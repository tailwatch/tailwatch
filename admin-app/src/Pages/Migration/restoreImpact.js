/**
 * What replacing this site with a backup does, said the same way in every question that
 * ends in a restore: restoring a backup, bringing another site here, trying that again and
 * loading a migration file.
 *
 * One fact per line, and only the lines that are true. A restore that leaves the database
 * alone keeps the users and the active plugin list, so it signs nobody out and switches no
 * plugin off, and is not warned that it might.
 */

/** What a restore replaces: the whole backup, or one half of the site. */
export const SCOPE_FULL = "full";
export const SCOPE_DATABASE = "database";
export const SCOPE_FILES = "files";

/**
 * The plugins the restore takes off the active list once the database is swapped, and
 * lists on the restore screen.
 */
export const RESTORE_SWITCH_OFF_NOTE =
  "Plugins that could lock you out, such as login-hiding, firewall and captcha plugins, are switched off. On a site without HTTPS, forced-SSL plugins and settings are too. You will see which ones at the end.";

/** Which half a backup holds, from what it says about itself. Not knowing counts as both. */
export const scopeOfContents = (contents) => {
  const files = Boolean(contents?.files);
  const database = Boolean(contents?.database);
  if (database && !files) return SCOPE_DATABASE;
  if (files && !database) return SCOPE_FILES;
  return SCOPE_FULL;
};

/** The same, from the backup type the backup list shows. */
export const scopeOfBackupType = (type) => {
  if (type === "Database Only") return SCOPE_DATABASE;
  if (type === "Files Only") return SCOPE_FILES;
  return SCOPE_FULL;
};

/**
 * The lines, each with the scopes it is true for.
 *
 * With `scope` only that scope's lines come back, without `when`. Without it every line
 * comes back with its `when`, for a question that lets the scope be chosen. A backup from
 * another site brings that site's users, so signing in afterwards means its accounts.
 */
export const restorePoints = ({ scope = "", fromOtherSite = false } = {}) => {
  const points = fromOtherSite
    ? [
        { icon: "replace", when: [SCOPE_FULL], text: "This site's files and database are replaced with that site's backup." },
        { icon: "replace", when: [SCOPE_DATABASE], text: "This site's database is replaced with that site's backup. Its files stay as they are." },
        { icon: "replace", when: [SCOPE_FILES], text: "This site's files are replaced with that site's backup. Its database stays as it is." },
        { icon: "maintenance", text: "Visitors see a maintenance page until it finishes." },
        { icon: "signin", when: [SCOPE_FULL, SCOPE_DATABASE], text: "Afterwards you sign in with that site's usernames and passwords." },
        { icon: "signin", when: [SCOPE_FULL, SCOPE_DATABASE], text: "Authenticator-app sign-ins and saved passwords from that site can't be used here: authenticator users set the app up again at their next sign-in, Email 2FA keeps working, and SMTP or Gmail details are entered again." },
        { icon: "shield", when: [SCOPE_FULL, SCOPE_DATABASE], text: RESTORE_SWITCH_OFF_NOTE },
      ]
    : [
        { icon: "replace", when: [SCOPE_FULL], text: "Your files and database are replaced with this backup." },
        { icon: "replace", when: [SCOPE_DATABASE], text: "Your database is replaced with this backup. Your files stay as they are." },
        { icon: "replace", when: [SCOPE_FILES], text: "Your files are replaced with this backup. Your database stays as it is." },
        { icon: "maintenance", text: "Visitors see a maintenance page until it finishes." },
        { icon: "signin", when: [SCOPE_FULL, SCOPE_DATABASE], text: "You may need to sign in again." },
        { icon: "shield", when: [SCOPE_FULL, SCOPE_DATABASE], text: RESTORE_SWITCH_OFF_NOTE },
      ];

  if (!scope) return points;
  return points
    .filter((point) => !point.when || point.when.includes(scope))
    .map(({ icon, text }) => ({ icon, text }));
};

/** Said where a transfer comes first: once it finishes, the site is replaced without asking again. */
export const TRANSFER_FIRST = "The transfer runs first. This site is replaced automatically as soon as it finishes.";

/** The last thing read before agreeing: there is no undo, and the way back is a backup. */
export const restoreWarning = ({ fromOtherSite = false, extra = "" } = {}) => ({
  title: "This can't be undone.",
  text: [
    extra,
    fromOtherSite
      ? "If you may want this site as it is now, take a backup of it first."
      : "If you may want your site as it is now, take a backup first.",
  ]
    .filter(Boolean)
    .join(" "),
});
