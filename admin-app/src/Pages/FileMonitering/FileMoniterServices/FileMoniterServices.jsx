import axios from "axios";
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer'
import { alertService } from "../../../Components/AlertService/AlertService";
/* global wptw_ajax */

export const fetchFileLogs = async (setLoading, setTabData, setPagination, page = 1, limit = 10) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_file_logs_data");
    formData.append('data', JSON.stringify({ page, limit }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
      setTabData(response.data.data.data);
      if (setPagination && response.data.data.pagination) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_items: response.data.data.pagination.total });
      }
    } else {
      setTabData([]);
    }
  } catch (error) {
    if (!axios.isCancel(error)) {
      console.error("Error fetching file logs data:", error);
    }
    setTabData([]);
  }
  setLoading(false);
};

export const instantScanning = async (setScanCompleted, setStatusMessage, setIsDisabled, checkMonitoringStatus, fetchLogs, setIsMonitoring) => {
  try {
    setIsMonitoring(true);
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_instant_files_integrity_check");
    formData.append("nonce", wptw_ajax.nonce);
    formData.append("data", JSON.stringify({ instant_scan: true }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      const runningProcess = response.data?.data?.data?.running_process;
      await alertService.warning(message, 'Process in Progress');
      if (runningProcess === 'files_integrity' && typeof fetchLogs === 'function') {
        setScanCompleted(true);
        fetchLogs();
        return true;
      }
      return;
    }

    if (response.data?.data?.comparison === false) {
      setStatusMessage('Current status scan is still in progress. Please wait for it to complete before proceeding.');
      setTimeout(checkMonitoringStatus, 2000);
      setIsDisabled(true);
      return;
    } else if (response.data?.data?.comparison === true) {
      setScanCompleted(true);
      fetchLogs();
      return true;
    }
    if (response.data?.data?.message === "Failed to run Files Integrity: instant_scan is false.") {
      toast.error("Failed to run File Integrity. Please try again later");
    } else {
      console.error("File Integrity error");
    }
    return false;
  } catch (error) {
    console.error("error During Scan", error);
    return false;
  } finally {
    setIsMonitoring(false);;
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
    formData.append("action_type", "wptw_files_integrity_cron_if_failed");
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

const checkCronExecution = async (cron_running) => {

  if (cron_running === false && !cronScheduled && !isCronExecuting) {
    
    cronScheduled = true;
    cronTimeoutId = setTimeout(async () => {
      try {
        await executeCronIfFailed();
        await CronHealer(); // Call the CroneHealer function
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
  setIsScanInProgress,
  setIsLogsVisible,
  setProgress,
  setRenderKey,
  setTrigerMoniteringDetails,
  setIsPaused,
  setIsCanceled,
  setOperationLoading,
  isLastLogIndex, setIsLastLogIndex
) => {

  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_files_integrity_comparison_logs");
    formData.append("nonce", wptw_ajax.nonce);
    const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
    formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const { new_logs, is_completed, scan_state, cron_running, progress, function_completed, last_log_index, completion_timestamp, current_timestamp } = response.data.data;
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

    if (is_completed) {
      setIsCompleted(true);
      setRenderKey((prevKey) => prevKey + 1);
      setTrigerMoniteringDetails((prevKey) => prevKey + 1);
    }

    else if (scan_state === "pause") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsScanInProgress(false);
      setIsPaused(true);
      setOperationLoading(false);
      return;
    }
    else if (scan_state === "cancel") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsCanceled(true);
      setIsScanInProgress(false);
      setIsLogsVisible(false);
      setIsPaused(false);
      setOperationLoading(false);
      return;
    }

    else if (scan_state === null) {
      setIsScanInProgress(false);
      setIsLogsVisible(false);
      setOperationLoading(false);
      return;
    }

    else if (is_completed === false) {
      if (isComponentActiveRef.current) {
        setTimeout(() => {
          fetchLogs(
            setLogs,
            setIsCompleted,
            false,
            isComponentActiveRef,
            setIsScanInProgress,
            setIsLogsVisible,
            setProgress,
            setRenderKey,
            setTrigerMoniteringDetails,
            setIsPaused,
            setIsCanceled,
            setOperationLoading,
            last_log_index, setIsLastLogIndex
          );
        }, 4000);
      } else {
        
      }
    }

    if (scan_state !== "pause" && scan_state !== "cancel") {
      setTimeout(() => {
        checkCronExecution(cron_running);
      }, 15000);
    }

  } catch (error) {
    console.error("Error fetching logs", error);
    setIsScanInProgress(false);
    setIsLogsVisible(false);
    setOperationLoading(false);
  }
};

export const handleDelete = async (deleteData, setTabData, setDeletingFileId) => {
  const { ids, is_delete } = deleteData;
  setDeletingFileId(ids.length > 0 ? ids[0] : null); // Set first ID or null for bulk
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_delete_comparison_by_id");
    formData.append("data", JSON.stringify({ ids, is_delete }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      const runningProcess = response.data?.data?.data?.running_process;
      await alertService.warning(message, 'Process in Progress');      
      return;
    }

    if (response.data.data.code === 200) {
      setTabData((prevFiles) => {
        if (is_delete) {
          return []; // Delete all files
        } else {
          return prevFiles.filter((file) => !ids.includes(file.ids));
        }
      });
      toast.success("Files deleted successfully");
    } else {
      console.error("Failed to delete file:", response.data.data.message);
      toast.error("Failed to delete files");
    }
  } catch (error) {
    console.error("Error deleting file:", error);
  } finally {
    setDeletingFileId(null);
  }
};

export const checkMalwareScanner = async (setIsScannerEnabled, setLoadingStrip, setScanError = null) => {
  setLoadingStrip(true);
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_malware_scanner_changes_detection");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    if (response.data.success) {
      const { is_enabled, changes_detected, quota_blocked, quota_reason, message } = response.data.data;
      setIsScannerEnabled(is_enabled === true && changes_detected === true);

      // Quota gate — backend returns success:true but signals scanning was blocked.
      // Surface the reason in the same ScanErrorModal the malware scanner uses.
      if (quota_blocked === true && setScanError) {
        const fallback = 'Malware scanning is currently blocked. Please try again later.';
        const errorType = quota_reason === 'scanning_disabled' ? 'SCANNING_DISABLED' : 'SCAN_LIMIT_REACHED';
        setScanError({
          type: errorType,
          message: message || fallback,
          details: '',
          canRetry: false,
          timestamp: new Date().toISOString()
        });
      }
    } else {
      // toast.success("Failed to get Malware Scanner status:", response.data.data.message);
    }
  } catch (error) {
  }
  setLoadingStrip(false);
}

export const fetchLogData = async (setLogData, setLoading, setError, id, page = 1, limit = 5, file_status = 'all') => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_files_log_by_id');
    formData.append('data', JSON.stringify({
      id: parseInt(id, 10),
      page: page,
      limit: limit,
      file_status: file_status
    }));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    });

    

    if (response.data.success) {
      setLogData(response.data.data);
    } else {
      setError('Failed to fetch log data');
    }
  } catch (error) {
    setError('Error fetching log data');
  }
  setLoading(false);
};

