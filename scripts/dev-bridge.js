#!/usr/bin/env node
'use strict';
/**
 * dev-bridge.js — install / remove the local hot-reload bridge.
 *
 * Copies dev/tailwatch-dev.php into this WordPress install's mu-plugins/ folder so the
 * Create React App dev server (`npm start`) loads into the real wp-admin Tailwatch page
 * for React Fast Refresh. The plugin lives in wp-content/plugins/tailwatch, so mu-plugins
 * is a deterministic ../../mu-plugins — no configuration needed.
 *
 * Usage (via npm scripts):
 *   npm run dev:link      # copy the bridge in  -> then run `npm start`
 *   npm run dev:unlink    # remove the bridge when you're done
 */
const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.resolve(__dirname, '..');
const SRC = path.join(PLUGIN_ROOT, 'dev', 'tailwatch-dev.php');
// wp-content/plugins/tailwatch  ->  wp-content/mu-plugins
const MU_DIR = path.resolve(PLUGIN_ROOT, '..', '..', 'mu-plugins');
const DEST = path.join(MU_DIR, 'tailwatch-dev.php');

const mode = process.argv[2];

if (mode === 'install') {
  if (!fs.existsSync(SRC)) {
    console.error('\n[dev-bridge] ERROR: missing ' + SRC + '\n');
    process.exit(1);
  }
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SRC, DEST);
  console.log('[dev-bridge] linked -> ' + DEST);
  console.log('  Now run `npm start`, open wp-admin -> Tailwatch, and edit admin-app/src/.');
  console.log('  Run `npm run dev:unlink` when you stop the dev server.');
} else if (mode === 'remove') {
  if (fs.existsSync(DEST)) {
    fs.rmSync(DEST);
    console.log('[dev-bridge] unlinked -> ' + DEST);
  } else {
    console.log('[dev-bridge] nothing to remove (not found): ' + DEST);
  }
} else {
  console.error('usage: node scripts/dev-bridge.js <install|remove>');
  process.exit(1);
}
