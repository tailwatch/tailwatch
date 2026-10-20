import { readFeatureGate } from '../../../Components/Utils/featureGate';
import axios from "axios";
import { toast } from "react-toastify";
import { alertService } from "../../../Components/AlertService/AlertService";
import { CronHealer } from "../../../Components/CroneHealer/CronHealer";
import { warnIfBlocked } from "../../../Components/Hooks/OwningProcess/warnIfBlocked";
/* global tailwatch_ajax */

// The site refused to start because the disk is too full. Said in the site's own words:
// how much is free, how much a start needs, and what to do about it.
const refusedForDiskSpace = async (payload) => {
  if (payload?.code !== 507) {
    return false;
  }
  await alertService.warning(
    payload.message || "There is not enough free disk space to start a backup.",
    "Not enough disk space"
  );
  return true;
};

// The site refused to start because a process is already running: a backup started in another
// tab or still starting, or another module's process. A running backup is followed here, as if
// this start had begun it. null when the answer is not such a refusal.
const refusedForRunningProcess = async (payload, setScanCompleted, fetchLogs) => {
  if (payload?.code !== 409 || payload?.data?.blocked_by_process !== true) {
    return null;
  }
  const message = payload.message || 'A background process is currently running. Please wait for it to finish before starting a backup.';
  await alertService.warning(message, 'Process in Progress');
  if (payload.data.running_process === 'backup' && typeof fetchLogs === 'function') {
    setScanCompleted(true);
    fetchLogs();
    return { followed: true };
  }
  return { followed: false };
};

export const instantScanning = async (
  setScanCompleted,
  setErrorMessages,
  fetchLogs
) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_instant_backup_scanner");
    formData.append("nonce", tailwatch_ajax.nonce);
    formData.append("data", JSON.stringify({ instant_scan: true }));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const refused = await refusedForRunningProcess(response.data?.data, setScanCompleted, fetchLogs);
    if (refused) {
      if (refused.followed) {
        return true;
      }
      return;
    }
    if (await refusedForDiskSpace(response.data?.data)) {
      return false;
    }
    const { database_optimize, optimize_skip } = response.data.data;

    // Case 1: database_optimize or optimize_skip is true
    if (database_optimize || optimize_skip) {
      setScanCompleted(true);
      fetchLogs();
      return true;
    }
    // Case 2: if database_optimize and optimize_skip are both false show pop-up
    if (database_optimize === false && optimize_skip === false) {
      const result = await alertService.optimizationPopup(
        "Do you want to create a backup with database optimization? This may improve performance but could take longer.",
        "Database Optimization Option",
        "Backup with Optimization",
        "Skip Optimization"
      );

      let optimizeSkipValue;
      if (result) {
        optimizeSkipValue = false; // Backup with optimization
      } else {
        optimizeSkipValue = true; // Skip optimization
      }

      const newFormData = new FormData();
      newFormData.append("action", "tailwatch_global_ajax_handler");
      newFormData.append(
        "action_type",
        "tailwatch_start_backup_with_optimize_or_not"
      );
      newFormData.append("nonce", tailwatch_ajax.nonce);
      newFormData.append(
        "data",
        JSON.stringify({ optimize_skip: optimizeSkipValue })
      );
      const newResponse = await axios.post(tailwatch_ajax.ajax_url, newFormData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      const refusedNow = await refusedForRunningProcess(newResponse.data?.data, setScanCompleted, fetchLogs);
      if (refusedNow) {
        if (refusedNow.followed) {
          return true;
        }
        return;
      }
      if (await refusedForDiskSpace(newResponse.data?.data)) {
        return false;
      }

      const { optimize_skip: newInstantScan } = newResponse.data.data;

      if (newInstantScan === true || newInstantScan === false) {
        setScanCompleted(true);
        fetchLogs();
        return true;
      } else {
        setErrorMessages((prevErrors) => [
          ...prevErrors,
          "Failed to start backup after optimization choice",
        ]);
        return false;
      }
    }
    // Case 3: neither flag came back as an answer, so the backup did not start
    else {
      if (
        response.data.data.message ===
        "Failed to run backup process: instant_scan is false."
      ) {
        setErrorMessages((prevErrors) => [
          ...prevErrors,
          ` ${response.data.data.message} `,
        ]);
      } else {
        setErrorMessages((prevErrors) => [
          ...prevErrors,
          "tailwatch_instant_backup error",
        ]);
      }
      return false;
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Error in starting the backup process",
    ]);
    console.error("Network error", error);
    return false;
  }
};

