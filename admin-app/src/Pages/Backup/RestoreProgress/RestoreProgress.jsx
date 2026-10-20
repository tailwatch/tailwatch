import React, { useEffect, useRef, useState } from "react";
import { useDispatch } from "react-redux";
import { setRestoreRunning } from "../../../Components/Redux/ScanSlice.jsx";
/* global tailwatch_ajax */
import {
  fetchRestoreStatus,
  fetchRestoreLogs,
  resumeRestoreIfStalled,
  RESTORE_PHASE_LABELS,
  RESTORE_PHASE_ORDER,
} from "../RestoreServices/RestoreServices.jsx";
import LogsScreen from "../../../Components/LogsScreen/LogsScreen.jsx";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import ProgresBar from "../../../Components/Progressbar/ProgresBar.jsx";

/**
 * How often a finished round is followed by the next one.
 *
 * Counted from the END of a round rather than the start of the last: a round awaits two
 * requests, and on a site slow enough to take longer than the gap a fixed interval would
 * start the next one on top of it. Two rounds in flight read the same log cursor, so the
 * console ends up with lines repeated and out of order — from the one screen whose whole
 * job is to be believed about what just happened to the site.
 */
const POLL_EVERY_MS = 3000;

/**
 * The bar, in the two states it can be in.
 *
 * The brand colour holds from the first step through to the last, including at 100% — a
 * bar that changes colour on arrival draws the eye to the moment the run ENDS, when the
 * thing worth reading is the result above it. Grey is kept for the one case that is not a
 * finish at all: a restore that stopped part way, held at the step it reached.
 */
const BAR_RUNNING = "linear-gradient(to right, #007980, #85cbcf)";
const BAR_STOPPED = "linear-gradient(to right, #616161, #d1d5db)";

/**
 * How long a restore may show no movement before the server is asked to look at it.
 *
 * Long enough that an ordinary slow phase — a large SQL import, a big extract — is
 * never mistaken for a dead worker. Each of those still advances its own cursor every
 * few seconds; what does not move for two minutes is a restore whose worker is gone.
 */
const STALL_AFTER_MS = 120000;

/**
 * Minimum gap between two resume requests, so a genuinely wedged restore asks once a
 * minute rather than on every 3-second poll.
 */
const RESUME_EVERY_MS = 60000;

/**
 * How far along RESTORE_PHASE_ORDER a status sits, or -1 when it cannot be placed.
 *
 * `failed` is deliberately not a step in that order — it is an outcome — so a failed
 * restore's own phase says nothing about how far it got. The server's tick history does:
 * every tick records the phase it ran from and the phase it left behind, so the furthest
 * real step named there is where the restore stopped. That is what lets this screen draw
 * the grey bar at the right place even when it arrives after the failure, with no running
 * restore left to watch.
 */
const phaseIndexOf = (status) => {
  if (!status) {
    return -1;
  }

  const direct = RESTORE_PHASE_ORDER.indexOf(status.phase);
  if (direct >= 0) {
    return direct;
  }

  let furthest = -1;
  for (const tick of status.ticks ?? []) {
    furthest = Math.max(
      furthest,
      RESTORE_PHASE_ORDER.indexOf(tick?.from),
      RESTORE_PHASE_ORDER.indexOf(tick?.to)
    );
  }
  return furthest;
};

/**
 * Live progress for a running restore.
 *
 * Deliberately not dismissible while the restore is destructive. Once files are being
 * replaced there is nothing a user can usefully do except wait, and closing the panel
 * would hide the one thing that tells them whether the site came back.
 *
 * Polling is expected to fail intermittently: the site is behind a maintenance gate
 * for most of a restore, and the swap phase replaces the very tables this request
 * reads. A missed poll is therefore treated as "no news", never as "finished".
 */
