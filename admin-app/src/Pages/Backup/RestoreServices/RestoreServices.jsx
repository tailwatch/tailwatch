import axios from "axios";
import { alertService } from "../../../Components/AlertService/AlertService";
import {
  blockedByProcess,
  announceBlocked,
} from "../../../Components/Hooks/OwningProcess/blockedProcess";
/* global tailwatch_ajax */

/**
 * Restore is the only destructive action in the product: it overwrites files under
 * the WordPress root and renames every table in the backup. The backend refuses more
 * ways than it accepts, so each refusal is surfaced with its own reason rather than a
 * generic failure — an operator staring at "something went wrong" mid-restore has no
 * way to tell a rejected backup from a broken site.
 */

const post = async (actionType, data = {}) => {
  const formData = new FormData();
  formData.append("action", "tailwatch_global_ajax_handler");
  formData.append("action_type", actionType);
  formData.append("nonce", tailwatch_ajax.nonce);
  formData.append("data", JSON.stringify(data));

  const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  // The dispatcher wraps every controller response one level deep.
  const envelope = response?.data ?? {};
  const payload = envelope?.data ?? {};

  // The AJAX gate rejects a dead session with a bare string — wp_send_json_error(
  // 'Invalid nonce' ) — and HTTP 200, while every controller answers with an object
  // carrying `code`. That shape difference is the only signal available, and without
  // reading it a signed-out operator is indistinguishable from an unreachable site.
  if (envelope.success === false && typeof payload === "string") {
    return { code: 401, message: payload, signedOut: true };
  }

  return payload;
};

/**
 * Whether a refusal was the feature switch rather than anything about the request.
 *
 * The dispatcher answers every gated action with this pair before the controller runs,
 * which is the same pair the status poll carries. Recognising it here is what lets a
 * press update the screen instead of producing an alert that says "not enabled" and
 * leaves the button looking pressable.
 */
export const isFeatureRefusal = (result) =>
  result?.feature_enable === false || result?.parent_enable === false;

/**
 * Ask the backend to start restoring a backup.
 *
 * Returns { started: bool, warnings: [] }. Everything that is not a start is
 * explained to the operator here and reported as started:false, so callers only have
 * to handle the happy path.
 */
export const startRestore = async (folderDate, scope = "full") => {
  try {
    const result = await post("tailwatch_start_restore", { folderDate, scope });

    // 422 — the pre-restore gate refused. These are the checks that run BEFORE
    // anything is overwritten, so the site is untouched and the errors are the
    // whole point: show them all rather than only the first.
    if (result.code === 422) {
      const reasons = Array.isArray(result.errors) ? result.errors : [];
      await alertService.error(
        reasons.length
          ? reasons.join("\n")
          : "This backup could not be verified, so it will not be restored.",
        "This backup cannot be restored"
      );
      return { started: false, warnings: [] };
    }

    // 409 — another process owns the site right now. Naming it is what lets the
    // operator decide whether to wait or cancel that process.
    if (result.code === 409) {
      // The backend's own message names the module — "Site Migration is currently
      // running" — so it is passed through rather than replaced by a generic line.
      // Announcing it takes the operator to that module instead of leaving them on a
      // screen whose only remaining option is the one that was just refused.
      const blocked = blockedByProcess(result);
      await alertService.warning(
        result.message ||
          "Another background process is running. Wait for it to finish before restoring.",
        "Process in progress"
      );
      announceBlocked(blocked);
      return { started: false, warnings: [] };
    }

    if (result.code === 403) {
      await alertService.error(
        result.message || "You do not have permission to restore this site.",
        "Not permitted"
      );
      return { started: false, warnings: [] };
    }

    // The switch was turned off after this page loaded — in another tab, or by someone
    // else. Say which setting it is, and tell the caller so the screen can go to its
    // disabled state now rather than at the next poll.
    if (isFeatureRefusal(result)) {
      await alertService.warning(
        "Restoring is switched off for this site. Turn on \"Allow Restoring\" under Restore & Migration in Features.",
        "Restoring is switched off"
      );
      return { started: false, warnings: [], featureDisabled: true };
    }

    if (result.code !== 200) {
      await alertService.error(
        result.message || "The restore could not be started.",
        "Restore failed to start"
      );
      return { started: false, warnings: [] };
    }

    return {
      started: true,
      warnings: Array.isArray(result.warnings) ? result.warnings : [],
    };
  } catch (error) {
    console.error("Error starting restore", error);
    await alertService.error(
      "The restore could not be started. Check that the site is reachable and try again.",
      "Restore failed to start"
    );
    return { started: false, warnings: [] };
  }
};

