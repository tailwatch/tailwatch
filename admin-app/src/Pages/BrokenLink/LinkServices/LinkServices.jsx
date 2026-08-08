import axios from 'axios';
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const instantScanning = async ({ setScanCompleted, setErrorMessages, fetchLogs }) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_start_broken_link_checker");
        formData.append("nonce", tailwatch_ajax.nonce);
        formData.append("data", JSON.stringify({ instant_scan: true }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
            const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
            const runningProcess = response.data?.data?.data?.running_process;
            await alertService.warning(message, 'Process in Progress');
            if (runningProcess === 'broken_link_checker' && typeof fetchLogs === 'function') {
                setScanCompleted(true);
                await fetchLogs();
                return true;
            }
            return;
        }

        if (response.data.data.code === 200) {
            setScanCompleted(true);
            await fetchLogs();
            return true;
        } else {
            setErrorMessages((prevErrors) => [...prevErrors, "Failed to Scan Broken Links"]);
            return false;
        }
    } catch (error) {
        console.error("Error in instantScanning:", error);
        setErrorMessages((prevErrors) => [...prevErrors, `Network Error: ${error.message || "Unknown error"}`]);
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
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_broken_links_cron_if_failed");
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data.success) {

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

export const fetchLogs = async (setLogs, setIsCompleted, isFirstExecution = true, isComponentActiveRef, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, isLastLogIndex, setIsLastLogIndex) => {

    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_broken_link_checker_live_logs");
        formData.append("nonce", tailwatch_ajax.nonce);

        const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
        formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
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
            setIsScanInProgress(false);
            setIsLogsVisible(false);
            setIsCanceled(true);
            setIsPaused(false);
            setOperationLoading(false);
            return;
        }
        else if (scan_state === null) {
            setIsScanInProgress(false);
            setIsLogsVisible(false);
            setOperationLoading(false);
            setErrorMessages((prevErrors) => [...prevErrors, "Scan State is undefined"]);
        }

        else if (scan_state === "in-progress") {
            if (isComponentActiveRef.current) {
                setTimeout(() => {
                    fetchLogs(setLogs, setIsCompleted, false, isComponentActiveRef, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, last_log_index, setIsLastLogIndex);
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
    }
};

export const handleBrokenLinkActions = async ({ scan_state, setIsScanInProgress, setIsPaused, setIsCompleted, setLogs, setIsLogsVisible, setErrorMessages }) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_cancel_pause_broken_link");
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
            } else if (scan_state === "pause") {
                setIsScanInProgress(false);
                setIsPaused(true);
                setLogs((prevLogs) => [...prevLogs, "Scan Broken Link paused by user"]);
            }
        }
    } catch (error) {
        setErrorMessages((prevErrors) => [...prevErrors, "error while cancel or pause scan broken link"]);
        console.error(`Error during ${scan_state === 'cancel' ? 'cancel' : 'pause'} scan broken link action:`, error);
    }
};

export const verifyBrokenLinkStatus = async (setLoading, setVerifyStatus, setProgress, setScanState, setScanType, setErrorMessages, setFeatureEnable, setParentEnable, setIsLicenseConnect) => {
    try {
        setLoading(true);

        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_verify_broken_link_status");
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        if (response.data.success === true) {
            const { is_completed, progress, scan_state, scan_type } = response.data.data;
            setProgress(progress);
            setScanState(scan_state);
            setScanType(scan_type);

            if (is_completed === false) {
                setVerifyStatus(false);
            } else {
                setVerifyStatus(true);
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            setErrorMessages((prevErrors) => [...prevErrors, "Error Verify Scan Link Status"]);
            console.error("Error verifying Scan Link status:", error);
        }
    } finally {
        setLoading(false);
    }
};

export const getBrokenLinkLogs = async ({ setLinkLogs, setLoading, page = 1, limit = 10, setFeatureEnable, setParentEnable, setIsLicenseConnect, setPagination, setRedirectionsFeature }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_broken_links_logs');
        formData.append('data', JSON.stringify({ page: page, limit: limit }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            setLinkLogs(response.data.data.data);
            if (setPagination && response.data.data) {
                setPagination({ page: response.data.data.page, limit: response.data.data.limit, total_pages: response.data.data.total_pages, total_items: response.data.data.total });
            }
            if (setRedirectionsFeature) {
                setRedirectionsFeature(response.data.data.redirections_feature || null);
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        } else {
            setLinkLogs([]);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            console.error("Error verifying Scan Link status:", error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleDeleteLogs = async (deleteData, setIsDeleting, fetchBrokenLinkLogs) => {
    const { ids, is_delete } = deleteData;
    setIsDeleting(true);
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_delete_entries_and_logs");
        formData.append("data", JSON.stringify({ ids, key: "default_broken_link_logs", is_delete }));
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
            await fetchBrokenLinkLogs();
            toast.success("Logs deleted successfully");
        } else {
            toast.error("Failed to delete logs");
        }
    } catch (error) {
        console.error("Error deleting logs:", error);
    } finally {
        setIsDeleting(false);
    }
};
