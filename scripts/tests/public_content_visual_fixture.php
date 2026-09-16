<?php
/** Publish a temporary valid FAQ around the responsive browser pass. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$row = Database::one(
    'SELECT id, title, body, meta_title, meta_description, is_published FROM content_pages WHERE slug = :slug',
    [':slug' => 'faq']
);
if ($row === null) {
    fwrite(STDERR, "FAQ fixture row is missing.\n");
    exit(2);
}

$exit = 1;
try {
    Database::run(
        'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :description, is_published = :published WHERE id = :id',
        [':title' => 'Questions and Answers', ':body' => "## How do deposits work?\n\nThe current deposit is {{deposit_percentage}}.\n\n## How do I get help?\n\nUse the contact form or WhatsApp.",
         ':meta_title' => 'Questions answered', ':description' => 'Answers about shopping with OK Veggies.', ':published' => 1, ':id' => $row['id']]
    );
    $node = getenv('OKV_NODE') ?: 'node';
    $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/public_content_visual_test.mjs');
    passthru($command, $exit);
} finally {
    Database::run(
        'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :description, is_published = :published WHERE id = :id',
        [':title' => $row['title'], ':body' => $row['body'], ':meta_title' => $row['meta_title'], ':description' => $row['meta_description'],
         ':published' => $row['is_published'], ':id' => $row['id']]
    );
}
exit($exit);
