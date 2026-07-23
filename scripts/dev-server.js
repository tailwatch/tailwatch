#!/usr/bin/env node
'use strict';
/**
 * dev-server.js — start the admin-app Create React App dev server, loading dev settings
 * from the plugin-root `.env` (BROWSER, WDS_SOCKET_*, HOST, PORT, …).
 *
 * `npm start` runs react-scripts with its working directory in `admin-app/`, so CRA would
 * not see a root `.env` on its own. This wrapper reads the root `.env` into the environment
 * first (no dependency), then hands off to the CRA dev server. Existing shell env vars win.
 */
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const root = path.resolve(__dirname, '..');
const envFile = path.join(root, '.env');

if (fs.existsSync(envFile)) {
  for (const line of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
    if (/^\s*(#|$)/.test(line)) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    if (key && process.env[key] === undefined) process.env[key] = val; // don't override the shell
  }
}

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
// shell: true is required on Windows/Node >=18.20 to spawn .cmd shims (npm.cmd);
// without it Node throws `spawn EINVAL` (CVE-2024-27980 hardening).
const child = spawn(npm, ['start', '--prefix', 'admin-app'], { stdio: 'inherit', env: process.env, shell: true });
child.on('exit', (code) => process.exit(code == null ? 0 : code));