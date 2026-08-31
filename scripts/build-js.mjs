/**
 * scripts/build-js.mjs
 * OK Veggies. Minify the vanilla JS in assets/js/ into assets/js/okv.min.js.
 * No framework, no runtime dependency. Run with `npm run build:js`.
 */
import { build } from 'esbuild';
import { existsSync } from 'node:fs';

const sources = [
  'assets/js/okv.js',
  'assets/js/okv-rbac.js',
  'assets/js/auth.js',
  'assets/js/admin-users.js',
  'assets/js/account.js',
  'assets/js/catalogue.js',
  'assets/js/basket.js',
  'assets/js/admin-products.js',
  'assets/js/admin-pricing.js',
  'assets/js/admin-combos.js',
].filter(existsSync);

if (sources.length === 0) {
  console.log('[build-js] no source files yet, nothing to do.');
  process.exit(0);
}

await build({
  entryPoints: sources,
  bundle: false,
  minify: true,
  outdir: 'assets/js',
  outExtension: { '.js': '.min.js' },
  logLevel: 'info',
});

console.log('[build-js] minified:', sources.join(', '));
