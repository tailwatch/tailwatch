import axios from 'axios';
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const instantScanning = async ({ setScanCompleted, setErrorMessages, fetchLogs }) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_start_hardening_audit");
        formData.append("nonce", tailwatch_ajax.nonce);
        formData.append("data", JSON.stringify({ instant_scan: true }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
            const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
            const runningProcess = response.data?.data?.data?.running_process;
            await alertService.warning(message, 'Process in Progress');
            if (runningProcess === 'hardening_audit' && typeof fetchLogs === 'function') {
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
            setErrorMessages((prevErrors) => [...prevErrors, "Failed to Start Hardening Audit"]);
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
        formData.append("action_type", "tailwatch_hardening_audit_cron_if_failed");
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (!response.data.success) {
            console.error("Failed to execute hardening audit cron.");
        }
    } catch (error) {
        console.error("Error executing hardening audit cron", error);
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

export const fetchLogs = async (setLogs, setIsCompleted, isFirstExecution = true, isComponentActiveRef, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, isLastLogIndex, setIsLastLogIndex, setFeatureEnable, setParentEnable) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_get_hardening_audit_live_logs");
        formData.append("nonce", tailwatch_ajax.nonce);

        const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
        formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        // Backend gates: when feature/parent are disabled the call returns success=false
        // with the disable flags in `data`. Mirror BrokenLink's pattern.
        if (response.data?.success === false) {
            const { feature_enable, parent_enable } = response.data.data || {};
            if (typeof feature_enable !== 'undefined' && setFeatureEnable) setFeatureEnable(feature_enable);
            if (typeof parent_enable !== 'undefined' && setParentEnable) setParentEnable(parent_enable);
            setIsScanInProgress(false);
            setIsLogsVisible(false);
            setOperationLoading(false);
            return;
        }

        const { new_logs, is_completed, scan_state, cron_running, progress, function_completed, last_log_index, completion_timestamp, current_timestamp } = response.data.data;
        setIsLastLogIndex(last_log_index);
        setProgress(progress);
        if (new_logs && new_logs.length > 0) {
            setLogs((prevLogs) => [...prevLogs, ...new_logs]);
        }

        if (function_completed && completion_timestamp && current_timestamp) {
            const timeDifference = current_timestamp - completion_timestamp;
            if (timeDifference >= 12) {
                await CronHealer();
            }
        }

        if (is_completed && scan_state === "completed") {
            setIsCompleted(true);
            setRenderKey((prevKey) => prevKey + 1);
        }
        else if (scan_state === "pause") {
            toast.success(`Audit is ${scan_state}, stopping further log fetching.`);
            setIsScanInProgress(false);
            setIsPaused(true);
            setOperationLoading(false);
            return;
        }
        else if (scan_state === "cancel") {
            toast.success(`Audit is ${scan_state}, stopping further log fetching.`);
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
            setErrorMessages((prevErrors) => [...prevErrors, "Audit State is undefined"]);
        }
        else if (scan_state === "in-progress") {
            if (isComponentActiveRef.current) {
                setTimeout(() => {
                    fetchLogs(setLogs, setIsCompleted, false, isComponentActiveRef, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, last_log_index, setIsLastLogIndex, setFeatureEnable, setParentEnable);
                }, 4000);
            }
        }

        if (scan_state !== "pause" && scan_state !== "cancel") {
            setTimeout(() => {
                checkCronExecution(cron_running);
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

export const handleHardeningAuditActions = async ({ scan_state, setIsScanInProgress, setIsPaused, setIsCompleted, setLogs, setIsLogsVisible, setErrorMessages }) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_cancel_pause_hardening_audit");
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
                setLogs((prevLogs) => [...prevLogs, "Hardening Audit paused by user"]);
            }
        }
    } catch (error) {
        setErrorMessages((prevErrors) => [...prevErrors, `Error while ${scan_state === 'cancel' ? 'canceling' : 'pausing'} hardening audit`]);
        console.error(`Error during ${scan_state === 'cancel' ? 'cancel' : 'pause'} hardening audit:`, error);
    }
};

export const verifyHardeningAuditStatus = async (setLoading, setVerifyStatus, setProgress, setScanState, setScanType, setErrorMessages, setFeatureEnable, setParentEnable) => {
    try {
        setLoading(true);
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_verify_hardening_audit_status");
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data.success === true) {
            const { is_completed, progress, scan_state, scan_type } = response.data.data;

            // "idle" means no audit is in progress — ignore it and keep the UI in its
            // default ready-to-start state.
            if (scan_state === "idle") {
                setVerifyStatus(true);
                return;
            }

            setProgress(progress);
            setScanState(scan_state);
            setScanType(scan_type);

            if (is_completed === false) {
                setVerifyStatus(false);
            } else {
                setVerifyStatus(true);
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data || {};
            if (typeof feature_enable !== 'undefined') setFeatureEnable(feature_enable);
            if (typeof parent_enable !== 'undefined') setParentEnable(parent_enable);
        }
    } catch (error) {
        setErrorMessages((prevErrors) => [...prevErrors, "Error Verifying Hardening Audit Status"]);
        console.error("Error verifying Hardening Audit status:", error);
    } finally {
        setLoading(false);
    }
};

export const listHardeningAuditReports = async ({ setReports, setLoading, page = 1, limit = 10, setFeatureEnable, setParentEnable, setPagination }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_list_hardening_audit_reports');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data?.data?.code === 200) {
            // Response shape: response.data.data.data = { reports: [...], limit, offset, total? }
            const inner = response.data.data.data || {};
            const reports = Array.isArray(inner.reports)
                ? inner.reports
                : Array.isArray(inner.data)
                    ? inner.data
                    : Array.isArray(inner)
                        ? inner
                        : [];
            setReports(reports);
            if (setPagination) {
                const respLimit = inner.limit ?? limit;
                const offset = inner.offset ?? 0;
                const totalItems = inner.total ?? inner.total_items ?? reports.length;
                const totalPages = inner.total_pages ?? Math.max(1, Math.ceil(totalItems / respLimit));
                setPagination({
                    page: inner.page ?? Math.floor(offset / respLimit) + 1,
                    limit: respLimit,
                    total_pages: totalPages,
                    total_items: totalItems,
                });
            }
        } else if (response.data?.success === false) {
            const { feature_enable, parent_enable } = response.data.data || {};
            if (typeof feature_enable !== 'undefined' && setFeatureEnable) setFeatureEnable(feature_enable);
            if (typeof parent_enable !== 'undefined' && setParentEnable) setParentEnable(parent_enable);
            setReports([]);
        } else {
            setReports([]);
        }
    } catch (error) {
        console.error("Error listing hardening audit reports:", error);
        setReports([]);
    } finally {
        setLoading(false);
    }
};

export const getHardeningAuditReportById = async (id) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_hardening_audit_report_by_id');
        formData.append('data', JSON.stringify({ id }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data?.data?.code === 200) {
            return response.data.data.data || response.data.data;
        }
        return null;
    } catch (error) {
        console.error("Error fetching hardening audit report by id:", error);
        return null;
    }
};

export const deleteHardeningAuditReport = async (deleteData, setIsDeleting, refetch) => {
    const { ids, is_delete } = deleteData;
    setIsDeleting(true);
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_delete_hardening_audit_report_by_id");
        formData.append("data", JSON.stringify({ ids, is_delete }));
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data?.data?.code === 200) {
            if (typeof refetch === 'function') await refetch();
            toast.success("Reports deleted successfully");
        } else {
            console.error("Failed to delete reports:", response.data?.data?.message);
            toast.error("Failed to delete reports");
        }
    } catch (error) {
        console.error("Error deleting reports:", error);
    } finally {
        setIsDeleting(false);
    }
};