let cronScheduled = false;
let isCronExecuting = false;
let cronTimeoutId = null;

/**
 * How long the backup may show no movement before the server is asked to look at it.
 *
 * cron_running is written to false by the worker on its way out, so ONLY a tick that
 * returns clears it. The failures this watchdog exists for — an out-of-memory kill, the
 * OOM killer, a proxy cutting the request — never reach the way out, and leave the flag
 * reading "running" forever. Worse, the branch below then CANCELS the pending recovery.
 *
 * The log cursor cannot be faked the same way: it only advances when a tick actually did
 * something. Two minutes is comfortably longer than the slowest single part.
 */
const STALL_AFTER_MS = 120000;

/**
 * Minimum gap between two recovery requests, so a wedged run asks once a minute rather
 * than on every poll.
 */
const RECOVER_EVERY_MS = 60000;

let lastProgressMark = "";
let lastProgressAt = Date.now();
let lastRecoveryAt = 0;

const noteBackupProgress = (lastLogIndex, progress) => {
  const mark = `${lastLogIndex}:${progress}`;
  if (mark !== lastProgressMark) {
    lastProgressMark = mark;
    lastProgressAt = Date.now();
  }
};

/**
 * Ask the server to revive the run when nothing has moved for a while.
 *
 * The client only reports "nothing is happening". Whether that means a dead worker is
 * the server's call — it holds the heartbeat and the scheduled events, and it refuses
 * when a slow-but-live tick is still checking in.
 */
const checkStalledBackup = async () => {
  const now = Date.now();
  if (now - lastProgressAt < STALL_AFTER_MS) {
    return;
  }
  if (now - lastRecoveryAt < RECOVER_EVERY_MS) {
    return;
  }

  lastRecoveryAt = now;
  await executeCronIfFailed();
};

const executeCronIfFailed = async () => {
  if (isCronExecuting) {
    return;
  }
  try {
    isCronExecuting = true;

    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_execute_cron_if_failed");
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
    } else {
      console.error("Failed to execute cron.");
    }
  } catch (error) {
    console.error("Error executing cron", error);
  } finally {
    cronScheduled = false;
    isCronExecuting = false;
  }
};
const checkCronExecution = async (message, cron_running) => {
  if (cron_running === false && !cronScheduled && !isCronExecuting) {
    cronScheduled = true;
    cronTimeoutId = setTimeout(async () => {
      try {
        await executeCronIfFailed();
        await CronHealer();
      } catch (error) {
        console.error("Error in scheduled cron execution", error);
      }
    }, 15000);

  } else if (cron_running === true) {
    if (cronScheduled && cronTimeoutId) {
      clearTimeout(cronTimeoutId);
      cronScheduled = false;
      cronTimeoutId = null;
    }
  }
};

