import { OWNING_PROCESSES, PROCESS_BLOCKED_EVENT } from './owningProcesses.js';

/**
 * A refusal that names the process holding the site.
 *
 * The backend refuses a conflicting action with 409 and says which module is running.
 * That name is what an operator needs: "another process is running" tells them nothing
 * they did not know, while the name says WHAT is running and where to watch it.
 *
 * Reading the name here also closes a gap. ProcessLock asks when the dashboard loads and
 * when the operator moves to another screen, so a process that started since — in another
 * tab, or one second ago — is not yet known to this page. A 409 is proof it is running,
 * arriving before the next move would ask, so it is worth acting on immediately.
 */

/** Re-exported so a caller needs only this module to raise one. */
export { PROCESS_BLOCKED_EVENT };

/**
 * Read a guard refusal out of a dispatcher payload.
 *
 * Returns null for anything that is not one — a 409 from somewhere else, or a refusal
 * for a different reason, must not be mistaken for a running process and must not move
 * the operator off the page they are on.
 *
 * @param {object} payload The controller's answer (already unwrapped one level).
 * @return {{type: string, owns: boolean}|null}
 */
export const blockedByProcess = (payload) => {
  const data = payload?.data ?? {};

  if (payload?.code !== 409 || data.blocked_by_process !== true) {
    return null;
  }

  const type = typeof data.running_process === 'string' ? data.running_process : '';

  // `owns` separates the three that close the site from the ones that merely conflict.
  // A backup refusing a second backup is a wait; a restore refusing anything is a
  // redirect, because there is nothing else worth looking at while it runs.
  return { type, owns: Boolean(OWNING_PROCESSES[type]) };
};

/**
 * Tell the dashboard a refusal named an owning process.
 *
 * Deliberately an event rather than a navigation. Services have no router, and the
 * decision of where the operator goes belongs in one place — ProcessLock — so that a
 * refusal and a poll produce the same behaviour instead of two rival redirects.
 */
export const announceBlocked = (blocked) => {
  if (!blocked?.owns || typeof window === 'undefined') {
    return;
  }

  window.dispatchEvent(
    new CustomEvent(PROCESS_BLOCKED_EVENT, { detail: { type: blocked.type } })
  );
};
