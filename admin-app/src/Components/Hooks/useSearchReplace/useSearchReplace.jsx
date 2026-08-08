import React, { useEffect, useState, useRef } from 'react';
import axios from 'axios';
import { UseSearchReplace } from '../Features/UseFeatures';
import { getLocalStorage, updateLocalStorage } from '../../Utils/HelperFunctions/LocalStorageHelper';
import { toast } from 'react-toastify';
import { getTables, fetchLogs, verifySearchReplaceStatus, seartchActions, startSearchReplace } from '../../../Pages/SearchReplace/SearchReplaceServices/SearchReplaceServices';
/* global tailwatch_ajax */
export const useSearchReplace = () => {
    const { enableSearchReplace, refreshSearchReplaceStatus } = UseSearchReplace();
    const [scanCompleted, setScanCompleted] = useState(false);
    const [logs, setLogs] = useState([]);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isOperationInProgress, setIsOperationInProgress] = useState(false);
    const [isPausing, setIsPausing] = useState(false);
    const [isCanceling, setIsCanceling] = useState(false);
    const [isDisabled, setIsDisabled] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [isModalOpen, setIsModalOpen] = useState(false);    
    const [isLoadingVisible, setIsLoadingVisible] = useState(true);
    const [isCanceled, setIsCanceled] = useState(false);
    const [scanState, setScanState] = useState(null);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [progress, setProgress] = useState(1);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const progressBarColor = isScanInProgress ? "linear-gradient(to right, #007980, #85cbcf)" : (isPaused || !verifyStatus) ? "linear-gradient(to right, #616161, #d1d5db)" : "";

    const [tables, setTables] = useState([]);
    const [selectedTables, setSelectedTables] = useState([]);
    const [searchText, setSearchText] = useState('');
    const [replaceText, setReplaceText] = useState('');
    const [caseInsensitive, setCaseInsensitive] = useState(false);
    const [replaceGuids, setReplaceGuids] = useState(false);
    const [dryRun, setDryRun] = useState(false);
    const [loading, setLoading] = useState(true);
    const isComponentActive = useRef(true)
    const [infoBarVisible, setInfoBarVisible] = useState(false);
    const [infoMessage, setInfoMessage] = useState('');
    const [infoRevertChange, setInfoRevertChange] = useState(false);
    const [revertMessage, setRevertMessage] = useState('');
    const [isDryRun, setIsDryRun] = useState(null);
    const [isStatusChecked, setIsStatusChecked] = useState(false);
    const [isResuming, setIsResuming] = useState(false);
    const dryRunValue = getLocalStorage('Search&Replace', 'dryRun');
    const [verifyLoading, setVerifyLoading] = useState(false);
    const [isOperationLoading, setOperationLoading] = useState(false);
    const [isLastLogIndex,setIsLastLogIndex] = useState(null);
    const isChecking = useRef(false);

    useEffect(() => {
        const fetchData = async () => {                
            try {
                await Promise.all([checkPauseResumeStatus()]);
            } catch (error) {
            } finally {
            }
        };
        fetchData();        
    }, []);

    useEffect(() => {
        if (!isStatusChecked) return;
        if (scanState === "in-progress" || scanState === "reverting_changes" || (scanState === "cancel" && dryRunValue === false)) {
            setIsScanInProgress(true);
            setInfoBarVisible(false);
            setIsPaused(false);
            setIsCompleted(false);
            setScanCompleted(false);
            setProgress(1);
            updateLocalStorage('Search&Replace', {
                dryRun: dryRun,
            });
        }
    }, [scanState]);

    useEffect(() => {
        if (!isStatusChecked) return;
        if ((scanState === "in-progress" || scanState === "reverting_changes" || (scanState === "cancel" && dryRunValue === false)) && !isCompleted) {
            const delayFetchLogs = setTimeout(() => {
                fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setIsPausing, setIsResuming, setIsPaused, setIsCanceled, setInfoRevertChange, setIsCanceling, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
            }, 4000);

            return () => clearTimeout(delayFetchLogs);
        }
    }, [fetchLogs, isCompleted, scanState, isStatusChecked]);

    useEffect(() => {
        isComponentActive.current = true;

        return () => {
            isComponentActive.current = false;
        };
    }, []);

    useEffect(() => {
        if (!isCompleted) {
            updateLocalStorage('Search&Replace', { dryRun: dryRun });
        }
    }, [isScanInProgress, dryRun, isCompleted]);

    const handleResume = async () => {
        setIsModalOpen(true);
    };

    const handleSearchReplace = async () => {
        // Client-side validation runs before hitting the backend so we can show
        // the "missing fields" message only when the user genuinely left a field empty.
        // When "Run as dry run" is checked, the Replace term is optional — a dry run only
        // previews matches against the Search term without performing any replacement.
        const hasSearch = searchText.trim() !== '';
        const hasReplace = replaceText.trim() !== '';
        const hasTables = selectedTables && selectedTables.length > 0;
        const isReplaceMissing = !dryRun && !hasReplace;

        if (!hasSearch || !hasTables || isReplaceMissing) {
            const missingFields = [];
            if (!hasSearch) missingFields.push("'Search' term");
            if (!hasTables) missingFields.push("'Table(s)'");
            if (isReplaceMissing) missingFields.push("'Replace' term");
            setInfoMessage(`Required fields missing: please provide ${missingFields.join(', ')}.`);
            setInfoBarVisible(true);
            return;
        }

        const payload = { search: searchText, replace: replaceText, dryRun: dryRun, guid: replaceGuids, caseInsensitive: caseInsensitive, allTables: selectedTables };
        setIsStarting(true);
        setInfoBarVisible(false);
        setScanCompleted(false);

        try {
            const response = await startSearchReplace(payload, setScanCompleted, () => fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setIsPausing, setIsResuming, setIsPaused, setIsCanceled, setInfoRevertChange, setIsCanceling, setOperationLoading, isLastLogIndex, setIsLastLogIndex));

            if (!response) {
                // Backend rejected the request (e.g. another process is running).
                // The service already surfaced the reason via alertService — don't show the
                // validation error here, since fields were already verified above.
                return;
            }

            // Backend confirmed the search/replace is running — now flip the in-progress UI
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            updateLocalStorage('Search&Replace', { dryRun: dryRun });
        } catch (error) {
            console.error('Error during Search/Replace:', error);
        } finally {
            setIsStarting(false);
        }
    };

    const handleSeartchAction = async (scan_state) => {
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
            return false;
        }
    };

    const handleCancel = async () => {
        setIsCanceling(true);
        setOperationLoading(true);
        const isDryRunValue = await handleSeartchAction("cancel");
        if (isDryRunValue === true && dryRunValue === true) {
            setIsScanInProgress(false);
            setIsCanceled(true);
            setIsLogsVisible(false);
            setIsPaused(false);
            setIsCanceling(false);
            updateLocalStorage('Search&Replace', {}, ['dryRun']);
        } else {
            setInfoRevertChange(true);
            setIsResuming(true);
            setIsScanInProgress(true);
            setRevertMessage("Reverting the changes. Dont stop the process");
        }
    };

    const handleCancelAfterPause = async () => {
        setIsCanceling(true);
        const isDryRunValue = await handleSeartchAction("cancel");
        if (isDryRunValue === true) {
            setIsScanInProgress(false);
            setIsCanceled(true);
            setIsLogsVisible(false);
            setIsPaused(false);
            setIsCanceling(false);
        } else {
            setInfoRevertChange(true);
            setIsResuming(true);
            setIsScanInProgress(true);
            await fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setIsPausing, setIsResuming, setIsPaused, setIsCanceled, setInfoRevertChange, setIsCanceling, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
            setRevertMessage("Reverting the changes. Dont stop the process");
        }
    };

    const handlePause = async () => {
        setOperationLoading(true);
        await handleSeartchAction("pause");
        setIsPausing(true);
        setIsResuming(false);
    };

    const resetAllStates = () => {
        setLogs([]);
        setIsCanceled(false);
        setIsCanceling(false);
        setIsCompleted(false);
        setIsLogsVisible(false);
        setIsScanInProgress(false);
        setIsPaused(false);
        setScanCompleted(false);
        setProgress(1);
        setVerifyStatus(true);
        setInfoRevertChange(false);
        setScanState(null);
        setIsPausing(false);
    };

    const checkPauseResumeStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        setLoading(true);
        setIsLoadingVisible(true);
        try {
            await refreshSearchReplaceStatus();
            const fetchedTables = await getTables();
            setTables(fetchedTables);
            setFeatureEnable(null);
            setParentEnable(null);
            await verifySearchReplaceStatus({ setSearchText, setReplaceText, setDryRun, setReplaceGuids, setCaseInsensitive, setSelectedTables, setProgress, setScanState, setIsScanInProgress, setIsLogsVisible, setIsLoadingVisible, setVerifyStatus, setIsStatusChecked, setFeatureEnable, setParentEnable })
        } finally {
            isChecking.current = false;
            setLoading(false);
        }
    };

    return {
        isLoadingVisible,parentEnable, loading, verifyLoading, infoBarVisible, infoMessage, setInfoBarVisible, featureEnable, enableSearchReplace, searchText, setSearchText, isScanInProgress, isStarting, verifyStatus, isPaused, replaceText, setReplaceText, tables,
        selectedTables, setSelectedTables, caseInsensitive, setCaseInsensitive, replaceGuids, setReplaceGuids, dryRun, setDryRun, checkPauseResumeStatus, isCompleted, handleSearchReplace, setIsLogsVisible, isLogsVisible, handleResume, isOperationInProgress, isCanceled, isPausing,
        isCanceling, handleCancel, handlePause, isDisabled, resetAllStates, scanState, handleCancelAfterPause, progress, progressBarColor, infoRevertChange, setInfoRevertChange, logs, setLogs, isModalOpen, setIsModalOpen, fetchLogs, setIsScanInProgress, setIsCompleted, setIsOperationInProgress, isComponentActive,
        setProgress, setIsResuming, isResuming, setIsPausing, setIsPaused, setIsCanceled, setIsCanceling, setOperationLoading, isOperationLoading, isLastLogIndex, setIsLastLogIndex
    }
}