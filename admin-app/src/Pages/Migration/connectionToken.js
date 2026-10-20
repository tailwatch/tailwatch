/**
 * The one thing an operator carries between two sites to start an automatic migration.
 *
 * ## Why a string and not a file
 *
 * This is a credential: whoever holds it can pull the source site's newest backup. A file
 * lands in Downloads, syncs to cloud storage, gets forwarded, and outlives its usefulness;
 * a pasted string is transient. It also cannot be confused with the migration file, which
 * is a packing list that carries no credential at all and is meant to be emailed around.
 * Two JSON files in one workflow would be mixed up, and the safe-looking one is the one
 * people would be careless with.
 *
 * A secret written into that file would have no expiry, no single use and no revocation:
 * anyone who ever received it could pull the whole site, forever.
 *
 * ## What the token is, and is not
 *
 * It is an ENCODING, not a protection. Base64 hides nothing; anyone can read the address
 * and key back out. Its whole job is to make one thing to carry instead of two, and to
 * fail clearly when it is the wrong thing.
 *
 * Nothing downstream trusts it either: the destination decodes it and hands both halves to
 * the same endpoint the two-field form uses, which validates the address through the SSRF
 * guard and the key against the source's stored hash. A tampered token cannot reach
 * anywhere a typed address could not.
 *
 * The credential's properties are the pairing key's, because it IS the pairing key: shown
 * once, one hour, single use, revocable.
 */

/** Current format. A token from a newer version is refused by name rather than by failing. */
export const CONNECTION_TOKEN_VERSION = 'tw1';

const PREFIX = `${CONNECTION_TOKEN_VERSION}.`;

/** Base64url, so the token survives a URL bar, an email client and a double-click. */
const encodeBase64Url = (text) => {
  const bytes = new TextEncoder().encode(text);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const decodeBase64Url = (token) => {
  const padded = token.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(padded);
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  return new TextDecoder().decode(bytes);
};

/**
 * Pack a source address and its pairing key into one thing to carry.
 *
 * @param {string} siteUrl The source site's address.
 * @param {string} key     The pairing key it just minted.
 * @returns {string} The token, or '' if either half is missing — a half token would be
 *                   worse than none, because it looks carryable.
 */
export const buildConnectionToken = (siteUrl, key) => {
  const url = (siteUrl || '').trim();
  const pairing = (key || '').trim();

  if (!url || !pairing) {
    return '';
  }

  return PREFIX + encodeBase64Url(JSON.stringify({ u: url, k: pairing }));
};

/**
 * Read a token back, or say why it cannot be read.
 *
 * Every refusal names something the operator can act on. "Invalid key" tells them nothing
 * about whether they pasted the wrong thing, half a thing, or a thing from a version this
 * site does not know.
 *
 * @param {string} token Whatever was pasted.
 * @returns {{siteUrl: string, key: string}|{error: string}}
 */
export const parseConnectionToken = (token) => {
  const raw = (token || '').trim();

  if (!raw) {
    return { error: 'Paste the connection key from the other site.' };
  }

  if (!raw.startsWith(PREFIX)) {
    // A token from a LATER version is the one failure worth naming precisely: the fix is
    // to update this site, not to generate another key.
    if (/^tw\d+\./.test(raw)) {
      return {
        error:
          'That connection key was made by a newer version of Tailwatch. Update this site, then paste it again.',
      };
    }
    // The other likely mistake: the migration file's contents, pasted here.
    if (raw.startsWith('{')) {
      return {
        error:
          'That looks like the contents of a migration file, not a connection key. Migration files are loaded further down, under moving a site by hand.',
      };
    }
    return { error: 'That does not look like a Tailwatch connection key.' };
  }

  let decoded;
  try {
    decoded = JSON.parse(decodeBase64Url(raw.slice(PREFIX.length)));
  } catch (error) {
    // Truncated in an email, or a stray character on the end.
    return {
      error:
        'That connection key could not be read. Copy it again from the other site — it may have been cut short.',
    };
  }

  const siteUrl = typeof decoded?.u === 'string' ? decoded.u.trim() : '';
  const key = typeof decoded?.k === 'string' ? decoded.k.trim() : '';

  if (!siteUrl || !key) {
    return {
      error:
        'That connection key is missing the other site or its key. Generate a new one there.',
    };
  }

  return { siteUrl, key };
};