export const fetchLogs = async (
  setLogs,
  setIsCompleted,
  isFirstExecution = true,
  isComponentActiveRef,
  setProgress,
  setIsScanInProgress,
  setIsLogsVisible,
  setRenderKey,
  setRenderBackupDetail,
  setErrorMessages,
  setIsPaused,
  setIsCanceled,
  setOperationLoading, isLastLogIndex, setIsLastLogIndex, setIsFailed
) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_live_logs");
    formData.append("nonce", tailwatch_ajax.nonce);
    const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
    formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    
    const { new_logs, scan_state, cron_running, message, progress, function_completed, last_log_index, completion_timestamp, current_timestamp } = response.data.data;
    

    setIsLastLogIndex(last_log_index);
    setProgress(progress);
    noteBackupProgress(last_log_index, progress);
    if (new_logs.length > 0) {
      setLogs((prevLogs) => [...prevLogs, ...new_logs]);
    }

    if (function_completed && completion_timestamp && current_timestamp) {
      const timeDifference = current_timestamp - completion_timestamp;
      

      if (timeDifference >= 12) {
        
        await CronHealer();
      } else {
        
      }
    }

    // Finished once the status row says so. The log's own flag can lag a step behind, and
    // none of the branches below would ask again.
    if (scan_state === "completed") {
      setProgress(100);
      setIsCompleted(true);
      setRenderKey((prevKey) => prevKey + 1);
      setRenderBackupDetail((prevKey) => prevKey + 1);
    } else if (scan_state === "pause") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsScanInProgress(false);
      setIsPaused(true);
      setOperationLoading(false);
      return;
    } else if (scan_state === "cancel") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsScanInProgress(false);
      setIsLogsVisible(false);
      setIsCanceled(true);
      setIsPaused(false);
      setOperationLoading(false);
      return;
    } else if (scan_state === "failed") {
      // The site gave up on this backup — recovery exhausted its retries, or a tick died
      // somewhere it could not come back from. A backup that failed has to say so, stop
      // the poll and hand the button back.
      //
      // The console STAYS open, unlike a cancel. A cancel is something the operator did
      // and already knows the reason for; a failure is the opposite, and the last few
      // lines above are the only account of it they will get.
      setIsScanInProgress(false);
      setIsPaused(false);
      setOperationLoading(false);
      // Opened, not closed. Reaching this branch on the first round of a reload means the
      // operator has come back to find out what happened, and the tail this call just
      // loaded is the answer; a cancel closes the console because there is nothing in it
      // the operator does not already know.
      setIsLogsVisible(true);
      if (typeof setIsFailed === "function") {
        setIsFailed(true);
      }
      setLogs((prevLogs) => [
        ...prevLogs,
        "The backup stopped and could not be completed. Your previous backups are safe — start a new one when you are ready.",
      ]);
      setRenderKey((prevKey) => prevKey + 1);
      setRenderBackupDetail((prevKey) => prevKey + 1);
      return;
    } else if (scan_state === null) {
      setIsScanInProgress(false);
      setIsLogsVisible(false);
      setErrorMessages((prevErrors) => [
        ...prevErrors,
        "Scan State is undefined",
      ]);
    } else if (scan_state === "in-progress") {
      if (isComponentActiveRef.current) {
        setTimeout(() => {
          fetchLogs(
            setLogs,
            setIsCompleted,
            false,
            isComponentActiveRef,
            setProgress,
            setIsScanInProgress,
            setIsLogsVisible,
            setRenderKey,
            setRenderBackupDetail,
            setErrorMessages,
            setIsPaused,
            setIsCanceled,
            setOperationLoading, last_log_index, setIsLastLogIndex, setIsFailed
          );
        }, 4000);
      } else {
        
      }
    }

    // Only for a run that could still be revived. `failed` belongs with pause and cancel
    // here: the site writes cron_running false on its way into that state and unschedules
    // the worker hooks in the same breath, so a client that reads the flag at face value
    // answers by scheduling the very cron the site just took away — trying to resurrect a
    // backup it deliberately buried.
    if (
      scan_state !== "pause" &&
      scan_state !== "cancel" &&
      scan_state !== "failed"
    ) {
      setTimeout(() => {
        checkCronExecution(message, cron_running);
      }, 15000);
    }

    // Independent of cron_running, and deliberately so: this is the path that catches a
    // worker killed hard enough that it never got to say it had stopped.
    if (scan_state === "in-progress") {
      checkStalledBackup();
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [...prevErrors, "Error Fetching logs"]);
    setIsScanInProgress(false);
    setIsLogsVisible(false);
    setOperationLoading(false);
    console.error("Error Fetching Logs", error);
  }
};
export const fetchFolderFiles = async (
  folder_name,
  id,
  backupFolder,
  setErrorMessages
) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_backup_folder_files");
    formData.append("data", JSON.stringify({ folder_name, id, backupFolder }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });
    if (response.data.data.code === 200 && response.data.data.data) {
      return response.data.data.data;
    } else {
      return [];
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Error Fetching folder Files",
    ]);
    console.error("errorFetching folder files", error);
    return [];
  }
};
/**
 * Raised when something has changed the set of backups on disk.
 *
 * The progress panel and the list of backups are sibling screens with no state between
 * them, and a cancel is handled entirely by the first one. The server prunes the cancelled
 * run's partial folder, so without this the list goes on showing what it showed before
 * until the operator navigates away and back.
 */
