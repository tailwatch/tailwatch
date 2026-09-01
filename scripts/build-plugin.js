#!/usr/bin/env node

/**
 * Assemble a clean, ship-only copy of the plugin in dist/tailwatch/.
 *
 * ALLOWLIST model: only the paths in SHIP_* are copied, so a dev/junk file can
 * never leak into a release. A forbidden-file check runs at the end as a second
 * safety net. Run AFTER `npm run build`.
 *
 *   npm run build && node scripts/build-plugin.js      (or: npm run dist)
 *
 * dist/tailwatch/ is then the exact folder shipped to GitHub + WordPress.org.
 */

const fs = require('fs');
const path = require('path');

const SLUG = 'tailwatch';
const OUT = path.join('dist', SLUG);

// The ONLY things that ship. Nothing else is ever copied.
const SHIP_FILES = ['tailwatch.php', 'tw_autoload.php', 'readme.txt', 'changelog.txt', 'license.txt'];
const SHIP_DIRS = ['Admin', 'Vendor', 'languages'];
// admin-app/src (the React source) is intentionally NOT shipped: the compiled bundle
// in Admin/View/Static/ runs the dashboard, and the GPL source + build tooling are
// published in the GitHub repo (documented in readme.txt's == Source Code == section)
// and in every GitHub release's auto-generated "Source code" archive. This
// satisfies the WordPress.org minified-code guideline.
const SHIP_SPECIAL = [];

// Belt-and-suspenders: none of these may exist anywhere inside dist/.
const FORBIDDEN = [
  /(^|\/)node_modules(\/|$)/i,
  /(^|\/)\.git(\/|$)/i,
  /(^|\/)\.claude(\/|$)/i,
  /(^|\/)\.cursor(\/|$)/i,
  /(^|\/)\.DS_Store$/i,
  /(^|\/)\.env(\.|$)/i,
  /(^|\/)_smoke-test(\/|$)/i,
  /(^|\/)composer\.(json|lock)$/i,
  /\.(log|bak|orig)$/i,
];

// Skipped inside copied directories (dev/test noise that must not ship).
const SKIP = [/\.test\.js$/i, /^setupTests\.js$/i, /^\.DS_Store$/i, /^Thumbs\.db$/i, /^\.gitignore$/i];
function shouldSkip(name) { return SKIP.some((re) => re.test(name)); }

function fail(msg) { console.error('build-plugin: ' + msg); process.exit(1); }

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    if (shouldSkip(entry.name)) continue;
    const s = path.join(src, entry.name);
    const d = path.join(dest, entry.name);
    if (entry.isDirectory()) copyDir(s, d);
    else if (entry.isFile()) fs.copyFileSync(s, d);
  }
}

function walk(dir, base, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p, base, out);
    else out.push(path.relative(base, p).split(path.sep).join('/'));
  }
  return out;
}

// 1) fresh dist/
fs.rmSync('dist', { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

// 2) copy the allowlist
for (const f of SHIP_FILES) {
  if (!fs.existsSync(f)) fail('missing shippable file: ' + f);
  fs.copyFileSync(f, path.join(OUT, f));
  console.log('  + ' + f);
}
for (const d of SHIP_DIRS) {
  if (!fs.existsSync(d)) fail('missing shippable directory: ' + d);
  copyDir(d, path.join(OUT, d));
  console.log('  + ' + d + '/');
}
for (const [from, to] of SHIP_SPECIAL) {
  if (!fs.existsSync(from)) fail('missing shippable path: ' + from);
  copyDir(from, path.join(OUT, to));
  console.log('  + ' + to + '/');
}

// 3) verify: nothing forbidden slipped in
const files = walk(OUT, OUT, []);
const bad = files.filter((rel) => FORBIDDEN.some((re) => re.test(rel)));
if (bad.length) fail('forbidden files found in dist/:\n  ' + bad.join('\n  '));

// 4) sanity: the compiled bundle must be present (run `npm run build` first)
if (!files.some((f) => /^Admin\/View\/Static\/js\/.*\.js$/i.test(f))) {
  fail('no compiled JS bundle in Admin/View/Static/js — run `npm run build` first');
}

console.log('\nbuild-plugin: ' + files.length + ' files assembled in ' + OUT + '/ (verified clean).');