const RestoreProgress = ({ folderDate, initialWarnings = [], onFinished, onIdle }) => {
  const dispatch = useDispatch();
  const [status, setStatus] = useState(null);
  const [missedPolls, setMissedPolls] = useState(0);
  const [stalled, setStalled] = useState(false);
  const [signedOut, setSignedOut] = useState(false);
  // The furthest step this restore is known to have reached, kept as state rather than
  // derived from the current phase alone — because the phase a failed restore reports
  // is `failed`, which is nowhere on the bar. Only ever moves forward: a restore does
  // not un-happen, and a bar that slid backwards at the moment of failure would be
  // saying the opposite of the truth about what has been replaced.
  const [reached, setReached] = useState(-1);
  // The running commentary. A bar says how far; this says what it is doing and what it
  // decided — the questions someone watching their own site being replaced actually has.
  const [logs, setLogs] = useState([]);
  // Open while it runs, the way every other module opens its console when its process is
  // found running. Still a toggle: once the restore is over the console is a record, and
  // a record the operator cannot put away is in the way of the result above it.
  const [isLogsVisible, setIsLogsVisible] = useState(true);
  const logIndex = useRef(0);
  const finishedRef = useRef(false);
  const seenRef = useRef(false);
  // Progress, not liveness. A poll that fails is expected here — the site is behind a
  // maintenance gate — so what marks a stall is the PHASE standing still, which a dead
  // worker cannot fake and a busy one cannot help but change.
  const lastPhaseRef = useRef("");
  const lastMovedRef = useRef(Date.now());
  const lastResumeRef = useRef(0);

  useEffect(() => {
    let cancelled = false;

    const poll = async () => {
      // Logs first, so the last thing said before a swap kills this session is on screen.
      const tail = await fetchRestoreLogs(logIndex.current);
      if (!cancelled && tail && tail.newLogs.length > 0) {
        logIndex.current = tail.lastLogIndex;
        setLogs((current) => [...current, ...tail.newLogs]);
      }

      const next = await fetchRestoreStatus();
      if (cancelled) return;

      if (!next) {
        setMissedPolls((n) => n + 1);
        return;
      }

      // The restore replaced wp_users and this session did not survive it. Nothing
      // further can be read, so stop polling and say what happened — the restore itself
      // is still running and does not need this tab.
      if (next.signedOut) {
        setSignedOut(true);
        cancelled = true;
        return;
      }

      // No restore on record for this backup. The server keeps only the most recent
      // restore, so before this one begins it answers "idle" or with an earlier restore of
      // another backup, already finished, which read as this run would announce "Restore
      // complete" for a restore that has not started. Before this screen has seen its own
      // run there is nothing to watch; after, either answer is a moment the state could
      // not be read — the swap replaces the table it lives in — and is no news, like a
      // missed poll.
      const another = !!next.folderDate && !!folderDate && next.folderDate !== folderDate;
      if (next.phase === "idle" || another) {
        if (seenRef.current) {
          setMissedPolls((n) => n + 1);
          return;
        }
        cancelled = true;
        if (typeof onIdle === "function") {
          onIdle();
        }
        return;
      }
      seenRef.current = true;

      setMissedPolls(0);
      setStatus(next);
      setReached((current) => Math.max(current, phaseIndexOf(next)));

      if (next.phase === "done" || next.phase === "failed") {
        if (!finishedRef.current) {
          finishedRef.current = true;
          if (typeof onFinished === "function") {
            onFinished(next);
          }
        }
        return;
      }

      const now = Date.now();
      if (next.phase !== lastPhaseRef.current) {
        lastPhaseRef.current = next.phase;
        lastMovedRef.current = now;
        setStalled(false);
        return;
      }

      if (
        now - lastMovedRef.current >= STALL_AFTER_MS &&
        now - lastResumeRef.current >= RESUME_EVERY_MS
      ) {
        lastResumeRef.current = now;
        setStalled(true);
        const action = await resumeRestoreIfStalled();
        if (cancelled) return;
        if (action === "resumed") {
          // The chain was rebuilt; give it a fresh window before asking again.
          lastMovedRef.current = Date.now();
        }
      }
    };

    // The next round is scheduled once the last one has resolved, and not at all after
    // the restore has reached an outcome, so rounds never pile up on a slow site and a
    // finished restore is not polled, or offered a recovery it does not need, until the
    // tab is closed.
    let timer = null;
    const loop = async () => {
      await poll();
      if (cancelled || finishedRef.current) {
        return;
      }
      timer = setTimeout(loop, POLL_EVERY_MS);
    };

    loop();

    return () => {
      cancelled = true;
      if (timer) {
        clearTimeout(timer);
      }
    };
  }, [folderDate, onFinished, onIdle]);

  const phase = status?.phase ?? "verify";
  const isFailed = phase === "failed";
  const isDone = phase === "done";
  // The furthest of what this screen has watched and what the current status can account
  // for on its own — which is what makes a cold arrival at a finished restore draw the
  // same bar as having sat through it.
  const stepIndex = Math.max(reached, RESTORE_PHASE_ORDER.indexOf(phase));
  const percent =
    stepIndex < 0
      ? 0
      : Math.round((stepIndex / (RESTORE_PHASE_ORDER.length - 1)) * 100);
  // A failed restore keeps its bar, in grey, at the step it stopped on: how far it got is
  // the whole of what it has left to say, and it decides whether the site was touched.
  // The bar goes only when that is genuinely unknown — arriving at a restore that failed
  // before this screen ever saw it, with no tick history to place it by — because a bar
  // sitting at zero would claim nothing happened, which is the one thing it must not say.
  const showBar = stepIndex >= 0;

  // Only meaningful once the destructive phase has begun; before that a failure
  // leaves the site exactly as it was.
  const siteTouched = !!status?.destructive;

  const warnings = status?.warnings?.length ? status.warnings : initialWarnings;

  /**
   * Hold the rest of the dashboard while this is running, and let go the moment it stops.
   *
   * Driven from the phase this screen is already watching rather than from the press that
   * started it: a restore begun in another tab, or before this page was opened, locks the
   * sidebar just the same, and one that fails at the first check never locks it at all.
   *
   * The release on unmount matters more than the lock. If this component goes away while
   * the flag is set — a signed-out session, a crash, a route change that slipped through —
   * the operator would be left with a sidebar that refuses every link and a toast blaming
   * a restore that is no longer running.
   */
  useEffect(() => {
    // Only once the site has actually answered. This screen is also where a FINISHED
    // restore is read back — migration links here for its report — and locking the whole
    // dashboard for the second or two before the first poll returns "done" would hold
    // someone hostage to a restore that ended days ago.
    const live = !!status && !isDone && !isFailed && !signedOut;
    dispatch(setRestoreRunning(live ? folderDate || " " : ""));
  }, [dispatch, folderDate, status, isDone, isFailed, signedOut]);

  useEffect(() => () => { dispatch(setRestoreRunning("")); }, [dispatch]);

  return (
    <div className="mb-4 rounded-md border border-gray-200 bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-base font-semibold text-gray-800">
            {isFailed
              ? "Restore failed"
              : isDone
              ? "Restore complete"
              : "Restoring your site"}
          </h3>
          <p className="mt-0.5 text-xs text-gray-500">
            From backup <span className="font-medium">{folderDate}</span>
          </p>
        </div>
        <div className="flex items-center gap-3">
          {logs.length > 0 && (
            <ActionButton
              onClick={() => setIsLogsVisible(!isLogsVisible)}
              isDisabled={false}
              defaultText={isLogsVisible ? "Hide Logs" : "Show Logs"}
              className="px-3 py-1 text-white rounded-md bg-[#007980] hover:bg-opacity-80"
            />
          )}
          <span
            className={`rounded px-2 py-1 text-xs font-medium ${
              isFailed
                ? "bg-red-100 text-red-800"
                : isDone
                ? "bg-green-100 text-green-800"
                : "bg-blue-100 text-blue-800"
            }`}
          >
            {RESTORE_PHASE_LABELS[phase] ?? phase}
          </span>
        </div>
      </div>

      {showBar && (
        <div className="mt-3">
          <ProgresBar
            progress={percent}
            bgColor={isFailed ? BAR_STOPPED : BAR_RUNNING}
          />
        </div>
      )}

      {!isFailed && !isDone && (
        <p className="!mt-2 !text-xs !leading-relaxed !text-gray-600">
          {siteTouched
            ? "Your site is closed to visitors and files are being replaced. Do not close this tab or navigate away."
            : "Nothing on your site has been changed yet."}
        </p>
      )}

      {signedOut && (
        <p className="!mt-2 !text-xs !leading-relaxed !text-gray-700">
          You were signed out because the restore replaced the site's user accounts.
          The restore is still running and does not need this tab —{" "}
          <a
            className="underline"
            href={`${tailwatch_ajax.admin_url}admin.php?page=tailwatch`}
          >
            sign in again
          </a>{" "}
          to watch the rest of it.
        </p>
      )}

      {!signedOut && missedPolls > 0 && !isDone && !isFailed && (
        <p className="!mt-2 !text-xs !italic !leading-relaxed !text-gray-500">
          Waiting for the site to respond — this is normal while it is closed to
          visitors.
        </p>
      )}

      {stalled && !isDone && !isFailed && (
        <p className="!mt-2 !text-xs !italic !leading-relaxed !text-gray-500">
          This step is taking longer than expected. Checking whether the restore
          needs to be restarted — keep this tab open.
        </p>
      )}

      {isDone && (
        <div className="mt-2">
          <p className="!m-0 !text-xs !leading-relaxed !text-gray-600">
            Your site has been restored. You may need to sign in again.
          </p>

        </div>
      )}

      {isFailed && (
        <div className="mt-3">
          <p className="!m-0 !text-xs !font-medium !leading-relaxed !text-gray-700">
            {siteTouched
              ? "The restore stopped after it had begun replacing files. Your database was not changed."
              : "The restore was stopped before anything on your site was changed."}
          </p>
          {status?.errors?.length > 0 && (
            <ul className="!mb-0 !ml-0 !mt-2 !list-disc !pl-4 !text-xs !text-red-700">
              {status.errors.map((message, index) => (
                <li key={index}>{message}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      {/* Every class forced.
          This renders inside wp-admin, whose own stylesheet has opinions about `p`, `ul`
          and `li` — list markers off, its own margins, its own font size — and it loads in
          a way that beats a plain utility class. */}
      {warnings.length > 0 && (
        <div className="!mt-3 !rounded-md !border !border-yellow-200 !bg-yellow-50 !px-3 !py-2.5">
          <p className="!m-0 !text-xs !font-semibold !leading-relaxed !text-yellow-900">
            Restoring anyway, with these caveats:
          </p>
          <ul className="!mb-0 !ml-0 !mt-1.5 !list-disc !pl-4 !text-xs !text-yellow-800">
            {warnings.map((message, index) => (
              <li
                key={index}
                className="!my-0.5 !list-disc !p-0 !text-xs !leading-relaxed !text-yellow-800"
              >
                {message}
              </li>
            ))}
          </ul>
        </div>
      )}

      {logs.length > 0 && (
        <div className="mt-4">
          <LogsScreen
            logs={logs}
            setLogs={setLogs}
            isLogsVisible={isLogsVisible}
            isPaused={false}
            isCanceled={status?.phase === "failed"}
            isCompleted={status?.phase === "done"}
            cancelMessage="The restore stopped."
            successMessage="Restore complete. The site is open again."
            // A restore runs with the site closed to visitors, so a quiet console is what
            // this screen expects rather than a surprise — and it already says so, in the
            // status card above, in words that fit a restore. The console's own notice
            // would be a second voice on the same silence, and the one that says "don't
            // panic" to someone watching their site be overwritten.
            showStallNotice={false}
          />
        </div>
      )}
    </div>
  );
};

export default RestoreProgress;
