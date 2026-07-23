/**
 * Resolves whether a feature/option is locked based on pro plugin status.
 *
 * Locked when:
 * - obj.is_locked === true (always, even with pro), OR
 * - 'is_locked' key exists in obj AND proPluginActive === false
 */
export const resolveIsLocked = (obj, proPluginActive) => {
  if (!obj || !('is_locked' in obj)) return false;
  if (obj.is_locked === true) return true;
  return !proPluginActive;
};
