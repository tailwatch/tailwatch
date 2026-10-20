import React, { useEffect, useState, useRef } from 'react';
import { fetchLogs, instantScanning, handleBackupAction, verifyBackupStatus, fetchBackupDetails, getBackupFilesDownloadStatus } from '../../../Pages/Backup/BackupServices/BackupServices';
import UseBackupFeature from '../BackupFeature/UseBackupFeature';
import { getLocalStorage } from '../../Utils/HelperFunctions/LocalStorageHelper';
import { pieChartData } from '../../../Pages/Home/HomeServices/HomeServices';
import { toast } from 'react-toastify';
export const useBackup = () => {

    const { enableBackup, refreshBackupStatus } = UseBackupFeature();
    const [scanCompleted, setScanCompleted] = useState(false);
    const [logs, setLogs] = useState([]);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isOperationInProgress, setIsOperationInProgress] = useState(false);
    const [isCanceled, setIsCanceled] = useState(false);
    // Terminal failure, which is its own outcome and not a quieter kind of cancel: nobody
    // asked for it, so the screen owes an explanation rather than an acknowledgement.
    const [isFailed, setIsFailed] = useState(false);
    const [loading, setLoading] = useState(true);
    const [isVerifyLoading, setIsVerifyLoading] = useState(true);
    const [isPausing, setIsPausing] = useState(false);
    const [isCanceling, setIsCanceling] = useState(false);
    const [scanState, setScanState] = useState(null);
    const [scanType, setScanType] = useState(null);
    const [renderKey, setRenderKey] = useState(0);
    const [renderBackupDetails, setRenderBackupDetail] = useState(0);
    const [isFetchingLogs, setIsFetchingLogs] = useState(false);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [progress, setProgress] = useState(1);
    // Grey covers every way a run can be stopped rather than moving — paused, unverified,
    // failed. A failed backup keeps its bar at the point it got to, for the same reason a
    // failed restore does: that number is what says whether there is a partial archive on
    // disk, and it is the only thing the run has left to report.
    const progressBarColor = isScanInProgress ? "linear-gradient(to right, #007980, #85cbcf)" : (isPaused || isFailed || !verifyStatus) ? "linear-gradient(to right, #616161, #d1d5db)" : "";
    const [showDetails, setShowDetails] = useState(false);
    const [showErrorAccordion, setShowErrorAccordion] = useState(false);
    const [errorMessages, setErrorMessages] = useState([]);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isOperationLoading, setOperationLoading] = useState(false);
    const [backupDownloadStatus, setBackupDownloadStatus] = useState(null);
    const [backupStatus, setBackupStatus] = useState(null);
    const isComponentActive = useRef(true);
    const [siteSize, setSiteSize] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    const [intervalId, setIntervalId] = useState(null);
    const [isLastLogIndex,setIsLastLogIndex] = useState(null);
    const isChecking = useRef(false);

    useEffect(() => {
        const storedSiteSize = getLocalStorage('pieChart', 'site size');
        if (storedSiteSize) {
            setSiteSize(storedSiteSize);
        }

    }, []);

    useEffect(() => {
        if (backupDownloadStatus === true) {
            getBackupDownloadStatus();
        }
    }, [backupDownloadStatus]);

    useEffect(() => {
        if (isCompleted) {
            handleRefreshSiteSize();
        }
    }, [isCompleted]);

    const handleRefreshSiteSize = async () => {
        setRefreshing(true);
        try {
            await pieChartData();
            const updatedSiteSize = getLocalStorage('pieChart', 'site size');
            if (updatedSiteSize) {
                setSiteSize(updatedSiteSize);
            }
        } catch (error) {
            console.error('Error refreshing site size:', error);
        } finally {
            setRefreshing(false);
        }
    };

    useEffect(() => {
        if (errorMessages.length > 0) {
            setShowErrorAccordion(true);
        }
    }, [errorMessages]);

    // Remote-storage download progress. The service answers null whenever there is nothing
    // to report — including the case that matters here, where the status flag says a
    // download is running but the site has no action that would describe it. Every read
    // below guards for that rather than reaching straight into the result.
    const getBackupDownloadStatus = async () => {
        const downloadStatus = await getBackupFilesDownloadStatus();
        if (!downloadStatus) {
            setBackupStatus(null);
            return;
        }

        setBackupStatus(downloadStatus.backups);

        if (downloadStatus.process === 'in_progress') {
            if (!intervalId) {
                const id = setInterval(async () => {
                    const status = await getBackupFilesDownloadStatus();
                    if (!status) {
                        // It stopped answering. Stop asking, rather than polling something
                        // that is not there every four seconds for the rest of the session.
                        clearInterval(id);
                        setIntervalId(null);
                        return;
                    }

                    setBackupStatus(status.backups);

                    if (status.process === 'completed') {
                        clearInterval(id);
                        setIntervalId(null);
                    }
                }, 4000);
                setIntervalId(id);
            }
        } else if (downloadStatus.process === 'completed' && intervalId) {
            clearInterval(intervalId);
            setIntervalId(null);
        }
    };

    useEffect(() => {
        if (scanState === "in-progress") {
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setScanCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            setVerifyStatus(true);
            setIsFailed(false);
        }
        // The other way into the failed state: not watching it happen, but coming back to
        // the screen afterwards. The site answers this poll with scan_state 'failed' and a
        // message saying so, and a reload after a failure has to show it rather than a
        // clean idle page.
        if (scanState === "failed") {
            setIsScanInProgress(false);
            setIsPaused(false);
            setIsCompleted(false);
            setScanCompleted(false);
            setIsFailed(true);
            setVerifyStatus(true);
        }
        if (scanType !== "automatically" || scanType !== "completed") {
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

        // 'failed' is fetched once, and once only: the branch that handles it schedules no
        // follow-up. That single round is what pulls the failed run's log tail back off
        // disk, which is the entire reason someone reloads this screen after a failure —
        // to read the last thing the backup managed to say before it stopped.
        if ((scanState === "in-progress" || scanState === "failed") && !isCompleted) {
            const delayFetchLogs = setTimeout(() => {
                fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setRenderKey, setRenderBackupDetail, setErrorMessages, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex, setIsFailed);
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

    useEffect(() => {
        if (isCompleted) {
            setShowDetails(true);
            setVerifyStatus(true);
        }
    }, [isScanInProgress, isCompleted]);

    const handleResumeBackup = async () => {
        setIsModalOpen(true);
    };

    const handleInstantScan = async () => {
        setIsStarting(true);
        setScanCompleted(false);

        try {
            const scanSuccessful = await instantScanning(setScanCompleted, setErrorMessages, () => fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setRenderKey, setRenderBackupDetail, setErrorMessages, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex, setIsFailed));

            if (!scanSuccessful) {
                return;
            }

            // Backend confirmed the backup is running — now flip the in-progress UI
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setIsFailed(false);
            setProgress(1);
            setIsLogsVisible(true);
            setRenderKey((prevKey) => prevKey + 1);
            setRenderBackupDetail((prevKey) => prevKey + 1);
        } catch (error) {
        } finally {
            setIsStarting(false);
        }
    };

    const handleAction = async (scan_state) => {
        await handleBackupAction({ scan_state, setIsScanInProgress, setIsPaused, setIsCompleted, setLogs, setIsLogsVisible, setErrorMessages });
    };

    const handleCancelBackup = async () => {
        if (verifyStatus && !isPaused) {
            setOperationLoading(true);
        }
        setIsCanceling(true);
        await handleAction("cancel");
        setIsCanceled(true);
        setIsLogsVisible(false);
        setIsPaused(false);
        setIsCanceling(false);
        setRenderKey((prevKey) => prevKey + 1);
        setRenderBackupDetail((prevKey) => prevKey + 1);
    };

    const handlePauseBackup = async () => {
        setOperationLoading(true);
        setIsPausing(true);
        await handleAction("pause");
        await verifyStatusBackup();
        setVerifyStatus(true);
        setIsPausing(false);
        setRenderKey((prevKey) => prevKey + 1);
    };

    const verifyStatusBackup = async () => {
        await verifyBackupStatus(setLoading, setVerifyStatus, setProgress, setScanState, setScanType, setErrorMessages, setFeatureEnable, setParentEnable, setBackupDownloadStatus);
    }
    const checkBackupStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshBackupStatus();
            setRenderBackupDetail((prevKey) => prevKey + 1);
            setRenderKey((prevKey) => prevKey + 1);
            setFeatureEnable(null);
            setParentEnable(null);
            await verifyStatusBackup();
        } finally {
            isChecking.current = false;
        }
    };

    const resetAllStates = () => {
        setLogs([]);
        setIsCanceled(false);
        setIsCanceling(false);
        setIsFailed(false);
        setIsCompleted(false);
        setIsLogsVisible(false);
        setIsScanInProgress(false);
        setIsPaused(false);
        setScanCompleted(false);
        setVerifyStatus(true);
        setProgress(1);
        setScanType(null);
        setScanState(null);
        setShowErrorAccordion(false);
    };

    const handleToggleDetails = () => {
        if (scanType === "automatically") {
            setShowDetails((prev) => !prev);
        }
    };

    useEffect(() => {
        verifyStatusBackup();
    }, []);

    return {
        isVerifyLoading, loading, setLoading, enableBackup, renderBackupDetails, showErrorAccordion, errorMessages, scanType, isCompleted, scanState, verifyStatus, isScanInProgress, isStarting, isOperationInProgress, isCanceled, isModalOpen, setIsModalOpen, fetchLogs,
        isPaused, isPausing, isCanceling, resetAllStates, checkBackupStatus, progress, progressBarColor, logs, setLogs, showDetails, setIsLogsVisible, isLogsVisible, handleToggleDetails, handleInstantScan, handlePauseBackup, handleCancelBackup, handleResumeBackup, setFeatureEnable, setParentEnable, siteSize, refreshing, backupStatus,
        setIsScanInProgress, setIsCompleted, setProgress, setIsPaused, setIsCanceled, setIsOperationInProgress, setErrorMessages, isComponentActive, setRenderKey, verifyStatusBackup, setIsFetchingLogs, setRenderBackupDetail, renderKey, featureEnable, parentEnable, setOperationLoading, isOperationLoading, handleRefreshSiteSize, isLastLogIndex, setIsLastLogIndex, isFailed, setIsFailed

    }
}