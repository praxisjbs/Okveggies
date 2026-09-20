/**
 * scripts/tests/lesser_text_test.mjs
 * -----------------------------------------------------------------------------
 * OK Veggies. PR6 attention-span gate that does not need PHP or a browser.
 * Static storefront copy must stay scannable: no paragraph over 40 words, and
 * empty-state headings stay at 5 words or fewer unless a named test pins them.
 *
 *   node scripts/tests/lesser_text_test.mjs
 * -----------------------------------------------------------------------------
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = [
  'index.php',
  'page.php',
  'shop.php',
  'product.php',
  'combo.php',
  'combos.php',
  'contact.php',
  'cart.php',
  'kitchen-runs.php',
  'includes/components/shop/kitchen_run_intro.php',
  'includes/components/shop/make_it_right_guidance.php',
  'includes/components/shop/how_it_works_steps.php',
  'includes/components/shop/delivery_policy_table.php',
  'includes/components/shop/empty_state.php',
  'includes/components/shop/faq_disclosures.php',
  'includes/components/shop/help_sheet.php',
  'includes/components/shop/shop_results.php',
];

const pinnedHeadings = new Set([
  'No featured combo is on the stall today',
  'No produce has been marked as a weekly pick',
  'The category list is being prepared',
  'We are preparing these answers',
  "This week's picks are temporarily unavailable",
]);

let checks = 0;
let passed = 0;
const log = [];

function ok(value, label, detail = '') {
  checks++;
  if (value) {
    passed++;
    return;
  }
  console.error(`  FAIL: ${label}${detail ? ` ${detail}` : ''}`);
}

function wordsIn(text) {
  const cleaned = text
    .replace(/<\?[\s\S]*?\?>/g, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/gi, ' ')
    .replace(/\\'/g, "'")
    .replace(/\s+/g, ' ')
    .trim();
  if (!cleaned) {
    return { count: 0, text: '' };
  }
  const parts = cleaned.split(/\s+/).filter(Boolean);
  return { count: parts.length, text: parts.join(' ') };
}

for (const relative of files) {
  const source = fs.readFileSync(path.join(root, relative), 'utf8');
  const paragraphs = [...source.matchAll(/<p\b[^>]*>([\s\S]*?)<\/p>/gi)];
  let longest = 0;
  for (const match of paragraphs) {
    const { count, text } = wordsIn(match[1]);
    if (count > longest) {
      longest = count;
    }
    ok(count <= 40, `${relative}: paragraph stays under 40 words`,
      count > 40 ? `(${count}) ${text.slice(0, 120)}` : '');
  }

  const headings = [...source.matchAll(/okv_empty_state\(\s*(?:[\s\S]*?),\s*(['"])([\s\S]*?)\1/g)];
  for (const match of headings) {
    const heading = match[2].replace(/\\'/g, "'");
    const count = heading.trim().split(/\s+/).filter(Boolean).length;
    if (pinnedHeadings.has(heading)) {
      ok(true, `${relative}: pinned empty heading kept (${count} words)`);
      continue;
    }
    ok(count <= 5, `${relative}: empty heading is 5 words or fewer`,
      `(${count}) ${heading}`);
  }

  log.push({ file: relative, paragraphs: paragraphs.length, longest });
}

const home = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
ok(home.includes('Documentary photograph pending') && home.includes('We will not replace it with stock photography'),
  'homepage keeps the honest Track 2 placeholder, never a stock photograph');
ok(home.includes("fetchpriority=\"high\"") && !home.includes('fetchpriority="high" loading="lazy"'),
  'homepage hero is high priority and never lazy-loaded');

console.log('\nWord-count log (static paragraphs):');
for (const row of log) {
  console.log(`  ${row.file}: ${row.paragraphs} paragraphs, longest ${row.longest} words`);
}

console.log(`\n${passed} / ${checks} lesser-text assertions passed.`);
process.exit(passed === checks ? 0 : 1);
