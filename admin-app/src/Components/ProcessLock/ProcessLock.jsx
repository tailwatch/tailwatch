import { useEffect, useRef } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import UseOwningProcess from '../Hooks/OwningProcess/UseOwningProcess';

/**
 * Keeps the operator on the operation that owns the site.
 *
 * While a restore, a migration or a malware restore is running, the site is closed to
 * visitors and its files and tables are being replaced under the request. Anything else
 * started now is refused at best, with an "another process is running" error that says
 * nothing about what IS running or where to watch it.
 *
 * So: on load, and on any move to another screen while one is running, the dashboard goes
 * to that operation's own screen — the one with its progress and its console. That includes
 * one that started after the page loaded: each move asks again.
 *
 * Two deliberate restraints:
 *
 * - It waits for an answer. Redirecting before the first reply would bounce every page
 *   load, and a status endpoint that cannot be reached must never take the UI away.
 * - It does not lock on a STUCK process. A job that has stopped reporting is exactly when
 *   an operator needs the rest of the dashboard to cancel or recover it.
 */
const ProcessLock = () => {
  const { owner, checked, recheck } = UseOwningProcess();
  const location = useLocation();
  const navigate = useNavigate();
  const path = location.pathname;

  const onItsScreen = owner ? path.startsWith(owner.route) : true;

  // Asked when the dashboard loads and again whenever the operator moves to another screen,
  // never on a timer. One question per move is what notices an operation that started after
  // the page loaded — a migration hands over to a restore moments after its own screen was
  // opened — and a move is the only moment a redirect can happen anyway.
  //
  // A redirect acts only on an answer asked for the screen being shown: the answer from an
  // earlier screen can be out of date by then, and a run that finished in this session must
  // not keep the dashboard on its page until a reload.
  const lastPath = useRef(null);
  const askedFor = useRef('');

  useEffect(() => {
    if (!checked) {
      return;
    }

    const moved = lastPath.current !== null && lastPath.current !== path;
    lastPath.current = path;

    if (owner && onItsScreen) {
      askedFor.current = '';
      return;
    }

    if (!owner) {
      // Nothing was running at the last answer. Something may have started since, so a move
      // asks again, and an operation found running comes back through `owner`.
      if (moved) {
        askedFor.current = path;
        recheck();
      }
      return;
    }

    if (askedFor.current !== path) {
      askedFor.current = path;
      recheck();
      return;
    }

    navigate(owner.route, { replace: true });
  }, [checked, owner, onItsScreen, path, recheck, navigate]);

  // Renders nothing, deliberately.
  //
  // This component sits above the router outlet, and every page's own <Header> is inside
  // it, so anything drawn here would land ABOVE the page title. Nothing needs drawing
  // either: the screen this sends the operator to already carries the phase, the progress
  // bar and the running console.
  //
  // So this is a redirect, and only a redirect.
  return null;
};

export default ProcessLock;
