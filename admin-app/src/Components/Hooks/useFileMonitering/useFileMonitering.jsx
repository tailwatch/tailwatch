import React, { useState, useEffect, useRef, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import axios from "axios";
import { toast } from 'react-toastify';
import { fetchLogs, instantScanning, checkMalwareScanner } from '../../../Pages/FileMonitering/FileMoniterServices/FileMoniterServices';
import { CheckIntegrityFeature } from '../Features/UseFeatures';

/* global tailwatch_ajax */

export const useFileMonitering = () => {
    const [scanCompleted, setScanCompleted] = useState(false);
    const [logs, setLogs] = useState([]);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isOperationInProgress, setIsOperationInProgress] = useState(false);
    const [isCanceled, setIsCanceled] = useState(false);
    const [isPausing, setIsPausing] = useState(false);
    const [isCanceling, setIsCanceling] = useState(false);
    const [statusMessage, setStatusMessage] = useState('');
    // { files_scanned, files_total, progress } while a status scan is running.
    // Rendered as a compact progress card in place of the plain status message.
    const [scanProgress, setScanProgress] = useState(null);
    const [isLoadingVisible, setIsLoadingVisible] = useState(true);
    const [isDisabled, setIsDisabled] = useState(false);
    const { showFileMonteringLogs, refreshIntegrityStatus } = CheckIntegrityFeature();
    const [scanState, setScanState] = useState(null);
    const [scanType, setScanType] = useState(null);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [progress, setProgress] = useState(1);
    const [showDetails, setShowDetails] = useState(false);
    const progressBarColor = isScanInProgress ? "linear-gradient(to right, #007980, #85cbcf)" : (isPaused || !verifyStatus) ? "linear-gradient(to right, #616161, #d1d5db)" : "";
    const [isFetchingLogs, setIsFetchingLogs] = useState(false);
    const [renderKey, setRenderKey] = useState(0);
    const [trigerMoniteringDetails, setTrigerMoniteringDetails] = useState(0);
    const [summaryData, setSummaryData] = useState({ newScanFiles: 0, totalCapturedFiles: 0 });
    const [isScannerEnabled, setIsScannerEnabled] = useState(false);
    const [loadingStrip, setLoadingStrip] = useState(false);
    const [isOperationLoading, setOperationLoading] = useState(false);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [ismonitering, setIsMonitoring] = useState(false);
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const [isLastLogIndex,setIsLastLogIndex] = useState(null);
    const [scanError, setScanError] = useState(null);
    const [scanErrorDismissed, setScanErrorDismissed] = useState(false);
    const navigate = useNavigate();

    const isComponentActive = useRef(true);
    const isChecking = useRef(false);
    const handleSummaryData = useCallback((data) => {
        setSummaryData(data);
    }, []);

    useEffect(() => {
        if (scanState === "in-progress") {
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setScanCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            setVerifyStatus(true);
        }
        if (scanType !== "automatically") {
            setShowDetails(true);
        } else {
            setShowDetails(false);
        }
    }, [scanState, scanType]);

    useEffect(() => {
        if (isFetchingLogs) {
            setIsFetchingLogs(false)
            return;
        }
        if (scanState === "in-progress" && !isCompleted) {
            const delayFetchLogs = setTimeout(() => {
                fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsScanInProgress, setIsLogsVisible, setProgress, setRenderKey, setTrigerMoniteringDetails, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
            }, 4000);

            return () => clearTimeout(delayFetchLogs);
        }
    }, [fetchLogs, scanState, isCompleted]);

    useEffect(() => {
        isComponentActive.current = true;
        return () => {
            isComponentActive.current = false;
        };
    }, []);

    const handleInstantScan = async () => {
        setIsStarting(true);
        setScanCompleted(false);
        setScanErrorDismissed(false);

        try {
            const scanSuccessful = await instantScanning(setScanCompleted, setStatusMessage, setIsDisabled, checkMonitoringStatus, () => fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsScanInProgress, setIsLogsVisible, setProgress, setRenderKey, setTrigerMoniteringDetails, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex), setIsMonitoring);

            if (!scanSuccessful) {
                return;
            }

            // Backend confirmed the integrity scan is running — now flip the in-progress UI
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            setRenderKey((prevKey) => prevKey + 1);
            setTrigerMoniteringDetails((prevKey) => prevKey + 1);
        } catch (error) {
            console.error("Error in file Integrity:", error);
        } finally {
            setIsStarting(false);
        }
    };

    useEffect(() => {
        if (isCompleted) {
            checkMalwareScanner(setIsScannerEnabled, setLoadingStrip, setScanError);
        }
    }, [isCompleted]);

    const handleResume = async () => {
        setIsModalOpen(true);
    };

    const handleMoniteringAction = async (scan_state) => {
        try {
            const formData = new FormData();
            formData.append("action", "tailwatch_global_ajax_handler");
            formData.append("action_type", "tailwatch_cancel_pause_integrity");
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
                    setLogs((prevLogs) => [...prevLogs, "File Monitering process canceled by user"]);
                } else if (scan_state === "pause") {
                    setIsScanInProgress(false);
                    setIsPaused(true);
                    setLogs((prevLogs) => [...prevLogs, "File monitering process paused by user"]);
                }
            }
        } catch (error) {
            console.error(`Error during ${scan_state === 'cancel' ? 'cancel' : 'pause'} backup action:`, error);
        }
    };

    const handleCancel = async () => {
        if (verifyStatus && !isPaused) {
            setOperationLoading(true);
        }
        setIsCanceling(true);
        await handleMoniteringAction("cancel");
        setIsCanceled(true);
        setIsLogsVisible(false);
        setIsPaused(false);
        setIsCanceling(false);
        setTrigerMoniteringDetails((prevKey) => prevKey + 1);
    };

    const handlePause = async () => {
        setOperationLoading(true);
        setIsPausing(true);
        await handleMoniteringAction("pause");
        await checkMonitoringStatus();
        // setLoading(true);
        setVerifyStatus(true);
        setIsPausing(false);
    };

    const checkMonitoringStatus = async (isRecursiveCall = false) => {

        if (!isRecursiveCall) {
            setIsLoadingVisible(true);
        }

        try {
            const formData = new FormData();
            formData.append('action', 'tailwatch_global_ajax_handler');
            formData.append('action_type', 'tailwatch_verify_integrity_current_status');
            formData.append('nonce', tailwatch_ajax.nonce);

            const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });            
            if (response.status === 200 && response.data.success) {
                const { isEnabled, is_request, is_completed, message, progress, scan_state, scan_type, files_scanned, files_total } = response.data.data;
                setScanState(scan_state);
                setProgress(progress);
                setScanType(scan_type);

                const inProgressMessage = "Current status scan is still in progress. Please wait for it to complete before proceeding.";

                const isScanInProgress = (is_completed === undefined || message === inProgressMessage);
                setIsDisabled(isEnabled === false && isScanInProgress);

                if (isEnabled === false && isScanInProgress) {
                    setStatusMessage(inProgressMessage);
                    // Backend may not always ship counts (older payloads); only
                    // build the progress object when at least one of them exists.
                    if (files_scanned != null || files_total != null || progress != null) {
                        setScanProgress({
                            files_scanned: files_scanned ?? null,
                            files_total: files_total ?? null,
                            progress: progress ?? null,
                            scan_state,
                        });
                    } else {
                        setScanProgress(null);
                    }
                } else {
                    setStatusMessage('');
                    setScanProgress(null);
                }
                if (is_completed === true) {
                    setIsScanInProgress(false);
                }

                if (scan_state === 'pause' || message === 'File comparison was the pause.') {
                    setVerifyStatus(false);
                } else {
                    setVerifyStatus(true);
                }

                if (is_request === true && isComponentActive.current) {
                    setTimeout(() => checkMonitoringStatus(true), 4000);
                }
            } else if (response.data.success === false) {
                if (response.data.data) {
                    const { feature_enable, parent_enable } = response.data.data;
                    setFeatureEnable(feature_enable);
                    setParentEnable(parent_enable);
                }
            } else {
                console.error('Monitoring Details: no data exists');
            }
        } catch (error) {
            if (error.response.data.data.code === 403) {
                setIsLicenseConnect(true);
            } else {
                console.error('Error fetching Monitoring Details:', error);
            }
        } finally {

            if (!isRecursiveCall) {
                setIsLoadingVisible(false);
            }
        }
    };

    const checkIntegrityFileStatus = async () => {
        if (isChecking.current) return; // Exit if already running
        isChecking.current = true; // Set flag to true
        try {
            await refreshIntegrityStatus();
            setTrigerMoniteringDetails((prevKey) => prevKey + 1);
            setRenderKey((prevKey) => prevKey + 1);
            setFeatureEnable(null);
            setParentEnable(null);
            setIsLicenseConnect(null);
            await checkMonitoringStatus();
        } finally {
            isChecking.current = false; // Reset flag when done
        }
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
        setVerifyStatus(true);
        setScanType(null);
        setScanState(null);
        setProgress(1);
        setScanError(null);
        setScanErrorDismissed(false);
    };

    const handleToggleDetails = () => {
        if (scanType === "automatically") {
            setShowDetails((prev) => !prev);
        }
    };

    useEffect(() => {
        checkMonitoringStatus();
    }, []);

    useEffect(() => {
        if (isCompleted) {
            setShowDetails(true);
        }
    }, [isScanInProgress, isCompleted]);

    return {
        isLoadingVisible, ismonitering, featureEnable, setFeatureEnable, parentEnable, setParentEnable, showFileMonteringLogs, trigerMoniteringDetails, loadingStrip, isCompleted, isCanceled, summaryData, isScannerEnabled, navigate, scanType, scanState, handleToggleDetails,
        showDetails, isPaused, handleInstantScan, setIsLogsVisible, isLogsVisible, verifyStatus, handleResume, isScanInProgress, isStarting, isOperationInProgress, isPausing, isCanceling, handleCancel, handlePause, statusMessage,
        isDisabled, resetAllStates, checkIntegrityFileStatus, isModalOpen, fetchLogs, setIsScanInProgress, setLogs, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, setIsFetchingLogs,
        checkMonitoringStatus, setRenderKey, renderKey, setTrigerMoniteringDetails, setIsPaused, setIsCanceled, handleSummaryData, progress, progressBarColor, logs, setIsModalOpen, setOperationLoading, isOperationLoading, isLicenseConnect, setIsLicenseConnect, isLastLogIndex, setIsLastLogIndex,
        scanError, setScanError, scanErrorDismissed, setScanErrorDismissed,
        scanProgress
    }
}