export const BACKUP_LIST_STALE_EVENT = "tailwatch:backup-list-stale";

export const fetchBackupFiles = async (
  setErrorMessages,
  setPagination,
  page = 1,
  limit = 10
) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_backup_folders_info");
    formData.append("data", JSON.stringify({ page, limit }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });
    if (response.data.data.code === 200 && response.data.data.data) {
      if (setPagination && response.data.data.pagination) {
        setPagination({
          page: response.data.data.pagination.page,
          limit: response.data.data.pagination.limit,
          total_pages: response.data.data.pagination.total_pages,
          total_items: response.data.data.pagination.total,
        });
      }
      return response.data.data;
    } else {
      return [];
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Error Fetching Backup FIles",
    ]);
    console.error("Error fetching backup files", error);
    return [];
  }
};
export const handleBackupAction = async ({
  scan_state,
  setIsScanInProgress,
  setIsPaused,
  setIsCompleted,
  setLogs,
  setIsLogsVisible,
  setErrorMessages,
}) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_pause_backup_creation");
    formData.append("data", JSON.stringify({ scan_state }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
      setIsLogsVisible(true);

      if (scan_state === "cancel") {
        setIsScanInProgress(false);
        setIsCompleted(true);

        // The run that was cancelled took its half-written folder with it, so whatever the
        // list is showing is now out of date.
        window.dispatchEvent(new Event(BACKUP_LIST_STALE_EVENT));
      } else if (scan_state === "pause") {
        setIsScanInProgress(false);
        setIsPaused(true);
        setLogs((prevLogs) => [...prevLogs, "Backup process paused by user"]);
      }
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "error while cancel or pause backup",
    ]);
    console.error(
      `Error during ${scan_state === "cancel" ? "cancel" : "pause"
      } backup action:`,
      error
    );
  }
};
export const verifyBackupStatus = async (
  setLoading,
  setVerifyStatus,
  setProgress,
  setScanState,
  setScanType,
  setErrorMessages,
  setFeatureEnable,
  setParentEnable,
  setBackupDownloadStatus
) => {
  try {
    setLoading(true);

    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_verify_backup_status");
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    // A reply the gate let through is proof both switches are on. Read from every
    // response, not only refusals, so these flags cannot latch to false and stay there.
    readFeatureGate(response, setFeatureEnable, setParentEnable);


    if (response.data.success === true) {
      const { is_completed, progress, scan_state, scan_type, backup_downloading } = response.data.data;
      setProgress(progress);
      setScanState(scan_state);
      setScanType(scan_type);
      setBackupDownloadStatus(backup_downloading);
      if (is_completed === false) {
        setVerifyStatus(false);
      } else {
        setVerifyStatus(true);
      }
    } else if (response.data.success === false) {
      if (response.data.data) {
        const { feature_enable, parent_enable } = response.data.data;
        setFeatureEnable(feature_enable);
        setParentEnable(parent_enable);
      }
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Error Verify Backup Status",
    ]);
    console.error("Error verifying backup status:", error);
  } finally {
    setLoading(false);
  }
};

export const fetchBackupDetails = async ({
  setBackupDetails,
  setLoading,
  setFeatureEnable,
  setParentEnable,
}) => {
  try {
    setLoading(true);
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_backup_status");
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });
    // A reply the gate let through is proof both switches are on. Read from every
    // response, not only refusals, so these flags cannot latch to false and stay there.
    readFeatureGate(response, setFeatureEnable, setParentEnable);


    if (response.status === 200 && response.data.success) {
      const backupData = response.data.data.data;
      setBackupDetails(backupData);
    } else if (response.data.success === false) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    } else {
      console.error("Backup details do not exist");
    }
  } catch (error) {
    console.error("Error fetching backup details:", error);
  } finally {
    setLoading(false);
  }
};

/**
 * Per-file progress while a backup is being sent to remote storage.
 *
 * The site only asks for this once `backup_downloading` comes back true, and that is
 * decided by the `tailwatch_get_backup_downloading_status` filter. The filter can answer
 * true on an install that has no matching action, so this fails quietly: it returns null
 * rather than throwing, and the backup screen shows no progress column instead of
 * breaking on a missing property.
 */
