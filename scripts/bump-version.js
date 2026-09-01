#!/usr/bin/env node

/**
 * Bump the Tailwatch version across every file that must agree with the
 * WordPress.org Stable Tag, and sync the changelog:
 *   - changelog.txt is the COMPLETE archive (newest first, and it INCLUDES the
 *     current version),
 *   - readme.txt shows ONLY the current version, mirrored from the top of
 *     changelog.txt, plus a pointer to the full changelog.
 * Author each new version at the TOP of changelog.txt, then run this script: it
 * mirrors that top entry into readme.txt. If you bump before writing the entry,
 * an empty "= <version> =" stub is added to the top of changelog.txt for you to
 * fill in (then re-run to mirror it across). Edits are surgical + byte-preserving,
 * so line endings (CRLF) and every other byte stay intact.
 *
 *   node scripts/bump-version.js 1.0.3
 *   node scripts/bump-version.js 1.0.3 --dry-run
 *   node scripts/bump-version.js 1.0.3 --no-changelog
 *
 * changelog.txt is the complete history; readme.txt mirrors the latest entry.
 */

const fs = require('fs');

const SEMVER = /^\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?$/;
const VERSION = '(\\d+\\.\\d+\\.\\d+(?:-[A-Za-z0-9.]+)?)';
const POINTER = 'See changelog.txt (included with the plugin) for the full changelog.';

const TARGETS = [
  { file: 'tailwatch.php',              re: new RegExp('(\\*\\s*Version:\\s*)' + VERSION) },
  { file: 'readme.txt',                 re: new RegExp('(Stable tag:\\s*)' + VERSION) },
  { file: 'Admin/Config/Constants.php', re: new RegExp("(TAILWATCH_VERSION',\\s*')" + VERSION) },
  { file: 'package.json',               re: new RegExp('("version":\\s*")' + VERSION) },
];

function fail(msg) {
  console.error('bump-version: ' + msg);
  process.exit(1);
}

// 'latin1' maps each byte 1:1 to a code point, so read + write is byte-preserving
// for both UTF-8 and Windows-1252 source; we only ever insert ASCII.
function readBytes(file) { return fs.readFileSync(file, 'latin1'); }
function writeBytes(file, content) { fs.writeFileSync(file, content, 'latin1'); }

// Keep changelog.txt as the complete archive (newest first, current version
// included) and mirror its top entry into readme.txt's Changelog section. If the
// target version is not yet at the top of changelog.txt, an empty stub is added
// there first, so readme.txt never shows a version that the archive is missing.
function rollChangelog(version, dryRun) {
  const readmeFile = 'readme.txt';
  const clFile = 'changelog.txt';
  const HEADER = '== Changelog ==';

  const readme = readBytes(readmeFile);
  const eol = readme.indexOf('\r\n') !== -1 ? '\r\n' : '\n';

  const hIdx = readme.indexOf(HEADER);
  if (hIdx === -1) { console.log('  ! readme.txt has no "== Changelog ==" section; skipped changelog'); return; }

  // 1) changelog.txt: make sure the target version is the top entry.
  let archive = fs.existsSync(clFile) ? readBytes(clFile) : (HEADER + eol + eol);
  let aHdr = archive.indexOf(HEADER);
  if (aHdr === -1) { archive = HEADER + eol + eol + archive; aHdr = 0; }
  const body = archive.slice(aHdr + HEADER.length).replace(/^(?:\r?\n)+/, '');
  const topVer = (body.match(/^= ([^ ]+) =/) || [])[1];

  const stubbed = topVer !== version;
  const newBody = stubbed
    ? '= ' + version + ' =' + eol + '* ' + eol + eol + body
    : body;
  const newArchive = HEADER + eol + eol + newBody.replace(/(?:\r?\n)+$/, '') + eol;

  // 2) extract the top (current) entry to mirror into readme.txt.
  const secondAt = newBody.search(/\r?\n= [^\r\n]+ =/);
  const topEntry = (secondAt === -1 ? newBody : newBody.slice(0, secondAt)).replace(/(?:\r?\n)+$/, '');

  // 3) readme.txt: replace the Changelog section with that entry + the pointer.
  const afterHeader = hIdx + HEADER.length;
  const nextSec = readme.slice(afterHeader).search(/\r?\n== [^\r\n]+ ==/);
  const secEnd = nextSec === -1 ? readme.length : afterHeader + nextSec;
  const newSection = HEADER + eol + eol + topEntry + eol + eol + POINTER + eol + eol;
  const newReadme = readme.slice(0, hIdx) + newSection + readme.slice(secEnd).replace(/^(?:\r?\n)+/, '');

  if (dryRun) {
    console.log('  ~ changelog: ' + (stubbed
      ? 'would add "= ' + version + ' =" stub to changelog.txt, then mirror it into readme.txt'
      : 'would mirror changelog.txt top entry (' + version + ') into readme.txt') + ' (dry run)');
    return;
  }
  writeBytes(clFile, newArchive);
  writeBytes(readmeFile, newReadme);
  if (stubbed) {
    console.log('  * changelog.txt: added "= ' + version + ' =" stub at the top - fill in the bullet(s) there, then re-run to mirror into readme.txt');
  } else {
    console.log('  * changelog.txt: "= ' + version + ' =" already at the top (complete archive kept)');
  }
  console.log('  * readme.txt: Changelog now mirrors changelog.txt top entry (' + version + ') + pointer');
}

