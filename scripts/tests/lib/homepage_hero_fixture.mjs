/**
 * A browser fixture of the actual index.php hero markup and built assets.
 * This substitutes known template values only. It does not execute PHP or
 * prove CMS/database behaviour; homepage_http_test.php owns those assertions.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export function homepageHeroFixture(root) {
  const read = (file) => readFileSync(join(root, file), 'utf8');
  const home = read('index.php');
  const literal = (name) => {
    const value = home.match(new RegExp(`\\$${name} = '([^']*)';`))?.[1];
    if (value === undefined) { throw new Error(`Missing hero fixture literal: ${name}`); }
    return value;
  };
  const escape = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);
  const values = new Map([
    ['okv_e($heroHeading)', literal('heroHeading')],
    ['okv_e($heroIntro)', literal('heroIntro')],
    ['okv_e($heroSizes)', literal('heroSizes')],
    ['okv_e(okv_image_url($heroPath))', literal('defaultHeroPath')],
    ['okv_e($heroAlt)', literal('defaultHeroAlt')],
    ["okv_e($heroImage['srcset'])", [640, 960, 1280].map((w) => `/assets/img/hero/fresh-produce-${w}.webp ${w}w`).join(', ')],
    ["(int) $heroImage['width']", home.match(/\$defaultHeroImage = \[[\s\S]*?'width' => (\d+)/)?.[1]],
    ["(int) $heroImage['height']", home.match(/\$defaultHeroImage = \[[\s\S]*?'height' => (\d+)/)?.[1]],
    ['okv_e($tagline)', home.match(/Settings::str\('business_tagline', '([^']+)'\)/)?.[1]],
  ]);
  for (const field of ['hero_eyebrow', 'primary_cta_label', 'primary_cta_path', 'secondary_cta_label', 'secondary_cta_path']) {
    values.set(`okv_e($copy['${field}'])`, home.match(new RegExp(`'${field}' => '([^']+)'`))?.[1]);
  }
  const icons = read('includes/components/shop/icons.php');
  const render = (markup) => {
    let html = markup
      .replace(/<\?php if \(\$heroImage === null\): \?>[\s\S]*?<\?php endif; \?>/g, '')
      .replace(/<\?php (?:if \(\$heroImage !== null\):|endif;) \?>/g, '')
      .replace(/<\?php okv_seal\(120, 'flex-none', 'The OK Veggies seal'\); \?>/g,
        '<picture><source type="image/webp" srcset="/assets/img/brand/seal-320.webp"><img src="/assets/img/brand/seal-320.png" alt="The OK Veggies seal" width="120" height="120" class="flex-none" decoding="async"></picture>')
      .replace(/<\?php okv_icon\('([^']+)', '([^']+)'\); \?>/g, (_, name, classes) => {
        const paths = icons.match(new RegExp(`'${name}'\\s*=>\\s*'([^']+)'`))?.[1];
        if (!paths) { throw new Error(`Missing fixture icon: ${name}`); }
        return `<svg class="${escape(classes)}" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;
      })
      .replace(/<\?=\s*([\s\S]*?)\s*\?>/g, (_, expression) => {
        const asset = expression.match(/^okv_e\(okv_asset\('([^']+)'\)\)$/)?.[1];
        const value = asset ?? values.get(expression.trim());
        if (value === undefined) { throw new Error(`Unrecognised fixture expression: ${expression}`); }
        return escape(value);
      });
    if (html.includes('<?')) { throw new Error('Unresolved PHP in hero fixture'); }
    return html;
  };
  const hero = home.match(/<section class="okv-home-hero[\s\S]*?<\/section>/)?.[0];
  if (!hero) { throw new Error('Homepage hero markup not found'); }
  const preload = home.split('\n').find((line) => line.includes('<link rel="preload" as="image"'));
  const scripts = read('includes/components/shop/footer.php').split('\n')
    .filter((line) => /<script.*(?:vendor\/(?:gsap|ScrollTrigger)|okv-motion\.min\.js)/.test(line)).join('\n');
  const pending = read('includes/components/head_meta.php').match(/<script>document\.documentElement[\s\S]*?<\/script>/)?.[0];
  const links = [['/', 'Home'], ['/shop.php', 'Shop'], ['/combos.php', 'Combos'], ['/kitchen-runs.php', 'Kitchen Runs'], ['/cart.php', 'Basket'], ['/account.php', 'Account']];
  return `<!doctype html><html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Homepage hero template fixture, not a PHP runtime</title>
    <link rel="icon" href="/favicon.ico">
    ${render(preload)}
    <link rel="preload" as="font" type="font/woff2" crossorigin href="/assets/fonts/hanken-grotesk-latin.woff2">
    <link rel="stylesheet" href="/assets/css/tailwind.css">
  </head><body>${pending}
    <!-- Guest chrome stand-in only. The hero itself above is read from index.php. -->
    <header class="sticky top-0 z-30 border-b border-mist bg-white/95 backdrop-blur">
      <div class="okv-container flex h-16 items-center justify-between gap-4">
        <a href="/" class="inline-flex min-h-[44px] items-center" aria-label="OK Veggies, home">
          <img src="/assets/img/brand/lockup.svg" alt="OK Veggies, Fresh Picks" width="183" height="48" class="hidden h-12 w-auto sm:block">
          <img src="/assets/img/brand/lockup-compact.svg" alt="OK Veggies, Fresh Picks" width="172" height="36" class="h-9 w-auto sm:hidden">
        </a>
        <nav class="hidden items-center gap-6 text-sm font-semibold md:flex" aria-label="Main navigation"><a href="/shop.php" class="okv-btn-text">Shop</a><a href="/combos.php" class="okv-btn-text">Combos</a><a href="/kitchen-runs.php" class="okv-btn-outline px-4">Kitchen Runs</a></nav>
        <a href="/cart.php" class="okv-btn px-4">Basket</a>
      </div>
    </header>
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-6 border-t border-mist bg-white px-1 pb-2 md:hidden" aria-label="Mobile navigation">
      ${links.map(([href, label]) => `<a href="${href}" class="flex min-h-[56px] items-center justify-center text-center text-xs font-semibold">${label}</a>`).join('')}
    </nav>
    <main id="okv-main">${render(hero)}
      <section class="min-h-screen bg-forest-tint"><h2 class="okv-container py-16 font-editorial text-okv-h4">Below-hero scroll fixture</h2></section>
      <section class="min-h-screen"><h2 class="okv-container py-16 font-editorial text-okv-h4">End of fixture</h2></section>
    </main>${render(scripts)}</body></html>`;
}
