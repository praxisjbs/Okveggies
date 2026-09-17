<?php
/**
 * OK Veggies M12 content-page domain service.
 *
 * This class owns the fixed page registry, draft and published projections,
 * validation, optimistic locking, publishing and append-only audit history.
 * It deliberately does not render Markdown or enforce route permissions. The
 * controller must enforce content.view/content.edit, POST and CSRF before it
 * calls a write method.
 */
final class ContentPages
{
    public const AUDIT_ENTITY = 'content_page';
    public const ACTION_DRAFT = 'content_pages.draft.update';
    public const ACTION_IMAGE = 'content_pages.image.update';
    public const ACTION_PUBLISH = 'content_pages.publish';
    public const ACTION_UNPUBLISH = 'content_pages.unpublish';

    public const TITLE_MAX = 200;
    public const BODY_MAX = 100000;
    public const META_TITLE_MAX = 70;
    public const META_DESCRIPTION_MAX = 160;
    public const FAQ_MAX = 50;
    public const FAQ_QUESTION_MAX = 200;
    public const FAQ_TOKENS = [
        'deposit_percentage',
        'household_delivery_schedule',
        'business_delivery_schedule',
        'cancellation_policy',
        'make_it_right_window',
        'kitchen_run_quote_window',
        'support_whatsapp_number',
        'support_email',
    ];

    private const PAGES = [
        'home' => ['label' => 'Homepage', 'path' => '/', 'legal' => false],
        'about' => ['label' => 'Our Story', 'path' => '/our-story', 'legal' => false],
        'how-it-works' => ['label' => 'How It Works', 'path' => '/how-it-works', 'legal' => false],
        'faq' => ['label' => 'FAQ', 'path' => '/faq', 'legal' => false],
        'terms' => ['label' => 'Terms', 'path' => '/terms', 'legal' => true],
        'privacy' => ['label' => 'Privacy', 'path' => '/privacy', 'legal' => true],
        'delivery-policy' => ['label' => 'Delivery Policy', 'path' => '/delivery-policy', 'legal' => true],
    ];

    private const HOME_FIELDS = [
        'hero_eyebrow' => 60,
        'hero_heading' => 160,
        'hero_intro' => 500,
        'primary_cta_label' => 60,
        'primary_cta_path' => 255,
        'secondary_cta_label' => 60,
        'secondary_cta_path' => 255,
        'promise_heading' => 160,
        'promise_body' => 10000,
        'combos_eyebrow' => 60,
        'combos_heading' => 160,
        'categories_eyebrow' => 60,
        'categories_heading' => 160,
        'products_eyebrow' => 60,
        'products_heading' => 160,
    ];

    private const CTA_PATHS = [
        '/', '/shop.php', '/combos.php', '/kitchen-runs.php', '/contact.php', '/account.php',
    ];

    private const SELECT_COLUMNS =
        'id, slug, title, body, draft_title, draft_body, '
        . 'draft_meta_title, draft_meta_description, meta_title, meta_description, '
        . 'draft_content_data, content_data, draft_image_url, draft_image_alt, '
        . 'image_url, image_alt, is_published, published_at, published_by, '
        . 'updated_by, created_at, updated_at';

    /** Fixed registry in admin display order. */
    public static function registry(): array
    {
        return self::PAGES;
    }

    public static function supportedSlugs(): array
    {
        return array_keys(self::PAGES);
    }

    public static function isSupportedSlug(string $slug): bool
    {
        return isset(self::PAGES[$slug]);
    }

    public static function canonicalPath(string $slug): ?string
    {
        return self::PAGES[$slug]['path'] ?? null;
    }

    /** Homepage field names and their maximum lengths, for neutral admin forms. */
    public static function homeFields(): array
    {
        return self::HOME_FIELDS;
    }

