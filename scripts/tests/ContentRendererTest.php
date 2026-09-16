<?php
$sample = <<<'MD'
## Growing well

Fresh **produce** and _plain dealing_ with [shop links](/shop.php) and [farm notes](https://example.com/farm).

- One crate
- Two crates

1. Pick
2. Pack

> A clear promise.

### Growing well

<script>alert(1)</script> [unsafe](javascript:alert(1))

## Growing well
MD;
$rendered = ContentRenderer::render($sample);
okv_test_ok(str_contains($rendered['html'], '<strong>produce</strong>'), 'restricted Markdown renders strong text');
okv_test_ok(str_contains($rendered['html'], '<em>plain dealing</em>'), 'restricted Markdown renders emphasis');
okv_test_ok(str_contains($rendered['html'], '<ul ') && str_contains($rendered['html'], '<ol '), 'restricted Markdown renders both list types');
okv_test_ok(str_contains($rendered['html'], '<blockquote '), 'restricted Markdown renders a blockquote');
okv_test_ok(str_contains($rendered['html'], 'href="/shop.php"'), 'a safe local link is retained');
okv_test_ok(str_contains($rendered['html'], 'rel="external noopener noreferrer"'), 'an external link stays same-tab with a safe relationship');
okv_test_ok(!str_contains($rendered['html'], 'target="_blank"'), 'content links never force a new tab');
okv_test_ok(str_contains($rendered['html'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'raw HTML is escaped');
okv_test_ok(!str_contains($rendered['html'], 'href="javascript:'), 'an unsafe destination never becomes a link');
okv_test_eq('growing-well', $rendered['headings'][0]['id'], 'the first heading gets a deterministic anchor');
okv_test_eq('growing-well-2', $rendered['headings'][1]['id'], 'a duplicate subheading gets a deterministic suffix');
okv_test_eq('growing-well-3', $rendered['headings'][2]['id'], 'a later duplicate heading continues the suffix');
$plain = ContentRenderer::plainText('## Hello **fresh** [shop](/shop.php)', 200);
okv_test_eq('Hello fresh shop', $plain, 'metadata fallback removes Markdown without rendering HTML');

