import { alertService } from '../../AlertService/AlertService';
import { announceBlocked, blockedByProcess } from './blockedProcess.js';

/**
 * Say why the site refused to resume or continue a run, and hand a process that owns the site
 * to ProcessLock.
 *
 * The backend refuses with 409 while a process that cannot run beside this one is running, or
 * is paused with its work half done. Its message names that process and what to do, which a
 * plain "failed to resume" does not.
 *
 * @param {object} payload The controller's answer (already unwrapped one level).
 * @return {Promise<boolean>} Whether the payload was such a refusal.
 */
export const warnIfBlocked = async (payload) => {
  const blocked = blockedByProcess(payload);
  if (!blocked) {
    return false;
  }

  await alertService.warning(
    payload.message || 'Another task is running. Please wait for it to finish, then try again.',
    payload.data?.paused === true ? 'Task Paused' : 'Process in Progress'
  );
  announceBlocked(blocked);
  return true;
};