    /** List only fixed, seeded pages. Missing fixed rows simply do not appear. */
    public static function listForAdmin(): array
    {
        $params = [];
        $marks = [];
        foreach (self::supportedSlugs() as $index => $slug) {
            $key = ':slug_' . $index;
            $marks[] = $key;
            $params[$key] = $slug;
        }
        $rows = Database::all(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM content_pages WHERE slug IN (' . implode(', ', $marks) . ')',
            $params
        );
        $staff = self::staffNames($rows);
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string) $row['slug']] = self::adminProjection($row, $staff);
        }
        $ordered = [];
        foreach (self::supportedSlugs() as $slug) {
            if (isset($bySlug[$slug])) {
                $ordered[] = $bySlug[$slug];
            }
        }
        return $ordered;
    }

    /** Full presentation-neutral admin data. Route-level content.view is required. */
    public static function findForAdmin(string $slug): ?array
    {
        $row = self::findStored($slug, false);
        return $row === null ? null : self::adminProjection($row, self::staffNames([$row]));
    }

    /** Published snapshot only. Unknown and unpublished slugs are indistinguishable. */
    public static function findPublished(string $slug): ?array
    {
        if (!self::isSupportedSlug($slug)) {
            return null;
        }
        $row = Database::one(
            'SELECT id, slug, title, body, meta_title, meta_description, content_data, '
            . 'image_url, image_alt, published_at '
            . 'FROM content_pages WHERE slug = :slug AND is_published = :published LIMIT 1',
            [':slug' => $slug, ':published' => 1]
        );
        return $row === null ? null : self::publishedProjection($row);
    }

    /** Published footer destinations only, in fixed registry order. */
    public static function publishedNavigation(array $slugs): array
    {
        $wanted = [];
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            if ($slug !== 'home' && self::isSupportedSlug($slug)) {
                $wanted[$slug] = true;
            }
        }
        if (!$wanted) {
            return [];
        }
        $params = [':published' => 1];
        $marks = [];
        foreach (array_keys($wanted) as $index => $slug) {
            $key = ':nav_slug_' . $index;
            $marks[] = $key;
            $params[$key] = $slug;
        }
        $rows = Database::all(
            'SELECT slug FROM content_pages WHERE is_published = :published AND slug IN (' . implode(', ', $marks) . ')',
            $params
        );
        $published = [];
        foreach ($rows as $row) {
            $published[(string) $row['slug']] = true;
        }
        $navigation = [];
        foreach (self::supportedSlugs() as $slug) {
            if (isset($wanted[$slug], $published[$slug])) {
                $navigation[] = [
                    'slug' => $slug,
                    'label' => self::PAGES[$slug]['label'],
                    'path' => self::PAGES[$slug]['path'],
                    'legal' => self::PAGES[$slug]['legal'],
                ];
            }
        }
        return $navigation;
    }

    /** Published managed pages for sitemap generation, never drafts. */
    public static function publishedForSitemap(): array
    {
        $slugs = array_values(array_filter(
            self::supportedSlugs(),
            static fn(string $slug): bool => $slug !== 'home'
        ));
        $params = [':published' => 1];
        $marks = [];
        foreach ($slugs as $index => $slug) {
            $key = ':sitemap_slug_' . $index;
            $marks[] = $key;
            $params[$key] = $slug;
        }
        $rows = Database::all(
            'SELECT slug, published_at FROM content_pages '
            . 'WHERE is_published = :published AND slug IN (' . implode(', ', $marks) . ')',
            $params
        );
        $published = [];
        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if (isset(self::PAGES[$slug])) {
                $published[$slug] = $row['published_at'] ?? null;
            }
        }
        $pages = [];
        foreach ($slugs as $slug) {
            if (!array_key_exists($slug, $published)) {
                continue;
            }
            $pages[] = [
                'slug' => $slug,
                'path' => self::PAGES[$slug]['path'],
                'lastmod' => self::dateOnly($published[$slug]),
            ];
        }
        return $pages;
    }

    private static function dateOnly(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    /** Current draft only. The caller must enforce content.view on every request. */
    public static function findPreview(string $slug): ?array
    {
        $row = self::findStored($slug, false);
        return $row === null ? null : self::draftProjection($row);
    }

    /**
     * Pure draft validation. Draft saves may be incomplete; publication may not.
     * Unknown homepage keys are discarded rather than persisted.
     */
    public static function validateDraft(string $slug, array $input, bool $forPublish = false): array
    {
        if (!self::isSupportedSlug($slug)) {
            return ['ok' => false, 'code' => 'unknown_page', 'clean' => [], 'errors' => ['slug' => 'That page is not managed here.']];
        }

        $title = self::normaliseText($input['title'] ?? '');
        $body = self::normaliseText($input['body'] ?? '');
        $metaTitle = self::normaliseLine($input['meta_title'] ?? '');
        $metaDescription = self::normaliseLine($input['meta_description'] ?? '');
        $errors = [];

        self::validateText($title, 'title', self::TITLE_MAX, $errors, false);
        self::validateText($body, 'body', self::BODY_MAX, $errors, true);
        self::validateText($metaTitle, 'meta_title', self::META_TITLE_MAX, $errors, false);
        self::validateText($metaDescription, 'meta_description', self::META_DESCRIPTION_MAX, $errors, false);

        if ($forPublish && $title === '') {
            $errors['title'] = 'A visible page title is required before publishing.';
        }
        if ($forPublish && $body === '') {
            $errors['body'] = 'Page copy is required before publishing.';
        }

        $contentData = [];
        if ($slug === 'home') {
            $rawData = $input['content_data'] ?? [];
            if (!is_array($rawData)) {
                $rawData = [];
                $errors['content_data'] = 'Homepage fields must be submitted as a field set.';
            }
            foreach (self::HOME_FIELDS as $field => $max) {
                $value = str_ends_with($field, '_path')
                    ? self::normaliseLine($rawData[$field] ?? '')
                    : self::normaliseText($rawData[$field] ?? '');
                $contentData[$field] = $value;
                self::validateText($value, 'content_data.' . $field, $max, $errors, $field === 'promise_body');
                if ($forPublish && $value === '') {
                    $errors['content_data.' . $field] = 'This homepage field is required before publishing.';
                }
                if (str_ends_with($field, '_path') && $value !== '' && !in_array($value, self::CTA_PATHS, true)) {
                    $errors['content_data.' . $field] = 'Choose an approved storefront destination.';
                }
            }
        }

        if ($slug === 'faq' && $forPublish) {
            $faq = self::validateFaq($body);
            if (!$faq['ok']) {
                foreach ($faq['errors'] as $key => $message) {
                    $errors['body.' . $key] = $message;
                }
            }
        }

        $clean = [
            'title' => $title,
            'body' => $body,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'content_data' => $contentData,
        ];
        return [
            'ok' => !$errors,
            'code' => $errors ? 'validation_failed' : 'ok',
            'clean' => $clean,
            'errors' => $errors,
        ];
    }

    /** Parse and validate the agreed ## Question FAQ structure. */
    public static function validateFaq(string $body): array
    {
        $body = self::normaliseText($body);
        $errors = [];
        $items = [];
        $current = null;
        $answer = [];

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/u', $line, $match)) {
                if ($current !== null) {
                    self::finishFaqItem($current, $answer, $items, $errors);
                }
                $current = trim($match[1]);
                $answer = [];
                continue;
            }
            if ($current === null) {
                if (trim($line) !== '') {
                    $errors['preamble'] = 'FAQ copy must begin with a level-2 question.';
                }
                continue;
            }
            $answer[] = $line;
        }
        if ($current !== null) {
            self::finishFaqItem($current, $answer, $items, $errors);
        }
        if (!$items && !$errors) {
            $errors['empty'] = 'Add at least one FAQ question and answer.';
        }
        if (count($items) > self::FAQ_MAX) {
            $errors['count'] = 'FAQ can contain no more than ' . self::FAQ_MAX . ' questions.';
        }
        $seen = [];
        foreach ($items as $index => $item) {
            $normalised = self::normaliseFaqQuestion((string) $item['question']);
            if ($normalised !== '' && isset($seen[$normalised])) {
                $errors['duplicate_' . ($index + 1)] = 'Question ' . ($index + 1)
                    . ' repeats question ' . $seen[$normalised] . '.';
            } elseif ($normalised !== '') {
                $seen[$normalised] = $index + 1;
            }
            if (preg_match_all('/\{\{([a-z0-9_]+)\}\}/u', (string) $item['answer'], $tokens)) {
                foreach (array_unique($tokens[1]) as $token) {
                    if (!in_array($token, self::FAQ_TOKENS, true)) {
                        $errors['token_' . ($index + 1)] = 'Question ' . ($index + 1)
                            . ' uses an unsupported operational token: {{' . $token . '}}.';
                    }
                }
            }
            $withoutKnown = preg_replace('/\{\{[a-z0-9_]+\}\}/u', '', (string) $item['answer']) ?? '';
            if (str_contains($withoutKnown, '{{') || str_contains($withoutKnown, '}}')) {
                $errors['token_' . ($index + 1)] = 'Question ' . ($index + 1)
                    . ' contains an incomplete operational token.';
            }
        }
        return ['ok' => !$errors, 'code' => $errors ? 'invalid_faq' : 'ok', 'items' => $items, 'errors' => $errors];
    }

    /** Stable SHA-256 token over only the editable draft snapshot. */
    public static function draftFingerprint(array $page): string
    {
        $data = self::decodeData($page['draft_content_data'] ?? $page['content_data'] ?? []);
        $data = self::orderedData($data);
        $snapshot = [
            'title' => self::normaliseText($page['draft_title'] ?? $page['title'] ?? ''),
            'body' => self::normaliseText($page['draft_body'] ?? $page['body'] ?? ''),
            'meta_title' => self::normaliseLine($page['draft_meta_title'] ?? $page['meta_title'] ?? ''),
            'meta_description' => self::normaliseLine($page['draft_meta_description'] ?? $page['meta_description'] ?? ''),
            'content_data' => $data,
            'image_url' => (string) ($page['draft_image_url'] ?? $page['image_url'] ?? ''),
            'image_alt' => (string) ($page['draft_image_alt'] ?? $page['image_alt'] ?? ''),
        ];
        return hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function updateDraft(string $slug, array $input, string $expectedFingerprint, int $actorId): array
    {
        $validation = self::validateDraft($slug, $input, false);
        if (!$validation['ok']) {
            return $validation;
        }
        return self::mutate($slug, $expectedFingerprint, $actorId, function (array $row) use ($validation, $actorId): array {
            $clean = $validation['clean'];
            $newData = $row['slug'] === 'home' ? $clean['content_data'] : [];
            $before = self::draftSnapshot($row);
            $after = $before;
            $after['title'] = $clean['title'];
            $after['body'] = $clean['body'];
            $after['meta_title'] = $clean['meta_title'];
            $after['meta_description'] = $clean['meta_description'];
            $after['content_data'] = $newData;
            if ($before === $after) {
                return ['ok' => true, 'code' => 'unchanged', 'changed' => false, 'page' => self::adminProjection($row)];
            }
            Database::run(
                'UPDATE content_pages SET draft_title = :title, draft_body = :body, '
                . 'draft_meta_title = :meta_title, draft_meta_description = :meta_description, '
                . 'draft_content_data = :content_data, updated_by = :actor WHERE id = :id',
                [
                    ':title' => $clean['title'], ':body' => $clean['body'],
                    ':meta_title' => self::nullIfEmpty($clean['meta_title']),
                    ':meta_description' => self::nullIfEmpty($clean['meta_description']),
                    ':content_data' => $row['slug'] === 'home' ? self::encodeData($newData) : null,
                    ':actor' => $actorId, ':id' => (int) $row['id'],
                ]
            );
            Audit::record(self::ACTION_DRAFT, self::AUDIT_ENTITY, (int) $row['id'], $before, $after, $actorId);
            $fresh = self::findStored((string) $row['slug'], true);
            return ['ok' => true, 'code' => 'updated', 'changed' => true, 'page' => self::adminProjection($fresh)];
        });
    }

    public static function updateDraftImage(
        string $slug,
        string $path,
        string $alt,
        string $expectedFingerprint,
        int $actorId
    ): array {
        if (!self::isSupportedSlug($slug)) {
            return self::failure('unknown_page', ['slug' => 'That page is not managed here.']);
        }
        $path = self::normaliseLine($path);
        $alt = self::normaliseLine($alt);
        $errors = self::imageErrors($path, $alt);
        if ($errors) {
            return self::failure('validation_failed', $errors);
        }
        return self::mutate($slug, $expectedFingerprint, $actorId, function (array $row) use ($path, $alt, $actorId): array {
            $before = ['image_url' => (string) ($row['draft_image_url'] ?? ''), 'image_alt' => (string) ($row['draft_image_alt'] ?? '')];
            $after = ['image_url' => $path, 'image_alt' => $path === '' ? '' : $alt];
            if ($before === $after) {
                return ['ok' => true, 'code' => 'unchanged', 'changed' => false, 'page' => self::adminProjection($row)];
            }
            Database::run(
                'UPDATE content_pages SET draft_image_url = :url, draft_image_alt = :alt, updated_by = :actor WHERE id = :id',
                [':url' => self::nullIfEmpty($after['image_url']), ':alt' => self::nullIfEmpty($after['image_alt']), ':actor' => $actorId, ':id' => (int) $row['id']]
            );
            Audit::record(self::ACTION_IMAGE, self::AUDIT_ENTITY, (int) $row['id'], $before, $after, $actorId);
            $fresh = self::findStored((string) $row['slug'], true);
            return ['ok' => true, 'code' => 'updated', 'changed' => true, 'page' => self::adminProjection($fresh)];
        });
    }

    public static function publish(
        string $slug,
        string $expectedFingerprint,
        int $actorId,
        bool $legalApproved = false
    ): array {
        if (!self::isSupportedSlug($slug)) {
            return self::failure('unknown_page', ['slug' => 'That page is not managed here.']);
        }
        if (self::PAGES[$slug]['legal'] && !$legalApproved) {
            return self::failure('legal_approval_required', ['legal_approved' => 'Confirm that the client-approved legal wording has been supplied.']);
        }
        return self::mutate($slug, $expectedFingerprint, $actorId, function (array $row) use ($actorId, $legalApproved): array {
            $input = [
                'title' => $row['draft_title'], 'body' => $row['draft_body'],
                'meta_title' => $row['draft_meta_title'],
                'meta_description' => $row['draft_meta_description'],
                'content_data' => self::decodeData($row['draft_content_data']),
            ];
            $validation = self::validateDraft((string) $row['slug'], $input, true);
            $imageErrors = self::imageErrors(
                (string) ($row['draft_image_url'] ?? ''),
                (string) ($row['draft_image_alt'] ?? '')
            );
            if ($imageErrors) {
                $validation['ok'] = false;
                $validation['code'] = 'validation_failed';
                $validation['errors'] = array_merge($validation['errors'], $imageErrors);
            }
            if (!$validation['ok']) {
                return $validation;
            }
            $before = self::publishedSnapshot($row);
            $after = self::draftSnapshot($row);
            $after['is_published'] = true;
            if ((bool) $row['is_published'] && self::publishedMatchesDraft($row)) {
                return ['ok' => true, 'code' => 'unchanged', 'changed' => false, 'page' => self::adminProjection($row)];
            }
            Database::run(
                'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, '
                . 'meta_description = :meta_description, content_data = :content_data, '
                . 'image_url = :image_url, image_alt = :image_alt, is_published = :published, '
                . 'published_at = CURRENT_TIMESTAMP, published_by = :actor, updated_at = updated_at WHERE id = :id',
                [
                    ':title' => $row['draft_title'], ':body' => $row['draft_body'],
                    ':meta_title' => $row['draft_meta_title'], ':meta_description' => $row['draft_meta_description'],
                    ':content_data' => $row['draft_content_data'], ':image_url' => $row['draft_image_url'],
                    ':image_alt' => $row['draft_image_alt'], ':published' => 1,
                    ':actor' => $actorId, ':id' => (int) $row['id'],
                ]
            );
            if (self::PAGES[(string) $row['slug']]['legal']) {
                $after['legal_approval_confirmed'] = $legalApproved;
            }
            Audit::record(self::ACTION_PUBLISH, self::AUDIT_ENTITY, (int) $row['id'], $before, $after, $actorId);
            $fresh = self::findStored((string) $row['slug'], true);
            return ['ok' => true, 'code' => 'published', 'changed' => true, 'page' => self::adminProjection($fresh)];
        });
    }

    public static function unpublish(string $slug, int $actorId, ?string $expectedFingerprint = null): array
    {
        if (!self::isSupportedSlug($slug)) {
            return self::failure('unknown_page', ['slug' => 'That page is not managed here.']);
        }
        return self::mutate($slug, $expectedFingerprint, $actorId, function (array $row) use ($actorId): array {
            if (!(bool) $row['is_published']) {
                return ['ok' => true, 'code' => 'unchanged', 'changed' => false, 'page' => self::adminProjection($row)];
            }
            $before = self::publishedSnapshot($row);
            Database::run(
                'UPDATE content_pages SET is_published = :published, updated_at = updated_at WHERE id = :id',
                [':published' => 0, ':id' => (int) $row['id']]
            );
            $after = $before;
            $after['is_published'] = false;
            Audit::record(self::ACTION_UNPUBLISH, self::AUDIT_ENTITY, (int) $row['id'], $before, $after, $actorId);
            $fresh = self::findStored((string) $row['slug'], true);
            return ['ok' => true, 'code' => 'unpublished', 'changed' => true, 'page' => self::adminProjection($fresh)];
        });
    }

    /** Read-only history for one fixed page. Route-level content.view is required. */
    public static function history(string $slug, int $limit = 20): array
    {
        if (!self::isSupportedSlug($slug)) {
            return [];
        }
        $page = Database::one('SELECT id FROM content_pages WHERE slug = :slug LIMIT 1', [':slug' => $slug]);
        if ($page === null) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.action, a.old_values, a.new_values, a.created_at, '
            . 'TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS actor_name '
            . 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id '
            . 'WHERE a.entity_type = :entity AND a.entity_id = :page_id '
            . 'ORDER BY a.created_at DESC, a.id DESC LIMIT :row_limit'
        );
        $stmt->bindValue(':entity', self::AUDIT_ENTITY, PDO::PARAM_STR);
        $stmt->bindValue(':page_id', (int) $page['id'], PDO::PARAM_INT);
        $stmt->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['old_values'] = self::nullableJsonObject($row['old_values'] ?? null);
            $row['new_values'] = self::nullableJsonObject($row['new_values'] ?? null);
        }
        unset($row);
        return $rows;
    }

    private static function mutate(string $slug, ?string $expectedFingerprint, int $actorId, callable $change): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $row = self::findStored($slug, true);
            if ($row === null) {
                $pdo->rollBack();
                return self::failure('page_not_seeded', ['slug' => 'That managed page has not been seeded.']);
            }
            if (!self::activeStaffExists($actorId)) {
                $pdo->rollBack();
                return self::failure('invalid_actor', ['actor' => 'An active staff account is required.']);
            }
            if ($expectedFingerprint !== null && !hash_equals(self::draftFingerprint($row), $expectedFingerprint)) {
                $pdo->rollBack();
                return self::failure('stale_draft', ['fingerprint' => 'This draft changed after it was opened. Reload it before saving.']);
            }
            $result = $change($row);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function findStored(string $slug, bool $forUpdate): ?array
    {
        if (!self::isSupportedSlug($slug)) {
            return null;
        }
        return Database::one(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM content_pages WHERE slug = :slug LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [':slug' => $slug]
        );
    }

    private static function activeStaffExists(int $actorId): bool
    {
        if ($actorId < 1) {
            return false;
        }
        return Database::one(
            'SELECT id FROM users WHERE id = :id AND user_type = :type AND status = :status LIMIT 1',
            [':id' => $actorId, ':type' => 'staff', ':status' => 'active']
        ) !== null;
    }

    private static function adminProjection(array $row, array $staffNames = []): array
    {
        $draft = self::draftProjection($row);
        return $draft + [
            'id' => (int) $row['id'],
            'label' => self::PAGES[(string) $row['slug']]['label'],
            'legal' => self::PAGES[(string) $row['slug']]['legal'],
            'is_published' => (bool) $row['is_published'],
            'published_at' => self::nullableString($row['published_at'] ?? null),
            'published_by' => isset($row['published_by']) ? (int) $row['published_by'] : null,
            'published_by_name' => isset($row['published_by']) ? ($staffNames[(int) $row['published_by']] ?? null) : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_by_name' => isset($row['updated_by']) ? ($staffNames[(int) $row['updated_by']] ?? null) : null,
            'published' => self::publishedProjection($row),
        ];
    }

    /** Resolve staff display names without exposing account data to callers. */
    private static function staffNames(array $pages): array
    {
        $ids = [];
        foreach ($pages as $page) {
            foreach (['updated_by', 'published_by'] as $column) {
                $id = (int) ($page[$column] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if (!$ids) {
            return [];
        }
        $params = [];
        $marks = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':staff_' . $index;
            $marks[] = $key;
            $params[$key] = $id;
        }
        $rows = Database::all(
            'SELECT id, first_name, last_name FROM users WHERE id IN (' . implode(', ', $marks) . ')',
            $params
        );
        $names = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            $names[(int) $row['id']] = $name !== '' ? $name : 'Staff member';
        }
        return $names;
    }

    private static function draftProjection(array $row): array
    {
        $projection = [
            'slug' => (string) $row['slug'],
            'canonical_path' => self::canonicalPath((string) $row['slug']),
            'title' => (string) ($row['draft_title'] ?? ''),
            'body' => (string) ($row['draft_body'] ?? ''),
            'meta_title' => (string) ($row['draft_meta_title'] ?? ''),
            'meta_description' => (string) ($row['draft_meta_description'] ?? ''),
            'content_data' => self::decodeData($row['draft_content_data'] ?? null),
            'image_url' => (string) ($row['draft_image_url'] ?? ''),
            'image_alt' => (string) ($row['draft_image_alt'] ?? ''),
        ];
        $projection['fingerprint'] = self::draftFingerprint($row);
        return $projection;
    }

    private static function publishedProjection(array $row): array
    {
        return [
            'slug' => (string) $row['slug'],
            'canonical_path' => self::canonicalPath((string) $row['slug']),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'meta_title' => (string) ($row['meta_title'] ?? ''),
            'meta_description' => (string) ($row['meta_description'] ?? ''),
            'content_data' => self::decodeData($row['content_data'] ?? null),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'image_alt' => (string) ($row['image_alt'] ?? ''),
            'published_at' => self::nullableString($row['published_at'] ?? null),
        ];
    }

    private static function draftSnapshot(array $row): array
    {
        return [
            'title' => (string) ($row['draft_title'] ?? ''),
            'body' => (string) ($row['draft_body'] ?? ''),
            'meta_title' => (string) ($row['draft_meta_title'] ?? ''),
            'meta_description' => (string) ($row['draft_meta_description'] ?? ''),
            'content_data' => self::decodeData($row['draft_content_data'] ?? null),
            'image_url' => (string) ($row['draft_image_url'] ?? ''),
            'image_alt' => (string) ($row['draft_image_alt'] ?? ''),
        ];
    }

    private static function publishedSnapshot(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'meta_title' => (string) ($row['meta_title'] ?? ''),
            'meta_description' => (string) ($row['meta_description'] ?? ''),
            'content_data' => self::decodeData($row['content_data'] ?? null),
            'image_url' => (string) ($row['image_url'] ?? ''),
            'image_alt' => (string) ($row['image_alt'] ?? ''),
            'is_published' => (bool) ($row['is_published'] ?? false),
        ];
    }

    private static function publishedMatchesDraft(array $row): bool
    {
        $published = self::publishedSnapshot($row);
        unset($published['is_published']);
        return $published === self::draftSnapshot($row);
    }

    private static function validateText(string $value, string $field, int $max, array &$errors, bool $markdown): void
    {
        if (mb_strlen($value) > $max) {
            $errors[$field] = 'This field must be ' . $max . ' characters or fewer.';
            return;
        }
        self::rejectUnsafeText($value, $field, $errors);
        if (!$markdown || isset($errors[$field])) {
            return;
        }
        if (preg_match('/^#\s+/m', $value)) {
            $errors[$field] = 'Level-1 headings are reserved for the page title.';
        } elseif (preg_match('/^#{4,}\s+/m', $value)) {
            $errors[$field] = 'Only level-2 and level-3 headings are supported.';
        } elseif (preg_match('/!\[[^\]]*\]\s*\(/u', $value)) {
            $errors[$field] = 'Images cannot be embedded in page copy.';
        } elseif (!self::markdownLinksAreSafe($value)) {
            $errors[$field] = 'Links must use http, https or a safe site-relative path.';
        }
    }

    private static function imageErrors(string $path, string $alt): array
    {
        $errors = [];
        if ($path !== '' && !preg_match('#^/uploads/content/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$#i', $path)) {
            $errors['image_url'] = 'Choose a verified content image from the protected upload workflow.';
        }
        if ($path !== '' && $alt === '') {
            $errors['image_alt'] = 'Describe the documentary photograph before saving it.';
        }
        if (mb_strlen($path) > 500) {
            $errors['image_url'] = 'The stored image path is too long.';
        }
        if (mb_strlen($alt) > 255) {
            $errors['image_alt'] = 'Image description must be 255 characters or fewer.';
        }
        self::rejectUnsafeText($alt, 'image_alt', $errors);
        return $errors;
    }

    private static function rejectUnsafeText(string $value, string $field, array &$errors): void
    {
        if (preg_match('/<[!\/?a-z][^>]*>/iu', $value)) {
            $errors[$field] = 'HTML is not accepted. Use the supported text formatting instead.';
        } elseif (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $value)) {
            $errors[$field] = 'This field contains an unsupported control character.';
        } elseif (str_contains($value, "\u{2014}")) {
            $errors[$field] = 'Use a full stop, comma or colon instead of an em dash.';
        }
    }

    private static function markdownLinksAreSafe(string $value): bool
    {
        if (!preg_match_all('/(?<!!)\[[^\]]+\]\(([^\s)]+)(?:\s+["\'][^"\']*["\'])?\)/u', $value, $matches)) {
            return true;
        }
        foreach ($matches[1] as $destination) {
            $decoded = html_entity_decode((string) $destination, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[\x00-\x20\x7f]/', $decoded)) {
                return false;
            }
            if (str_starts_with($decoded, '/') && !str_starts_with($decoded, '//') && !str_contains($decoded, '..')) {
                continue;
            }
            $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return false;
            }
        }
        return true;
    }

    private static function finishFaqItem(string $question, array $answerLines, array &$items, array &$errors): void
    {
        $index = count($items) + 1;
        $answer = trim(implode("\n", $answerLines));
        if ($question === '') {
            $errors['question_' . $index] = 'Each FAQ question must have words after ##.';
        } elseif (mb_strlen($question) > self::FAQ_QUESTION_MAX) {
            $errors['question_' . $index] = 'FAQ questions must be ' . self::FAQ_QUESTION_MAX . ' characters or fewer.';
        }
        if ($answer === '') {
            $errors['answer_' . $index] = 'Each FAQ question needs an answer.';
        }
        $items[] = ['question' => $question, 'answer' => $answer];
    }

    /** Case, spacing and terminal question punctuation do not make a new FAQ. */
    private static function normaliseFaqQuestion(string $question): string
    {
        $question = mb_strtolower(trim($question));
        $question = (string) preg_replace('/\s+/u', ' ', $question);
        return trim($question, " \t\n\r\0\x0B?.!");
    }

    private static function normaliseText($value): string
    {
        if (!is_scalar($value) && $value !== null) {
            return '';
        }
        return trim(str_replace(["\r\n", "\r"], "\n", (string) ($value ?? '')));
    }

    private static function normaliseLine($value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::normaliseText($value)));
    }

    private static function decodeData($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function orderedData(array $data): array
    {
        $ordered = [];
        foreach (self::HOME_FIELDS as $key => $_max) {
            if (array_key_exists($key, $data)) {
                $ordered[$key] = (string) $data[$key];
            }
        }
        return $ordered;
    }

    private static function encodeData(array $data): string
    {
        return (string) json_encode(self::orderedData($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function nullableString($value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function nullableJsonObject($value): ?array
    {
        if ($value === null) {
            return null;
        }
        return self::decodeData($value);
    }

    private static function failure(string $code, array $errors): array
    {
        return ['ok' => false, 'code' => $code, 'errors' => $errors];
    }
}
