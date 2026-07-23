import axios from 'axios';
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer';
import { alertService } from "../../../Components/AlertService/AlertService";

/* global wptw_ajax */
const EMPTY_OPTIMIZER_STATUS = {
  steps: {},
  schedule: null,
  optimizerEnabled: null,
  licenseConnected: false,
  proPluginActive: false,
  currentPlan: null,
};

export const getDbOptimizerStatus = async () => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_db_optimizer_status');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    const payload = response.data?.data;
    if (payload?.code === 200) {
      return {
        steps: payload.steps || {},
        schedule: payload.schedule || null,
        optimizerEnabled: payload.optimizer_enabled ?? null,
        licenseConnected: !!payload.license_connected,
        proPluginActive: !!payload.pro_plugin_active,
        currentPlan: payload.current_plan || null,
      };
    }

    console.error('Failed to retrieve DB optimizer status:', payload?.message);
    return EMPTY_OPTIMIZER_STATUS;
  } catch (error) {
    console.error('Error fetching DB optimizer status:', error);
    return EMPTY_OPTIMIZER_STATUS;
  }
};


export const instantScanning = async (setScanCompleted, fetchLogs) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_database_optimize");
    formData.append("nonce", wptw_ajax.nonce);
    formData.append("data", JSON.stringify({ instant_scan: true }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      const runningProcess = response.data?.data?.data?.running_process;
      await alertService.warning(message, 'Process in Progress');
      if (runningProcess === 'db_optimize' && typeof fetchLogs === 'function') {
        setScanCompleted(true);
        fetchLogs();
        return true;
      }
      return;
    }

    const message = response.data?.data?.message || "";

    if (response.status === 200 && response.data.success && message.toLowerCase().includes("optimization started")) {
      setScanCompleted(true);
      fetchLogs?.();
      return true;
    }

    if (message.toLowerCase().includes("cron job could not be scheduled")) {
      toast.error("Optimization process failed. Please try again later");
    } else if (message.toLowerCase().includes("already scheduled")) {
      toast.error("Optimization already Scheduled");
    } else {
      console.error("Optimization error:", message);
    }

    return false;



  }
  catch (error) {
    console.error("Error during scan", error);
    return false
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
    formData.append("action_type", "wptw_db_optimization_cron_if_failed");
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

export const fetchLogs = async (setLogs, setIsCompleted, isFirstExecution = true, isComponentActiveRef, setIsLogsVisible, setIsScanInProgress, setProgress, setRenderKey, setIsPausing, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_optimize_live_logs");
    formData.append("nonce", wptw_ajax.nonce);

    const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
    formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const { new_logs, is_completed, cron_running, scan_state, message, last_log_index, progress, function_completed, completion_timestamp, current_timestamp } = response.data.data;
    setIsLastLogIndex(last_log_index);
    setProgress(progress);
    if (new_logs.length > 0) {
      setLogs((prevLogs) => [...prevLogs, ...new_logs]);
    }

    // Check if cronHealer should be executed
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

    } else if (scan_state === "pause") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsPausing(true);
      setIsPaused(true);
      setIsScanInProgress(false);
      setOperationLoading(false);
      return;

    } else if (scan_state === "cancel") {
      toast.success(`Scan is ${scan_state}, stopping further log fetching.`);
      setIsCanceled(true);
      setIsScanInProgress(false);
      setIsLogsVisible(false);
      setIsPaused(false);
      setOperationLoading(false);
      return;

    } else if (scan_state === null) {
      setIsScanInProgress(false);
      setIsLogsVisible(false);
    }
    else if (is_completed === false) {
      if (isComponentActiveRef.current) {
        setTimeout(() => {
          fetchLogs(
            setLogs,
            setIsCompleted,
            false,
            isComponentActiveRef,
            setIsLogsVisible,
            setIsScanInProgress,
            setProgress,
            setRenderKey,
            setIsPausing,
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
        checkCronExecution(message, cron_running);
      }, 15000);
    }
  } catch (error) {
    setIsLogsVisible(false);
    setIsScanInProgress(false);
    setOperationLoading(false);
  }
};

export const verifyDatabaseOptimizeStatus = async ({ setProgress, setScanState, setProcessType, setScanType, setVerifyStatus, setVerifyLoading, setFeatureEnable, setParentEnable }) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_verify_db_optimize_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const { is_completed, progress, scan_state, scan_type, process_run } = response.data.data;
    setProgress(progress);
    setScanState(scan_state);
    setProcessType(process_run);
    setScanType(scan_type);

    if (is_completed === false) {
      setVerifyStatus(false);
    } else {
      setVerifyStatus(true);
    }

    if (response.data.success === false) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    }

  } catch (error) {
  } finally {
    setVerifyLoading(false);
  }
};

export const onPauseOrCancel = async ({ scan_state, setIsLogsVisible, setLogs, setIsScanInProgress, setIsCompleted, setIsPaused, setErrorMessages }) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_pause_db_optimize");
    formData.append("data", JSON.stringify({ scan_state }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
      setIsLogsVisible(true);

      if (scan_state === "cancel") {
        setLogs([]);
        setIsScanInProgress(false);
        setIsCompleted(true);
        setIsLogsVisible(false);
      } else if (scan_state === "pause") {
        setIsScanInProgress(false);
        setIsPaused(true);
        setLogs((prevLogs) => [...prevLogs, "The database optimization process has been paused successfully."]);
      }
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [...prevErrors, "Error while do cancel and pause"]);
    console.error(`Error during ${scan_state === 'cancel' ? 'cancel' : 'pause'} optimize action:`, error);
  }
};

export const checkOptimizeStatus = async ({ setCheckTableStatus }) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_check_db_optimization_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    

    if (response.data.data.code === 200) {
      setCheckTableStatus(response.data.data.is_disabled);
    }
    else {
      console.error("Failed to retrieve optimization status:", response.data.data.message);
    }

  } catch (error) {
  }
};