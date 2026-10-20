import { useState, useEffect, useCallback, useRef } from 'react';
import { processMoniteringStatus } from '../../Header/Services/Services';
import { OWNING_PROCESSES, PROCESS_BLOCKED_EVENT } from './owningProcesses.js';

/**
 * Whether some operation currently owns this site.
 *
 * Refusing each action separately, one 409 at a time, tells the operator what they cannot
 * do only after they have tried. The point of this hook is to say it before: the dashboard
 * takes them to the operation that is running and keeps them there until it finishes, so
 * the only thing on screen is the thing that matters.
 *
 * Which operations those are, and why only those, is in ./owningProcesses.
 */

/**
 * Asked when the dashboard loads, and again on request. Never on a timer.
 *
 * There are three ways it is asked:
 *
 * - on mount, which is a page load or a refresh: arriving at the dashboard while one of
 *   these operations already owns the site.
 * - by ProcessLock, through `recheck`, each time the operator moves to another screen.
 *   That is what notices an operation started after the page loaded — a migration
 *   hands the site to a restore moments after its own screen was opened.
 * - on a refusal: a 409 naming one of these processes is proof one is running, and is
 *   the moment the operator most needs taking to it.
 *
 * An operation that starts while the operator stays on one screen is noticed on their
 * next move, not before.
 */

const UseOwningProcess = () => {
  // null = not asked yet. Distinct from "nothing is running", because redirecting on an
  // unanswered question would bounce every page load.
  const [owner, setOwner] = useState(null);
  const [checked, setChecked] = useState(false);
  const cancelled = useRef(false);

  const check = useCallback(async () => {
    try {
      const payload = await processMoniteringStatus();
      const running = payload?.data?.active_processes;

      if (cancelled.current) {
        return;
      }

      if (!Array.isArray(running)) {
        // Could not be read. Say nothing rather than locking the dashboard on a failed
        // request — an unreachable status endpoint must not take the UI away.
        setChecked(true);
        return;
      }

      const found = running.find(
        (process) => OWNING_PROCESSES[process?.type] && !process?.is_stuck
      );

      setOwner(found ? { ...OWNING_PROCESSES[found.type], type: found.type, process: found } : null);
      setChecked(true);
    } catch (error) {
      setChecked(true);
    }
  }, []);

  useEffect(() => {
    cancelled.current = false;
    check();

    // A refusal is proof a process is running, arriving before the next move would ask.
    // It is what turns "another process is running" from a dead-end warning into being
    // taken to the thing that is running.
    const onBlocked = () => check();
    window.addEventListener(PROCESS_BLOCKED_EVENT, onBlocked);

    return () => {
      cancelled.current = true;
      window.removeEventListener(PROCESS_BLOCKED_EVENT, onBlocked);
    };
  }, [check]);

  return { owner, checked, recheck: check };
};

export { OWNING_PROCESSES, PROCESS_BLOCKED_EVENT };
export default UseOwningProcess;