export const getBackupFilesDownloadStatus = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_backup_files_download_status");
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const payload = response?.data?.data;

    // An action the dispatcher cannot route answers with something that has no `backups`
    // in it. Treated as "nothing to report", never as a shape to read fields off.
    if (!payload || typeof payload !== "object" || !Array.isArray(payload.backups)) {
      return null;
    }

    return payload;
  } catch (error) {
    return null;
  }
};








export const onResumeBackup = async ({
  setIsScanInProgress,
  setIsFetchingLogs,
  checkBackupStatus,
  fetchLogs,
  setLogs,
  setIsCompleted,
  isComponentActive,
  setProgress,
  setIsLogsVisible,
  setRenderKey,
  setRenderBackupDetail,
  setErrorMessages,
  setIsPaused,
  setIsCanceled,
  setOperationLoading,
  isLastLogIndex,
  setIsLastLogIndex,
  setIsFailed,
}) => {
  const formData = new FormData();
  formData.append("action", "tailwatch_global_ajax_handler");
  formData.append("action_type", "tailwatch_resume_backup");
  formData.append("nonce", tailwatch_ajax.nonce);

  const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  if (response.data.success) {
    setIsScanInProgress(true);
    setIsFetchingLogs(true);
    // A resumed run is live again, so last time's failure stops being the headline.
    if (typeof setIsFailed === "function") {
      setIsFailed(false);
    }
    await checkBackupStatus();
    fetchLogs(
      setLogs,
      setIsCompleted,
      true,
      isComponentActive,
      setProgress,
      setIsScanInProgress,
      setIsLogsVisible,
      setRenderKey,
      setRenderBackupDetail,
      setErrorMessages,
      setIsPaused,
      setIsCanceled,
      setOperationLoading,
      isLastLogIndex,
      setIsLastLogIndex,
      setIsFailed
    );
  } else {
    setIsScanInProgress(false);
    setIsLogsVisible(false);
    const payload = response.data?.data;
    if (await warnIfBlocked(payload)) {
      return;
    }
    // The run is not paused any more (finished, running, or gone): say so, and show what it is now.
    if (payload?.code === 409) {
      await alertService.warning(payload.message || "There is no paused backup to resume.", "Nothing to Resume");
      await checkBackupStatus();
      return;
    }
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Failed to resume the backup:",
    ]);
  }
};





export const deleteBackupFolder = async ({
  deleteData,
  pagination,
  currentPage,
  pageSize,
  setDeletingFileId,
  setCurrentPage,
  loadBackupFiles,
}) => {
  const { ids, is_delete } = deleteData;
  setDeletingFileId(ids.length > 0 ? ids[0] : null);

  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_backup_folder");
    formData.append("data", JSON.stringify({ ids, is_delete }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

     if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';      
      await alertService.warning(message, 'Process in Progress');     
      return;
    }

    const result = response.data.data;

    if (result.code === 200) {
      toast.success("Files Deleted Successfully");
      if (is_delete) {
        setCurrentPage(0);
      } else {
        const remainingItems = pagination.total_items - ids.length;
        const maxPage = Math.ceil(remainingItems / pageSize) - 1;
        if (currentPage > maxPage && maxPage >= 0) {
          setCurrentPage(maxPage);
        }
      }
      loadBackupFiles();
      return;
    }

    // A backup can be refused rather than merely fail: an incremental backup that a
    // newer one is built on is kept, because deleting it would leave that newer backup
    // unrestorable. That is a decision the operator has to see the reason for, so the
    // per-backup reasons are shown instead of a generic failure.
    const reasons = Array.isArray(result.data?.failed_messages)
      ? result.data.failed_messages
      : [];

    if (reasons.length > 0) {
      await alertService.warning(
        reasons.join("\n\n"),
        result.code === 207 ? "Some backups were kept" : "Nothing was deleted"
      );
    } else {
      toast.error(result.message || "Failed to delete files");
    }

    // A partial delete still changed the list.
    if (result.code === 207) {
      loadBackupFiles();
    }
  } catch (error) {
    console.error("Error deleting backup:", error);
  } finally {
    setDeletingFileId(null);
  }
};