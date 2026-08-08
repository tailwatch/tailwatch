import axios from 'axios';
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

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
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_malware_scanner_cron_if_failed");
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
    }, 30000);

  } else if (cron_running === true) {
    

    if (cronScheduled && cronTimeoutId) {
      
      clearTimeout(cronTimeoutId);
      cronScheduled = false;
      cronTimeoutId = null;
    }
  }
};

export const instantScanning = async (setScanCompleted, setErrorMessages, fetchLogs, scanOptions = {}, setScanError = null) => {
  try {
    // Handle backward compatibility - if scanOptions is a boolean, treat it as dry_run
    const options = typeof scanOptions === 'boolean'
      ? { dry_run: scanOptions, files_backup: true, database_backup: false, website_restore: false }
      : {
        dry_run: scanOptions.dry_run ?? false,
        files_backup: scanOptions.files_backup ?? true,
        database_backup: scanOptions.database_backup ?? false,
        website_restore: scanOptions.website_restore ?? false
      };

    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_instant_malware_scanner");
    formData.append("nonce", tailwatch_ajax.nonce);
    formData.append("data", JSON.stringify({
      instant_scan: true,
      dry_run: options.dry_run,
      files_backup: options.files_backup,
      database_backup: options.database_backup,
      website_restore: options.website_restore
    }));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      const runningProcess = response.data?.data?.data?.running_process;
      await alertService.warning(message, 'Process in Progress');
      if (runningProcess === 'malware_scan' && typeof fetchLogs === 'function') {
        setScanCompleted(true);
        fetchLogs();
        return true;
      }
      return;
    }

    if (response.data?.data?.code === 429 && response.data?.data?.data?.error_code === 'scan_limit_reached') {
      const message = response.data?.data?.message || 'You have reached your scan limit for this window. Please try again later.';
      if (setScanError) {
        setScanError({
          type: 'SCAN_LIMIT_REACHED',
          message,
          details: '',
          canRetry: false,
          timestamp: new Date().toISOString()
        });
      }
      return false;
    }

    if (response.data?.data?.code === 403 && response.data?.data?.data?.error_code === 'scanning_disabled') {
      const message = response.data?.data?.message || 'Malware scans are currently disabled for your account. Contact support if you believe this is an error.';
      if (setScanError) {
        setScanError({
          type: 'SCANNING_DISABLED',
          message,
          details: '',
          canRetry: false,
          timestamp: new Date().toISOString()
        });
      }
      return false;
    }

    if (response.data.data.data.instant_scan === true) {
      setScanCompleted(true);
      fetchLogs();
      return true;
    } else {
      if (response.data.data.message === "Failed to run Malware Scanner process: instant_scan is false.") {
        setErrorMessages((prevErrors) => [...prevErrors, ` ${response.data.data.message} `]);
      } else {
        setErrorMessages((prevErrors) => [...prevErrors, 'tailwatch_instant_malware_scanner error']);
      }
      return false;
    }
  } catch (error) {
    setErrorMessages((prevErrors) => [...prevErrors, "Error in start the Malware Scanner Process Network Error"]);
    return false;
  }
};

// ============================================================================
// HELPER FUNCTIONS FOR fetchLogs
// ============================================================================

/**
 * Builds the FormData and config for fetching scan logs
 * @param {number} lastLogIndex - The index of the last log received
 * @returns {object} Object containing formData and requestConfig
 */
const buildScanLogsRequest = (lastLogIndex) => {
  const formData = new FormData();
  formData.append("action", "tailwatch_global_ajax_handler");
  formData.append("action_type", "tailwatch_get_malware_scan_live_logs");
  formData.append("nonce", tailwatch_ajax.nonce);
  formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

  const requestConfig = {
    headers: { "Content-Type": "multipart/form-data" },
    timeout: 30000, // 30 second timeout
  };

  return { formData, requestConfig };
};

/**
 * Parses response data from the server, handling mixed HTML/JSON responses
 * @param {object} response - Axios response object
 * @returns {object} Object with success flag and parsed data or error
 */