// Keep readme.txt's Upgrade Notice to the current version only (it is a per-update
// prompt message, not history). On bump it resets to a fresh "= <version> =" ready
// for a short note; leave it minimal if the release does not need one.
function rollUpgradeNotice(version, dryRun) {
  const readmeFile = 'readme.txt';
  const readme = readBytes(readmeFile);
  const eol = readme.indexOf('\r\n') !== -1 ? '\r\n' : '\n';
  const HEADER = '== Upgrade Notice ==';

  const hIdx = readme.indexOf(HEADER);
  if (hIdx === -1) return; // no section; nothing to do

  const afterHeader = hIdx + HEADER.length;
  const nextSec = readme.slice(afterHeader).search(/\r?\n== [^\r\n]+ ==/);
  const secEnd = nextSec === -1 ? readme.length : afterHeader + nextSec;
  const section = readme.slice(afterHeader, secEnd);

  // Plain string check (no RegExp built from input): is there already a
  // "= <version> =" heading line in this section?
  const marker = '= ' + version + ' =';
  if (section.split(/\r?\n/).some((line) => line.startsWith(marker))) {
    console.log('  = readme.txt Upgrade Notice already shows ' + version);
    return;
  }
  const stub = eol + eol + '= ' + version + ' =' + eol + eol;
  const newReadme = readme.slice(0, afterHeader) + stub + readme.slice(secEnd).replace(/^(?:\r?\n)+/, '');
  if (dryRun) {
    console.log('  ~ readme.txt: would reset Upgrade Notice to "= ' + version + ' =" (dry run)');
    return;
  }
  writeBytes(readmeFile, newReadme);
  console.log('  * readme.txt: reset Upgrade Notice to "= ' + version + ' =" (add a short line if needed)');
}

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run') || args.includes('-d');
const noChangelog = args.includes('--no-changelog');
const version = args.find((a) => !a.startsWith('-'));

if (!version) fail('usage: node scripts/bump-version.js <version> [--dry-run] [--no-changelog]');
if (!SEMVER.test(version)) fail('"' + version + '" is not a valid version (expected e.g. 1.0.3)');

let changed = 0;
for (const { file, re } of TARGETS) {
  if (!fs.existsSync(file)) fail(file + ' not found (run from the plugin root)');
  const content = readBytes(file);
  const match = content.match(re);
  if (!match) fail('could not find a version to update in ' + file);

  const previous = match[2];
  if (previous === version) {
    console.log('  = ' + file + ' already at ' + version);
    continue;
  }
  const updated = content.replace(re, (full, before) => before + version);
  if (dryRun) {
    console.log('  ~ ' + file + ': ' + previous + ' -> ' + version + ' (dry run)');
  } else {
    writeBytes(file, updated);
    console.log('  * ' + file + ': ' + previous + ' -> ' + version);
  }
  changed++;
}

if (!noChangelog) {
  rollChangelog(version, dryRun);
  rollUpgradeNotice(version, dryRun);
}

if (dryRun) {
  console.log('\nDry run complete: ' + changed + ' file(s) would change to ' + version + '.');
} else {
  console.log('\nBumped ' + changed + ' file(s) to ' + version + '. Make sure the ' + version + ' entry at the top of changelog.txt is filled in (readme.txt mirrors it), then open a PR.');
}
