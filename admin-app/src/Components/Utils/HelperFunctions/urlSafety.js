// URL-scheme validation helpers.
//
// React Router's <Link to> and raw <a href> do NOT sanitize the URL scheme, so a
// stored/scanned value like "javascript:..." or "data:..." can become a clickable
// script/redirect sink. Run server- or scan-sourced URLs through these helpers before
// using them as a link target. Client-side defense-in-depth only — the server must
// still sanitize/escape on its side.

// Schemes we allow in a link href. Everything else (javascript:, data:, vbscript:, …)
// is rejected. Site-relative paths ("/foo") and protocol-relative ("//host") are allowed.
const SAFE_URL_REGEX = /^(https?:|mailto:|tel:|\/)/i;

/**
 * Returns the URL if its scheme is safe to use as a link target, otherwise `fallback`.
 * @param {string} url - the candidate URL (may be server/user controlled)
 * @param {string} [fallback="#"] - value to return when the URL is unsafe/empty
 * @returns {string}
 */
export const safeUrl = (url, fallback = '#') => {
  if (typeof url !== 'string') return fallback;
  const trimmed = url.trim();
  if (!trimmed) return fallback;
  return SAFE_URL_REGEX.test(trimmed) ? trimmed : fallback;
};

/**
 * True when the URL is an http(s) absolute URL — used to gate image previews and
 * external navigation that must not honour javascript:/data: schemes.
 * @param {string} url
 * @returns {boolean}
 */
export const isHttpUrl = (url) => typeof url === 'string' && /^https?:\/\//i.test(url.trim());