const parseResponseData = (response) => {
  // Helper function to extract JSON from mixed HTML/JSON responses using brace counting
  const findJsonInResponse = (responseText) => {
    const successIndex = responseText.indexOf('{"success":');
    if (successIndex === -1) return null;

    let braceCount = 0;
    let jsonStart = successIndex;
    let jsonEnd = -1;

    for (let i = jsonStart; i < responseText.length; i++) {
      const char = responseText[i];
      if (char === '{') {
        braceCount++;
      } else if (char === '}') {
        braceCount--;
        if (braceCount === 0) {
          jsonEnd = i;
          break;
        }
      }
    }

    if (jsonEnd !== -1) {
      return responseText.substring(jsonStart, jsonEnd + 1);
    }
    return null;
  };

  // Validate response structure
  if (!response || !response.data) {
    return { success: false, error: 'Empty response received from server' };
  }

  // Check for backend failure
  if (response.data.success === false) {
    const errorMsg = response.data.message || "Fetch logs call response is not correct";

    if (response.data.data?.critical) {
      return {
        success: false,
        error: errorMsg,
        critical: true,
        details: response.data.data?.details || ''
      };
    }

    // Non-critical error, log but continue
    console.warn("Backend returned success=false:", errorMsg);
  }

  let parsedData;

  if (typeof response.data === 'object') {
    parsedData = response.data;
  } else if (typeof response.data === 'string') {
    // Check for HTML errors in the response (log them but don't stop processing)
    const htmlErrorMatches = response.data.matchAll(/<div[^>]*id=["']error["'][^>]*>([\s\S]*?)<\/div>/gi);
    const errorMessages = [];

    for (const match of htmlErrorMatches) {
      const errorMessage = match[1]
        .replace(/<[^>]+>/g, "")
        .replace(/&#039;/g, "'")
        .trim();
      if (errorMessage) {
        errorMessages.push(errorMessage);
      }
    }

    if (errorMessages.length > 0) {
      console.warn("HTML Errors detected in response (processing will continue):", errorMessages);
    }

    // Use robust JSON extraction with brace counting
    const jsonString = findJsonInResponse(response.data);

    if (jsonString) {
      try {
        parsedData = JSON.parse(jsonString);
      } catch (parseError) {
        console.error("Failed to parse extracted JSON:", parseError);
        parsedData = null;
      }
    } else {
      // Fallback to original regex method
      const jsonMatch = response.data.match(/\{.*\}$/s);
      if (jsonMatch) {
        try {
          parsedData = JSON.parse(jsonMatch[0]);
        } catch (parseError) {
          console.error("Failed to parse JSON with fallback method:", parseError);
          parsedData = null;
        }
      } else {
        parsedData = null;
      }
    }
  }

  // Validate parsed data
  if (!parsedData || !parsedData.data) {
    return { success: false, error: 'Unable to parse response data' };
  }

  return { success: true, data: parsedData.data };
};

/**
 * Classifies HTTP errors into user-friendly error types
 * @param {Error} error - Axios error object
 * @returns {object} Object containing errorType and errorDetails
 */
const classifyHttpError = (error) => {
  let errorType = 'UNKNOWN_ERROR';
  let errorDetails = {
    message: 'An unexpected error occurred',
    details: ''
  };

  if (error.code === 'ECONNABORTED' || error.message.includes('timeout')) {
    errorType = 'TIMEOUT_ERROR';
    errorDetails = {
      message: 'Request timed out while fetching scan logs',
      details: 'The server took too long to respond. This may indicate server overload or network issues.'
    };
  } else if (error.response) {
    // Server responded with error
    const status = error.response.status;

    if (status === 500) {
      errorType = 'SERVER_ERROR';
      errorDetails = {
        message: 'Internal server error (500)',
        details: error.response.data?.message || 'The server encountered an error processing the scan.'
      };
    } else if (status === 503) {
      errorType = 'SERVICE_UNAVAILABLE';
      errorDetails = {
        message: 'Service temporarily unavailable (503)',
        details: 'The server is temporarily unable to handle the request. Please try again later.'
      };
    } else if (status === 403 || status === 401) {
      errorType = 'AUTH_ERROR';
      errorDetails = {
        message: 'Authentication failed',
        details: 'Your session may have expired. Please refresh the page and try again.'
      };
    } else {
      errorType = 'HTTP_ERROR';
      errorDetails = {
        message: `HTTP Error ${status}`,
        details: error.response.data?.message || error.message
      };
    }
  } else if (error.request) {
    // Request made but no response
    errorType = 'NETWORK_ERROR';
    errorDetails = {
      message: 'Network connection failed',
      details: 'Unable to reach the server. Please check your internet connection.'
    };
  } else {
    // Something else happened. Do NOT surface error.stack to the UI — it leaks internal
    // paths/function names. Keep the stack in the console for debugging only.
    if (error?.stack) {
      console.error('Scan error:', error.stack);
    }
    errorDetails = {
      message: error.message || 'Unknown error occurred',
      details: 'An unexpected error occurred. Please try again.'
    };
  }

  return { errorType, errorDetails };
};

/**
 * Updates all scan-related state from response data
 * @param {object} data - Parsed scan data from server
 * @param {object} setters - Object containing all state setter functions
 */
const updateScanProgress = (data, setters) => {
  const {
    last_log_index,
    progress,
    new_logs
  } = data;

  const {
    setIsLastLogIndex,
    setProgress,
    setLogs
  } = setters;

  setIsLastLogIndex(last_log_index);
  setProgress(progress);

  if (new_logs && new_logs.length > 0) {
    setLogs((prevLogs) => [...prevLogs, ...new_logs]);
  }
};

/**
 * Determines the next action based on scan state
 * @param {string} scanState - Current scan state
 * @param {boolean} isCompleted - Whether scan is completed
 * @param {string} message - Message from server
 * @returns {object} Object with action type and payload
 */
const determineNextAction = (scanState, isCompleted, message, extraParams = {}) => {
  if (isCompleted && scanState === "completed") {
    return { action: 'COMPLETE' };
  }

  if (scanState === "cancel" || scanState === "cancelled" || scanState === "canceled") {
    return {
      action: 'CANCEL',
      message: extraParams?.cancel_reason || message || 'The scan was cancelled.',
    };
  }

  if (scanState === null || scanState === undefined) {
    return {
      action: 'FAIL',
      errorType: 'INVALID_STATE',
      message: 'Scan state is undefined or null',
      details: 'The scanner encountered an unexpected state. This may indicate a backend error.'
    };
  }

  if (scanState === "failed" || scanState === "error") {
    return {
      action: 'FAIL',
      errorType: 'SCAN_FAILED',
      message: message || 'The scan process failed on the server',
      details: 'Check the server logs for more information about this failure.'
    };
  }

  if (scanState === "in-progress" || isCompleted === false) {
    return { action: 'CONTINUE' };
  }

  return { action: 'NONE' };
};

// ============================================================================
// MAIN fetchLogs FUNCTION
// ============================================================================

export const fetchLogs = async (
  setLogs,
  setIsCompleted,
  isFirstExecution = true,
  isComponentActiveRef,
  setProgress,
  setIsScanInProgress,
  setIsLogsVisible,
  setErrorMessages,
  setTrigerScannedDetails,
  setMalwareStarted,
  isLastLogIndex,
  setIsLastLogIndex,
  setScanError = null,
  setIsCanceled = null,
  setCancelMessage = null,
  maxRetries = 6,
  attempt = 1
) => {
  const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

  // Helper function to handle scan failure
  const handleScanFailure = (errorType, errorDetails, shouldRetry = false) => {
    console.error(`[Scan Error - Attempt ${attempt}/${maxRetries}]`, errorType, errorDetails);

    setIsScanInProgress(false);
    setIsLogsVisible(false);
    setMalwareStarted(false);

    if (setScanError) {
      setScanError({
        type: errorType,
        message: errorDetails.message || errorDetails,
        details: errorDetails.details || '',
        canRetry: shouldRetry,
        attempt: attempt,
        maxRetries: maxRetries,
        timestamp: new Date().toISOString()
      });
    }

    // Still log to error messages for debugging
    setErrorMessages((prevErrors) => [...prevErrors, `[${errorType}] ${errorDetails.message || errorDetails}`]);
  };

  try {
    // 1. Build request
    const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
    const { formData, requestConfig } = buildScanLogsRequest(lastLogIndex);

    // 2. Make API request
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, requestConfig);

    // 3. Parse response
    const parseResult = parseResponseData(response);

    // Handle critical backend errors
    if (!parseResult.success && parseResult.critical) {
      handleScanFailure('BACKEND_ERROR', {
        message: parseResult.error,
        details: parseResult.details || ''
      }, false);
      return;
    }

    // Handle non-critical backend errors (log but continue)
    if (!parseResult.success && parseResult.error && !parseResult.critical) {
      setErrorMessages((prevErrors) => [...prevErrors, parseResult.error]);
    }

    // 4. Handle parse failures with retry
    if (!parseResult.success) {
      if (attempt < maxRetries) {
        await delay(20000);
        return fetchLogs(
          setLogs, setIsCompleted, false, isComponentActiveRef,
          setProgress, setIsScanInProgress, setIsLogsVisible,
          setErrorMessages, setTrigerScannedDetails, setMalwareStarted,
          isLastLogIndex, setIsLastLogIndex, setScanError,
          setIsCanceled, setCancelMessage,
          maxRetries, attempt + 1
        );
      } else {
        handleScanFailure('PARSE_ERROR', {
          message: 'Unable to parse server response after multiple attempts',
          details: 'The server response format is invalid or corrupted. Please check server logs.'
        }, true);
        return;
      }
    }

    // 5. Successfully parsed - extract data
    const data = parseResult.data;
    const {
      is_completed,
      scan_state,
      cron_running,
      message,
      function_completed,
      completion_timestamp,
      current_timestamp,
      extra_params
    } = data;

    // 6. Update all scan progress state
    const setters = {
      setIsLastLogIndex,
      setProgress,
      setLogs
    };
    updateScanProgress(data, setters);

    // 7. Check if cron healing is needed
    if (function_completed && completion_timestamp && current_timestamp) {
      const timeDifference = current_timestamp - completion_timestamp;

      if (timeDifference >= 12) {
        await CronHealer();
      }
    }

    // 8. Determine next action based on scan state
    const nextAction = determineNextAction(scan_state, is_completed, message, extra_params);

    switch (nextAction.action) {
      case 'COMPLETE':
        setIsCompleted(true);
        setTrigerScannedDetails((prevKey) => prevKey + 1);
        setMalwareStarted(false);
        break;

      case 'CANCEL':
        // Scan was cancelled server-side (e.g. retry budget exhausted).
        // Tear down the in-progress UI, stop polling, and surface the cancel reason
        // inline via the LogsScreen cancel banner (NOT the modal — the cancel reason
        // belongs on the main screen, similar to the File Monitoring cancel UX).
        setIsCompleted(true);
        setIsScanInProgress(false);
        setMalwareStarted(false);
        setTrigerScannedDetails((prevKey) => prevKey + 1);
        if (setIsCanceled) setIsCanceled(true);
        if (setCancelMessage) setCancelMessage(nextAction.message);
        break;

      case 'FAIL':
        handleScanFailure(nextAction.errorType, {
          message: nextAction.message,
          details: nextAction.details
        }, true);
        break;

      case 'CONTINUE':
        if (isComponentActiveRef.current) {
          await delay(4000);
          fetchLogs(
            setLogs, setIsCompleted, false, isComponentActiveRef,
            setProgress, setIsScanInProgress, setIsLogsVisible,
            setErrorMessages, setTrigerScannedDetails, setMalwareStarted,
            data.last_log_index, setIsLastLogIndex, setScanError,
            setIsCanceled, setCancelMessage,
            maxRetries, 1 // Reset attempt counter for ongoing scans
          );
        }
        break;

      case 'NONE':
        // No action needed
        break;
    }

    // 9. Schedule cron execution check if needed
    if (scan_state !== "pause" && scan_state !== "cancel") {
      setTimeout(() => {
        checkCronExecution(message, cron_running);
      }, 30000);
    }

  } catch (error) {
    console.error(`[fetchLogs Error - Attempt ${attempt}/${maxRetries}]`, error);

    // 10. Classify the error
    const { errorType, errorDetails } = classifyHttpError(error);
    setErrorMessages((prevErrors) => [...prevErrors, `${errorType}: ${errorDetails.message}`]);

    // 11. Retry logic
    if (attempt < maxRetries) {
      const retryDelay = 20000; // Fixed 20 second delay between all retry attempts
      await delay(retryDelay);

      return fetchLogs(
        setLogs, setIsCompleted, false, isComponentActiveRef,
        setProgress, setIsScanInProgress, setIsLogsVisible,
        setErrorMessages, setTrigerScannedDetails, setMalwareStarted,
        isLastLogIndex, setIsLastLogIndex, setScanError,
        setIsCanceled, setCancelMessage,
        maxRetries, attempt + 1
      );
    } else {
      // Max retries reached
      handleScanFailure(errorType, {
        message: `${errorDetails.message} (Failed after ${maxRetries} attempts)`,
        details: errorDetails.details
      }, true);
    }
  }
};

export const fetchMalwareData = async (setLoading, setMalwareData, page = 1, limit = 10, setPagination, setIsLicenseConnect, setIsLicenseReq) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_malware_scanner_report_summary");
    formData.append("nonce", tailwatch_ajax.nonce);
    formData.append("data", JSON.stringify({ page, limit }));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
      setMalwareData(response.data.data.data);
      if (setPagination && response.data.data.pagination) {
        setPagination(response.data.data.pagination);
      }
    } else {
      setMalwareData([]);
    }
  } catch (error) {
    if (error.response?.data?.data?.code === 403) {
      if (setIsLicenseConnect) setIsLicenseConnect(true);
    }
    else if (error.response?.data?.data?.code === 402) {
      if (setIsLicenseReq) setIsLicenseReq(error.response.data.data);
    }
    else {
      console.error("Error fetching Malware data:", error);
      setMalwareData([]);
    }

  }
  setLoading(false);
};