/**
 * Current restore progress.
 *
 * Returns null on a transport error rather than a fake idle state: while a restore is
 * running the site is behind a maintenance gate, so a failed poll is expected and must
 * not be read as "the restore finished".
 */
export const fetchRestoreStatus = async () => {
  try {
    const result = await post("tailwatch_restore_status");

    // A restore replaces wp_users, so the session behind this poll can end mid-restore.
    // That is a different fact from a missed poll and the operator has to be told, or
    // they sit watching a panel that will never move again.
    if (result.signedOut) {
      return { signedOut: true };
    }

    if (result.code !== 200) {
      return null;
    }
    return {
      signedOut: false,
      // The same pair every other module reads, reported on every poll so the screen can
      // say so up front instead of only after someone confirms an overwrite. A backend
      // that sends neither has to read as enabled — not as a warning over a restore that
      // works perfectly well.
      featureEnable: result.feature_enable !== false,
      parentEnable: result.parent_enable !== false,
      phase: result.phase ?? "idle",
      folderDate: result.folder_date ?? "",
      destructive: !!result.destructive,
      errors: Array.isArray(result.errors) ? result.errors : [],
      warnings: Array.isArray(result.warnings) ? result.warnings : [],
      scope: result.scope ?? "full",
      // Only a definite true offers the choice: an absent flag is not read as granted.
      partialAllowed: result.partial_allowed === true,
      // One entry per worker tick, each naming the phase it ran from and the phase it
      // left the restore in. `failed` is an outcome rather than a step, so a restore
      // that has already failed reports a phase that places nowhere on the bar — and
      // how far it got is the one thing a failed restore has to convey. This is where
      // that survives, for a screen arriving after the fact with nothing else to read.
      ticks: Array.isArray(result.ticks) ? result.ticks : [],
    };
  } catch (error) {
    return null;
  }
};

/**
 * Ask the backend to restart a restore whose worker has gone.
 *
 * Called only when the phase has not moved for a long time. The decision is entirely
 * the server's: it holds the heartbeat, the scheduled event and the worker lock, and
 * this is a request to look at them, not an instruction to restart anything. A failure
 * here is silent on purpose — the poller keeps running either way, and an alert about
 * a recovery attempt would be more alarming than the stall it is recovering from.
 */
export const resumeRestoreIfStalled = async () => {
  try {
    const result = await post("tailwatch_resume_restore_if_stalled");
    return result?.action ?? "none";
  } catch (error) {
    return "none";
  }
};

/**
 * Human-readable label per orchestrator phase. Kept beside the service rather than in
 * the component so the phase vocabulary has one home on the front end.
 */
export const RESTORE_PHASE_LABELS = {
  verify: "Checking the backup",
  engage: "Closing the site to visitors",
  import: "Loading the database into a staging copy",
  rewrite: "Updating the site's address",
  extract: "Preparing the restored files",
  prune: "Switching the files into place",
  swap: "Switching the database over",
  finalize: "Reopening the site",
  done: "Restore complete",
  failed: "Restore failed",
  idle: "Waiting",
};

/**
 * Phases in order, for the progress bar. `failed` is deliberately absent — it is an
 * outcome, not a step.
 */
export const RESTORE_PHASE_ORDER = [
  "verify",
  "engage",
  "import",
  "rewrite",
  "extract",
  "prune",
  "swap",
  "finalize",
  "done",
];


/**
 * The running commentary for a restore.
 *
 * A progress bar answers "how far". It does not answer "what is it doing" or "what did it
 * decide" — and those are the questions someone watching their own site being overwritten
 * actually has.
 *
 * Asks only for what it has not seen: a restore replaces the site under the browser, so a
 * poll that misses a tick must not replay the whole log back into the panel.
 */
export const fetchRestoreLogs = async (lastLogIndex = 0) => {
  try {
    const result = await post("tailwatch_restore_logs", { lastLogIndex });
    if (result.code !== 200) {
      return null;
    }
    return {
      newLogs: Array.isArray(result.new_logs) ? result.new_logs : [],
      lastLogIndex: result.last_log_index ?? lastLogIndex,
      phase: result.phase ?? "idle",
      isCompleted: !!result.is_completed,
    };
  } catch (error) {
    // A failed poll during a restore is expected — the site is behind a maintenance gate
    // and may be mid-swap. Null, never an empty log: an empty log reads as "nothing is
    // happening", which is the one thing it must not say.
    return null;
  }
};
