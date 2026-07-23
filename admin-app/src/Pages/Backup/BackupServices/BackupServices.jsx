import axios from "axios";
import { toast } from "react-toastify";
import { alertService } from "../../../Components/AlertService/AlertService";
import { CronHealer } from "../../../Components/CroneHealer/CronHealer";
/* global wptw_ajax */

export const instantScanning = async (
  setScanCompleted,
  setErrorMessages,
  fetchLogs
) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_instant_backup_scanner");
    formData.append("nonce", wptw_ajax.nonce);
    formData.append("data", JSON.stringify({ instant_scan: true }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      const runningProcess = response.data?.data?.data?.running_process;
      await alertService.warning(message, 'Process in Progress');
      if (runningProcess === 'backup' && typeof fetchLogs === 'function') {
        setScanCompleted(true);
        fetchLogs();
        return true;
      }
      return;
    }
    const { database_optimize, optimize_skip } = response.data.data;

    // Case 1:database_optimize, optimize_skip are true
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

      // new call execute
      const newFormData = new FormData();
      newFormData.append("action", "wptw_global_ajax_handler");
      newFormData.append(
        "action_type",
        "wptw_start_backup_with_optimize_or_not"
      );
      newFormData.append("nonce", wptw_ajax.nonce);
      newFormData.append(
        "data",
        JSON.stringify({ optimize_skip: optimizeSkipValue })
      );
      const newResponse = await axios.post(wptw_ajax.ajax_url, newFormData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      const { optimize_skip: newInstantScan } = newResponse.data.data;

      // if optimize_skip return true or false then execute this case
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
    // Case 3: instant_scan is false, but database_optimize or optimize_skip is true
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
          "wptw_instant_backup error",
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

const executeCronIfFailed = async () => {
  if (isCronExecuting) {
    return;
  }
  try {
    isCronExecuting = true;

    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_execute_cron_if_failed");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
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
  setOperationLoading, isLastLogIndex, setIsLastLogIndex
) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_live_logs");
    formData.append("nonce", wptw_ajax.nonce);
    const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
    formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    
    const { new_logs, is_completed, scan_state, cron_running, message, progress, function_completed, last_log_index, completion_timestamp, current_timestamp } = response.data.data;
    

    setIsLastLogIndex(last_log_index);
    setProgress(progress);
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

    if (is_completed && scan_state === "completed") {
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
            setOperationLoading, last_log_index, setIsLastLogIndex
          );
        }, 4000);
      } else {
        
      }
    }

    if (scan_state !== "pause" && scan_state !== "cancel") {
      setTimeout(() => {
        checkCronExecution(message, cron_running);
      }, 15000);
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
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_backup_folder_files");
    formData.append("data", JSON.stringify({ folder_name, id, backupFolder }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });
    if (response.data.data.code === 200 && response.data.data.data) {
      return response.data.data.data;
    } else {
      // setErrorMessages((prevErrors)=>[...prevErrors, response.data.data.message ]);
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
export const fetchBackupFiles = async (
  setErrorMessages,
  setPagination,
  page = 1,
  limit = 10
) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_backup_folders_info");
    formData.append("data", JSON.stringify({ page, limit }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
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
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_pause_backup_creation");
    formData.append("data", JSON.stringify({ scan_state }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
      setIsLogsVisible(true);

      if (scan_state === "cancel") {
        setIsScanInProgress(false);
        setIsCompleted(true);
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
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_verify_backup_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

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
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_backup_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

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

export const getBackupFilesDownloadStatus = async () => {

  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_backup_files_download_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    return response.data.data;
  } catch (error) {
    throw error;
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
}) => {
  const formData = new FormData();
  formData.append("action", "wptw_global_ajax_handler");
  formData.append("action_type", "wptw_resume_backup");
  formData.append("nonce", wptw_ajax.nonce);

  const response = await axios.post(wptw_ajax.ajax_url, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  if (response.data.success) {
    setIsScanInProgress(true);
    setIsFetchingLogs(true);
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
      setIsLastLogIndex
    );
  } else {
    setErrorMessages((prevErrors) => [
      ...prevErrors,
      "Failed to resume the backup:",
    ]);
    setIsScanInProgress(false);
    setIsLogsVisible(false);
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
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_delete_backup_folder");
    formData.append("data", JSON.stringify({ ids, is_delete }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

     if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';      
      await alertService.warning(message, 'Process in Progress');     
      return;
    }

    if (response.data.data.code === 200) {
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
    } else {
      console.error("Failed to delete backup:", response.data.data.message);
      toast.error("Failed to delete files");
    }
  } catch (error) {
    console.error("Error deleting backup:", error);
  } finally {
    setDeletingFileId(null);
  }
};