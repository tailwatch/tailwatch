import axios from 'axios';
import { updateLocalStorage, getLocalStorage } from '../../../Components/Utils/HelperFunctions/LocalStorageHelper';
import { toast } from 'react-toastify';
import { CronHealer } from '../../../Components/CroneHealer/CronHealer';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const getTables = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_all_table_names');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.status === 200 && response.data.success) {
            return response.data.data.all_tables;
        } else {
            return [];
        }
    } catch (error) {
        console.error('Error fetching tables:', error);
        return [];
    }
};

export const startSearchReplace = async (data, setScanCompleted, fetchLogs) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_start_search_replace');
        formData.append('nonce', tailwatch_ajax.nonce);

        const payload = {
            search: data.search,
            replace: data.replace,
            dry_run: data.dryRun,
            guid: data.guid,
            case_insensitive: data.caseInsensitive,
            all_tables: data.allTables
        };
        formData.append('data', JSON.stringify(payload));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
            const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
            const runningProcess = response.data?.data?.data?.running_process;
            await alertService.warning(message, 'Process in Progress');
            if (runningProcess === 'search_replace' && typeof fetchLogs === 'function') {
                setScanCompleted(true);
                fetchLogs();
                return true;
            }
            return;
        }

        if (response.data.data.is_started === true) {
            setScanCompleted(true);
            fetchLogs();
            return true;
        } else {
            console.error("Failed to Search/Replace execution");
        }

    } catch (error) {
        console.error('Error starting search/replace:', error);
        return null;
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
        formData.append("action_type", "tailwatch_search_replace_cron_if_failed");
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

export const fetchLogs = async (
    setLogs,
    setIsCompleted,
    isFirstExecution = true,
    isComponentActiveRef,
    setProgress,
    setIsScanInProgress,
    setIsLogsVisible,
    setIsPausing,
    setIsResuming,
    setIsPaused,
    setIsCanceled,
    setInfoRevertChange,
    setIsCanceling,
    setOperationLoading,
    isLastLogIndex,
    setIsLastLogIndex,
) => {

    try {
        const dryRunValue = getLocalStorage('Search&Replace', 'dryRun');

        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_live_search_replace_logs");
        formData.append("nonce", tailwatch_ajax.nonce);

        const lastLogIndex = isFirstExecution ? 0 : isLastLogIndex;
        formData.append("data", JSON.stringify({ last_log_index: lastLogIndex }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        const { new_logs, is_completed, scan_state, cron_running, progress, function_completed, completion_timestamp, last_log_index, current_timestamp } = response.data.data;
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

        if (scan_state === "reverting_changes" && is_completed === true) {
            setIsCompleted(true);
        }

        if (is_completed) {
            setIsCompleted(true);
            updateLocalStorage('Search&Replace', {}, ['dryRun']);
        }

        else if (scan_state === "pause") {
            toast.success(`Process is ${scan_state} `);
            setIsScanInProgress(false);
            setIsPaused(true);
            setIsPausing(true);
            setIsResuming(false);
            setOperationLoading(false)
            return;
        }
        else if ((scan_state === "cancel" && dryRunValue === true)) {
            toast.success(`Process is ${scan_state} `);
            setIsScanInProgress(false);
            setIsLogsVisible(false);
            setIsCanceled(true);
            setOperationLoading(false);
            return;
        }
        else if (scan_state === null) {
            setIsScanInProgress(false);
            setIsLogsVisible(false);
            setOperationLoading(false);
            updateLocalStorage('Search&Replace', {}, ['dryRun']);
            return;
        }

        else if (is_completed === false) {
            if (scan_state === "cancel" && dryRunValue === false) {
                setIsCanceling(true);
                setIsResuming(true);
                setInfoRevertChange(true);
                setOperationLoading(false);
            }
            if (
                scan_state === "in-progress" ||
                scan_state === "reverting_changes" ||
                scan_state === "completed" ||
                (scan_state === "cancel" && dryRunValue === false)
            ) {
                
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
                            setIsPausing,
                            setIsResuming,
                            setIsPaused,
                            setIsCanceled,
                            setInfoRevertChange,
                            setIsCanceling,
                            setOperationLoading,
                            last_log_index,
                            setIsLastLogIndex,
                        );
                    }, 4000);
                } else {
                    
                }
            } else if (scan_state === "pause") {
                
                toast.success(`Search & Replace process is paused successfully.`);
                return;
            } else if (scan_state === "cancel" && dryRunValue === true) {
                
                toast.success(`Search & Replace process is canceled.`);
                return;
            } else {
                
            }
        }

        if (scan_state !== "pause" && (scan_state !== "cancel" || dryRunValue === false)) {
            setTimeout(() => {
                checkCronExecution(cron_running);
            }, 15000);
        }

    } catch (error) {
        toast.error("Network Error");
        console.error("Error fetching logs:", error);
        setIsScanInProgress(false);
        setIsLogsVisible(false);
        setOperationLoading(false);
        updateLocalStorage('Search&Replace', {}, ['dryRun']);
    }
};

export const verifySearchReplaceStatus = async ({ setSearchText, setReplaceText, setDryRun, setReplaceGuids, setCaseInsensitive, setSelectedTables, setProgress, setScanState, setIsScanInProgress, setIsLogsVisible, setIsLoadingVisible, setVerifyStatus, setIsStatusChecked, setFeatureEnable, setParentEnable }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_verify_search_replace_status');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        const scanStates = response.data?.data?.scan_state;
        const options = response.data?.data?.options;

        if (options) {
            setSearchText(options.search || "");
            setReplaceText(options.replace || "");
            setDryRun(options.dry_run || false);
            setReplaceGuids(options.guid || false);
            setCaseInsensitive(options.case_insensitive || false);
            setSelectedTables(options.all_tables || []);
        }
        setProgress(response.data?.data?.progress);
        setScanState(scanStates);
        if (response.data?.data?.is_completed === false) {
            setVerifyStatus(false);
        }
        else if (response.data?.data?.is_completed === true) {
            setIsScanInProgress(false);
            setIsLogsVisible(false);
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        }
        else {
            setVerifyStatus(true);
        }
    } catch (error) {
        console.error("Error verifying optimization status:", error);
    } finally {
        setIsStatusChecked(true);
        setIsLoadingVisible(false);
    }
};

export const seartchActions = async ({ scan_state, setIsScanInProgress, setIsPaused, setLogs, setIsLogsVisible, setIsDryRun }) => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_cancel_pause_search_replace");
        formData.append("data", JSON.stringify({ scan_state }));
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data.success) {
            if (scan_state === "pause") {
                setIsScanInProgress(false);
                setIsPaused(true);
                setLogs((prevLogs) => [...prevLogs, "Search & Replace process is paused Successfully."]);
                setIsLogsVisible(true);
            } else if (scan_state === "cancel") {
                const isDryRun = response.data.data.dry_run;
                setIsDryRun(isDryRun);
                return isDryRun;
            }
        }
        return false;
    } catch (error) {
        toast.error("Network Error");
        return false;
    }
};