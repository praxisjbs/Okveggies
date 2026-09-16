<?php
/** M12 ContentPages transactional behavior against a migrated MySQL 8 database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function cpdb_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}
function cpdb_eq($expected, $actual, string $label): void
{
    cpdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

$pdo = Database::getInstance()->getConnection();
$columns = ['id', 'slug', 'title', 'body', 'draft_title', 'draft_body', 'draft_meta_title',
    'draft_meta_description', 'meta_title', 'meta_description', 'draft_content_data', 'content_data',
    'draft_image_url', 'draft_image_alt', 'image_url', 'image_alt', 'is_published', 'published_at',
    'published_by', 'updated_by', 'created_at', 'updated_at'];
$before = [];
foreach (ContentPages::supportedSlugs() as $slug) {
    $row = Database::one('SELECT ' . implode(', ', $columns) . ' FROM content_pages WHERE slug = :slug', [':slug' => $slug]);
    if ($row !== null) {
        $before[$slug] = $row;
    }
}

$suffix = bin2hex(random_bytes(6));
$actorId = 0;
$inactiveId = 0;

try {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) '
        . 'VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Content', ':last' => 'Editor', ':email' => "content-$suffix@example.test",
         ':phone' => '+23470' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
         ':type' => 'staff', ':status' => 'active']
    );
    $actorId = (int) $pdo->lastInsertId();
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) '
        . 'VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Inactive', ':last' => 'Editor', ':email' => "inactive-content-$suffix@example.test",
         ':phone' => '+23471' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
         ':type' => 'staff', ':status' => 'disabled']
    );
    $inactiveId = (int) $pdo->lastInsertId();

    Database::run(
        'UPDATE content_pages SET title = :title, body = :body, draft_title = :draft_title, draft_body = :draft_body, '
        . 'draft_meta_title = NULL, draft_meta_description = NULL, meta_title = NULL, meta_description = NULL, '
        . 'draft_content_data = NULL, content_data = NULL, draft_image_url = NULL, draft_image_alt = NULL, '
        . 'image_url = NULL, image_alt = NULL, is_published = :published, published_at = NULL, published_by = NULL, updated_by = NULL '
        . 'WHERE slug = :slug',
        [':title' => 'Our Story Published', ':body' => 'Original public body.', ':draft_title' => 'Our Story Draft',
         ':draft_body' => "Original draft body.\n\nSecond line.", ':published' => 1, ':slug' => 'about']
    );
    Database::run(
        'UPDATE content_pages SET draft_title = :title, draft_body = :body, is_published = :published WHERE slug = :slug',
        [':title' => 'Terms test fixture', ':body' => 'Integration test fixture. Not legal copy.', ':published' => 0, ':slug' => 'terms']
    );
    Database::run(
        'UPDATE content_pages SET draft_title = :title, draft_body = :body, is_published = :published WHERE slug = :slug',
        [':title' => 'FAQ', ':body' => "## When?\nNow.", ':published' => 0, ':slug' => 'faq']
    );

    $list = ContentPages::listForAdmin();
    cpdb_eq(ContentPages::supportedSlugs(), array_column($list, 'slug'), 'admin list contains the seven fixed pages in registry order');
    cpdb_eq(null, ContentPages::findPublished('unknown-page'), 'an unknown public page is absent');
    cpdb_eq(null, ContentPages::findPreview('unknown-page'), 'an unknown preview page is absent');
    cpdb_eq(null, ContentPages::findPublished('faq'), 'an unpublished page is absent from public retrieval');
    cpdb_eq('FAQ', ContentPages::findPreview('faq')['title'], 'an authorised caller can retrieve an unpublished draft');
    cpdb_ok(!array_key_exists('draft_title', ContentPages::findPublished('about')), 'public projection contains no draft column');
    cpdb_ok(!array_key_exists('updated_by', ContentPages::findPublished('about')), 'public projection contains no internal actor');

    $privacyBefore = Database::one('SELECT * FROM content_pages WHERE slug = :slug', [':slug' => 'privacy']);
    $about = ContentPages::findForAdmin('about');
    $saved = ContentPages::updateDraft('about', [
        'title' => 'Our Story, edited',
        'body' => "A changed draft.\n\nLine breaks stay.",
        'meta_title' => 'Our farm story',
        'meta_description' => 'The people and farms behind OK Veggies.',
    ], $about['fingerprint'], $actorId);
    cpdb_eq('updated', $saved['code'], 'a valid draft update succeeds');
    cpdb_eq("A changed draft.\n\nLine breaks stay.", ContentPages::findPreview('about')['body'], 'draft storage preserves meaningful line breaks');
    cpdb_eq('Original public body.', ContentPages::findPublished('about')['body'], 'saving a draft leaves the public snapshot unchanged');
    cpdb_eq($actorId, ContentPages::findForAdmin('about')['updated_by'], 'draft mutation records the responsible staff member');
    cpdb_eq($privacyBefore, Database::one('SELECT * FROM content_pages WHERE slug = :slug', [':slug' => 'privacy']), 'updating Our Story does not modify Privacy');

    $audit = Database::one(
        'SELECT action, actor_user_id FROM audit_logs WHERE entity_type = :entity AND entity_id = :id ORDER BY id DESC LIMIT 1',
        [':entity' => ContentPages::AUDIT_ENTITY, ':id' => $about['id']]
    );
    cpdb_eq(ContentPages::ACTION_DRAFT, $audit['action'], 'draft update writes the distinct audit action');
    cpdb_eq($actorId, (int) $audit['actor_user_id'], 'draft audit identifies the responsible staff member');

    $afterSave = ContentPages::findForAdmin('about');
    $noChange = ContentPages::updateDraft('about', [
        'title' => $afterSave['title'], 'body' => $afterSave['body'],
        'meta_title' => $afterSave['meta_title'], 'meta_description' => $afterSave['meta_description'],
    ], $afterSave['fingerprint'], $actorId);
    cpdb_eq('unchanged', $noChange['code'], 'saving the same draft is a no-op');

    $bad = ContentPages::updateDraft('about', ['title' => 'Bad', 'body' => '<script>bad</script>'], $afterSave['fingerprint'], $actorId);
    cpdb_eq('validation_failed', $bad['code'], 'failed validation returns an expected result');
    cpdb_eq($afterSave['fingerprint'], ContentPages::findForAdmin('about')['fingerprint'], 'failed validation does not partially update the page');

    $stale = ContentPages::updateDraft('about', ['title' => 'Overwrite', 'body' => 'No.'], $about['fingerprint'], $actorId);
    cpdb_eq('stale_draft', $stale['code'], 'a stale draft fingerprint is refused');
    cpdb_eq('Our Story, edited', ContentPages::findPreview('about')['title'], 'a stale update does not overwrite newer work');

    $invalidActor = ContentPages::updateDraft('about', [
        'title' => $afterSave['title'], 'body' => 'Attempt by disabled staff.',
        'meta_title' => $afterSave['meta_title'], 'meta_description' => $afterSave['meta_description'],
    ], $afterSave['fingerprint'], $inactiveId);
    cpdb_eq('invalid_actor', $invalidActor['code'], 'a disabled staff account cannot mutate content');

    $badImage = ContentPages::updateDraftImage('about', '/uploads/content/picture.php', 'Farm', $afterSave['fingerprint'], $actorId);
    cpdb_eq('validation_failed', $badImage['code'], 'an executable image extension is refused');
    $image = ContentPages::updateDraftImage('about', '/uploads/content/farm-visit.webp', 'A grower showing the team fresh vegetables.', $afterSave['fingerprint'], $actorId);
    cpdb_eq('updated', $image['code'], 'a verified content image path and alt text can update the draft');
    cpdb_eq('/uploads/content/farm-visit.webp', ContentPages::findPreview('about')['image_url'], 'draft image path is stored separately');
    cpdb_eq('', ContentPages::findPublished('about')['image_url'], 'draft photography does not replace the published photograph');

    $publishReady = ContentPages::findForAdmin('about');
    $published = ContentPages::publish('about', $publishReady['fingerprint'], $actorId);
    cpdb_eq('published', $published['code'], 'a complete valid draft publishes');
    cpdb_eq("A changed draft.\n\nLine breaks stay.", ContentPages::findPublished('about')['body'], 'publish copies the current draft into the public snapshot');
    cpdb_eq('/uploads/content/farm-visit.webp', ContentPages::findPublished('about')['image_url'], 'publish copies the documentary image atomically');
    cpdb_eq($actorId, ContentPages::findForAdmin('about')['published_by'], 'publish records the responsible staff member');

    $terms = ContentPages::findForAdmin('terms');
    $legalRefused = ContentPages::publish('terms', $terms['fingerprint'], $actorId, false);
    cpdb_eq('legal_approval_required', $legalRefused['code'], 'legal content cannot publish without explicit client-copy attestation');
    cpdb_eq(null, ContentPages::findPublished('terms'), 'refused legal publication stays unavailable publicly');
    $legalPublished = ContentPages::publish('terms', $terms['fingerprint'], $actorId, true);
    cpdb_eq('published', $legalPublished['code'], 'attested legal test fixture can exercise publication');
    $legalAudit = Database::one(
        'SELECT new_values FROM audit_logs WHERE entity_type = :entity AND entity_id = :id AND action = :action ORDER BY id DESC LIMIT 1',
        [':entity' => ContentPages::AUDIT_ENTITY, ':id' => $terms['id'], ':action' => ContentPages::ACTION_PUBLISH]
    );
    cpdb_eq(true, json_decode($legalAudit['new_values'], true)['legal_approval_confirmed'], 'legal publish audit records the attestation');

    $faq = ContentPages::findForAdmin('faq');
    $faqBadSave = ContentPages::updateDraft('faq', ['title' => 'FAQ', 'body' => "## Empty answer?"], $faq['fingerprint'], $actorId);
    cpdb_eq('updated', $faqBadSave['code'], 'incomplete FAQ structure can remain in a working draft');
    cpdb_eq('validation_failed', ContentPages::publish('faq', $faqBadSave['page']['fingerprint'], $actorId)['code'], 'invalid FAQ structure cannot publish');
    $faqSaved = ContentPages::updateDraft('faq', ['title' => 'FAQ', 'body' => "## When do you deliver?\n\nOn the day selected."], $faqBadSave['page']['fingerprint'], $actorId);
    cpdb_eq('updated', $faqSaved['code'], 'valid structured FAQ data updates');
    cpdb_eq('published', ContentPages::publish('faq', $faqSaved['page']['fingerprint'], $actorId)['code'], 'valid FAQ data publishes');

    $history = ContentPages::history('about', 100);
    cpdb_ok(count($history) >= 3, 'page-scoped history returns the draft, image and publish events');
    cpdb_ok(is_array($history[0]['old_values']) && is_array($history[0]['new_values']), 'history returns decoded presentation-neutral snapshots');
    cpdb_ok(!in_array(ContentPages::ACTION_PUBLISH, array_column(ContentPages::history('privacy'), 'action'), true), 'one page history does not leak another page events');

    $unpublished = ContentPages::unpublish('about', $actorId);
    cpdb_eq('unpublished', $unpublished['code'], 'a published page can be unpublished');
    cpdb_eq(null, ContentPages::findPublished('about'), 'unpublishing immediately removes public retrieval');
    cpdb_eq('Our Story, edited', ContentPages::findPreview('about')['title'], 'unpublishing retains the current draft for authorised preview');
    cpdb_eq('unchanged', ContentPages::unpublish('about', $actorId)['code'], 'unpublishing an already unpublished page is idempotent');
} finally {
    if ($actorId > 0) {
        Database::run('DELETE FROM audit_logs WHERE entity_type = :entity AND actor_user_id = :actor', [':entity' => ContentPages::AUDIT_ENTITY, ':actor' => $actorId]);
    }
    foreach ($before as $row) {
        Database::run(
            'UPDATE content_pages SET title = :title, body = :body, draft_title = :draft_title, draft_body = :draft_body, '
            . 'draft_meta_title = :draft_meta_title, draft_meta_description = :draft_meta_description, '
            . 'meta_title = :meta_title, meta_description = :meta_description, draft_content_data = :draft_content_data, '
            . 'content_data = :content_data, draft_image_url = :draft_image_url, draft_image_alt = :draft_image_alt, '
            . 'image_url = :image_url, image_alt = :image_alt, is_published = :is_published, published_at = :published_at, '
            . 'published_by = :published_by, updated_by = :updated_by, created_at = :created_at, updated_at = :updated_at WHERE id = :id',
            [':title' => $row['title'], ':body' => $row['body'], ':draft_title' => $row['draft_title'],
             ':draft_body' => $row['draft_body'], ':draft_meta_title' => $row['draft_meta_title'],
             ':draft_meta_description' => $row['draft_meta_description'], ':meta_title' => $row['meta_title'],
             ':meta_description' => $row['meta_description'], ':draft_content_data' => $row['draft_content_data'],
             ':content_data' => $row['content_data'], ':draft_image_url' => $row['draft_image_url'],
             ':draft_image_alt' => $row['draft_image_alt'], ':image_url' => $row['image_url'], ':image_alt' => $row['image_alt'],
             ':is_published' => $row['is_published'], ':published_at' => $row['published_at'],
             ':published_by' => $row['published_by'], ':updated_by' => $row['updated_by'],
             ':created_at' => $row['created_at'], ':updated_at' => $row['updated_at'], ':id' => $row['id']]
        );
    }
    foreach ([$inactiveId, $actorId] as $userId) {
        if ($userId > 0) {
            Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
        }
    }
}

fwrite(STDOUT, "\n$passed / $tests content page database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
