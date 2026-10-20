/**
 * The small conversions every screen in this module does.
 *
 * Every screen here shows bytes, contents and expiry, so they are rounded in one place
 * rather than inside any one page component.
 */

/**
 * Sizes, as a person reads them.
 *
 * Under 10 of a unit keeps one decimal — 1.2 GB says more than 1 GB; above it, none.
 * Zero and nothing both return an empty string rather than "0 B", because every caller
 * puts this in a list of facts where an unknown size should simply not be a fact.
 */
export const formatBytes = (bytes) => {
  const size = Number(bytes) || 0;
  if (!size) return "";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let value = size;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value >= 10 || unit === 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
};

/**
 * The upload chunk size, for the sentence that explains why a part is sent in pieces.
 *
 * Floored at 1 MB on purpose. The site is asked what size it wants and a site that answers
 * zero — a filter that returned nothing, for instance — would otherwise be taken at its
 * word, and a part sliced into one-byte pieces is a browser that stops responding. A floor
 * makes the wrong answer slow rather than fatal.
 */
export const MIN_CHUNK_BYTES = 1024 * 1024;

export const chunkSize = (bytes) => Math.max(MIN_CHUNK_BYTES, Number(bytes) || 0);

/** What the backup holds, in the words the rest of the plugin uses for it. */
export const describeContents = (backup) => {
  if (!backup) return "";
  if (backup.files && backup.database) return "Files and database";
  if (backup.database) return "Database only";
  if (backup.files) return "Files only";
  return "";
};

/** How many parts, said so it reads in a list beside the size. */
export const describeParts = (count) =>
  count ? `${count} part${count === 1 ? "" : "s"}` : "";

/**
 * How long a pairing key has left, in the site's own terms.
 *
 * Read at render rather than counted down: a ticking clock is a promise this screen cannot
 * keep across a reload, and an operator returning to a stale tab should be told the key
 * expired, not shown one quietly counting towards a time that has already passed.
 */
export const expiresIn = (timestamp) => {
  if (!timestamp) return "an hour or less";
  const seconds = timestamp - Math.floor(Date.now() / 1000);
  if (seconds <= 0) return "expired";
  const minutes = Math.ceil(seconds / 60);
  return `${minutes} minute${minutes === 1 ? "" : "s"}`;
};

/**
 * Transfer speed, averaged over the whole transfer rather than sampled.
 *
 * A transfer moves one part at a time across the site's scheduled ticks, so an
 * instantaneous figure would swing between the speed of the link and zero, and mean
 * neither.
 */
export const transferRate = (bytesDone, seconds) =>
  seconds && bytesDone ? `${formatBytes(Math.round(bytesDone / seconds))}/s` : "";

/** One line of facts, with the ones that are not known left out entirely. */
export const factLine = (...parts) => parts.filter(Boolean).join(" · ");
