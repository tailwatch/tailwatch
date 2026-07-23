#!/usr/bin/env node
/**
 * Copies the compiled React bundle from admin-app/build/static/ into the paths
 * the PHP loader (Admin/View/Controller/InterfaceController.php) scans at
 * runtime, and fixes the bundled-asset URLs inside the CSS.
 *
 * Layout produced:
 *   Admin/View/Static/js/     <- build/static/js/*     (main + chunks)
 *   Admin/View/Static/css/    <- build/static/css/*    (custom.css preserved)
 *   Admin/View/Static/media/  <- build/static/media/*  (flag SVGs, fonts, ...)
 *
 * Create React App emits CSS asset references as `url(../../static/media/x.svg)`
 * (relative to build/static/css/). Because this script flattens the build into
 * sibling css/ + media/ folders, those references are rewritten to
 * `url(../media/x.svg)` so they resolve to Admin/View/Static/media/ on both
 * case-sensitive (Linux) and case-insensitive (Windows) filesystems. Without
 * this rewrite the flag SVGs (and any other bundled media) 404 at runtime.
 *
 * The hand-authored Admin/View/Static/images/ folder (logos referenced through
 * the wptw_ajax.asset_url localized variable) is intentionally left untouched.
 *
 * Invoked automatically by `npm run build` from the repo root.
 */

const fs = require('fs');
const path = require('path');

const REPO_ROOT  = path.resolve(__dirname, '..');
const BUILD_DIR  = path.join(REPO_ROOT, 'admin-app', 'build', 'static');
const DEST_JS    = path.join(REPO_ROOT, 'Admin', 'View', 'Static', 'js');
const DEST_CSS   = path.join(REPO_ROOT, 'Admin', 'View', 'Static', 'css');
const DEST_MEDIA = path.join(REPO_ROOT, 'Admin', 'View', 'Static', 'media');

const PRESERVE_CSS = new Set(['custom.css']);

function ensureDir(dir) {
	if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function wipeFiles(dir, preserveSet) {
	if (!fs.existsSync(dir)) return;
	for (const entry of fs.readdirSync(dir)) {
		if (preserveSet && preserveSet.has(entry)) continue;
		const full = path.join(dir, entry);
		if (fs.statSync(full).isFile()) fs.unlinkSync(full);
	}
}

function copyDir(src, dest) {
	if (!fs.existsSync(src)) return 0;
	ensureDir(dest);
	let count = 0;
	for (const entry of fs.readdirSync(src)) {
		// Skip source maps — they are debugging-only and add tens of MB to the
		// distributed bundle. The unminified source is maintained in this
		// repository, so maps are not needed in the plugin itself.
		if (entry.endsWith('.map')) continue;
		const s = path.join(src, entry);
		const d = path.join(dest, entry);
		const stat = fs.statSync(s);
		if (stat.isDirectory()) {
			count += copyDir(s, d);
		} else {
			fs.copyFileSync(s, d);
			count += 1;
		}
	}
	return count;
}

// Rewrite CRA's `../../static/media/` asset references (as emitted from
// build/static/css/) to `../media/`, so they resolve to Admin/View/Static/media/
// from Admin/View/Static/css/. Only real .css files are rewritten (not .map).
function rewriteCssMediaPaths(dir) {
	if (!fs.existsSync(dir)) return 0;
	let rewritten = 0;
	for (const entry of fs.readdirSync(dir)) {
		if (!entry.endsWith('.css')) continue;
		const full = path.join(dir, entry);
		if (!fs.statSync(full).isFile()) continue;
		const before = fs.readFileSync(full, 'utf8');
		const after = before.split('../../static/media/').join('../media/');
		if (after !== before) {
			fs.writeFileSync(full, after);
			rewritten += 1;
		}
	}
	return rewritten;
}

if (!fs.existsSync(BUILD_DIR)) {
	console.error('[copy-build] admin-app/build/static/ not found — run `npm run build` inside admin-app first.');
	process.exit(1);
}

ensureDir(DEST_JS);
ensureDir(DEST_CSS);
ensureDir(DEST_MEDIA);

// Wipe generated output. js/ and media/ are fully regenerated; css/ keeps the
// hand-authored custom.css. images/ is NOT touched (hand-authored logos).
wipeFiles(DEST_JS, null);
wipeFiles(DEST_CSS, PRESERVE_CSS);
wipeFiles(DEST_MEDIA, null);

const jsCount    = copyDir(path.join(BUILD_DIR, 'js'), DEST_JS);
const cssCount   = copyDir(path.join(BUILD_DIR, 'css'), DEST_CSS);
const mediaCount = copyDir(path.join(BUILD_DIR, 'media'), DEST_MEDIA);
const cssFixed   = rewriteCssMediaPaths(DEST_CSS);

console.log(`[copy-build] JS: ${jsCount} file(s) -> Admin/View/Static/js/`);
console.log(`[copy-build] CSS: ${cssCount} file(s) -> Admin/View/Static/css/ (custom.css preserved; ${cssFixed} rewritten)`);
console.log(`[copy-build] Media: ${mediaCount} file(s) -> Admin/View/Static/media/`);
console.log('[copy-build] Done.');
