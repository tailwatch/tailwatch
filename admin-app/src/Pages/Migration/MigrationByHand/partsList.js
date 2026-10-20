/**
 * The rows the parts list shows.
 *
 * The whole job, with the arrived parts ticked, when the browser still holds the names the
 * migration file listed AND they describe this import: as many names as the site reports
 * parts, and every part the site is still waiting for among them. A list left over from an
 * earlier import, or none at all because this one was started in another browser, fails
 * that test, and the rows fall back to the parts that are still missing.
 *
 * @param {string[]} expected Part names kept from the migration file.
 * @param {string[]} missing  Part names the site is still waiting for.
 * @param {number}   total    Number of parts the site expects.
 * @returns {{ knowsEveryPart: boolean, parts: { file: string, done: boolean }[] }}
 */
export const partsToShow = (expected, missing, total) => {
  const names = Array.isArray(expected) ? expected : [];
  const waiting = Array.isArray(missing) ? missing : [];
  const knowsEveryPart =
    names.length > 0 &&
    names.length === Number(total) &&
    waiting.every((file) => names.includes(file));

  return {
    knowsEveryPart,
    parts: knowsEveryPart
      ? names.map((file) => ({ file, done: !waiting.includes(file) }))
      : waiting.map((file) => ({ file, done: false })),
  };
};