export const handleBulkDeleteMalware = async (isDeleteAll, pids) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_malware_scanner_report_by_pid");
    formData.append("data", JSON.stringify({ is_delete: isDeleteAll, pids: pids }));
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

    if (response.data.data.code === 200) {
      toast.success(isDeleteAll ? "All files deleted successfully" : "Selected files deleted successfully");
      return true;
    } else {
      console.error("Failed to delete files:", response.data.data.message);
      toast.error("Failed to delete files");
      return false;
    }
  } catch (error) {
    console.error("Error deleting files:", error);
    return false;
  }
};

export const verifyScannerStatus = async ({ setVerifyLoading, setProgress, setScanState, setScanType, setScannerStates, setInfoMessage, setParentEnable, setFeatureEnable, setIsLicenseConnect, setIsLicenseReq }) => {
  try {
    setVerifyLoading(true);
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_malware_scanner_verify_status");
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    if (response.data.success === true) {
      const { is_completed, progress, scan_state, scan_type, files_intergrity, malware_scanner, website_backup } = response.data.data;
      setProgress(progress);
      setScanState(scan_state);
      setScanType(scan_type);

      const trueStates = {
        filesIntegrity: files_intergrity ?? true,
        malwareScanner: malware_scanner ?? true,
        websiteBackup: website_backup ?? true,
      };
      setScannerStates(trueStates);
      const falseStates = Object.entries(trueStates)
        .filter(([key, value]) => !value)
        .map(([key]) => key);

      if (falseStates.length > 0) {
        setInfoMessage(`Please enable ${falseStates.join(", ")} feature in order to use Malware Scanner.`);
      } else {
        setInfoMessage("");
      }
    } else if (response.data.success === false) {
      if (response.data.data) {
        const { feature_enable, parent_enable } = response.data.data;
        setFeatureEnable(feature_enable);
        setParentEnable(parent_enable);
      }
    }
    if (response.data.data?.code === 402) {
      setIsLicenseReq(response.data.data);
    }
  } catch (error) {
    if (error.response?.data?.data?.code === 403) {
      if (setIsLicenseConnect) setIsLicenseConnect(true);
    }
    else if (error.response?.data?.data?.code === 402) {
      if (setIsLicenseReq) setIsLicenseReq(error.response.data.data);
    }
    else {
    }
  } finally {
    setVerifyLoading(false);
  }
};

export const viewInfectedContent = async (pid, file_path) => {
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_get_infected_file_content");
    formData.append('data', JSON.stringify({ pid, file_path }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });
    if (response.data.data.code === 200) {
      return response.data.data.data;
    } else {
      toast.error("Failed to fetch file content");
      return null;
    }
  } catch (error) {
    console.error("Error fetching file content:", error);
    return null;
  }
};

