import { useState, useEffect, useRef } from "react";
import { useHardeningAuditFeature } from "../Features/UseFeatures";
import {
    fetchLogs,
    instantScanning,
    handleHardeningAuditActions,
    verifyHardeningAuditStatus,
} from '../../../Pages/HardeningAudit/AuditServices/AuditServices';

export const useHardeningAudit = () => {
    const { enableHardeningAudit, refreshHardeningAuditStatus } = useHardeningAuditFeature();
    const [scanCompleted, setScanCompleted] = useState(false);
    const [logs, setLogs] = useState([]);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isOperationInProgress, setIsOperationInProgress] = useState(false);
    const [isCanceled, setIsCanceled] = useState(false);
    const [loading, setLoading] = useState(true);
    const [isPausing, setIsPausing] = useState(false);
    const [isCanceling, setIsCanceling] = useState(false);
    const [scanState, setScanState] = useState(null);
    const [scanType, setScanType] = useState(null);
    const [renderKey, setRenderKey] = useState(0);
    const [isFetchingLogs, setIsFetchingLogs] = useState(false);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [progress, setProgress] = useState(1);
    const progressBarColor = isScanInProgress
        ? "linear-gradient(to right, #007980, #85cbcf)"
        : (isPaused || !verifyStatus)
            ? "linear-gradient(to right, #616161, #d1d5db)"
            : "";
    const [showDetails, setShowDetails] = useState(false);
    const [showErrorAccordion, setShowErrorAccordion] = useState(false);
    const [errorMessages, setErrorMessages] = useState([]);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isOperationLoading, setOperationLoading] = useState(false);
    const [isLastLogIndex, setIsLastLogIndex] = useState(null);
    const isComponentActive = useRef(true);

    useEffect(() => {
        if (errorMessages.length > 0) {
            setShowErrorAccordion(true);
        }
    }, [errorMessages]);

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
        if (scanType !== "automatically" || scanType !== "completed") {
            setShowDetails(true);
        } else {
            setShowDetails(false);
        }
    }, [scanState, scanType]);

    useEffect(() => {
        if (isFetchingLogs) {
            setIsFetchingLogs(false);
            return;
        }

        if (scanState === "in-progress" && !isCompleted) {
            const delayFetchLogs = setTimeout(() => {
                fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, isLastLogIndex, setIsLastLogIndex, setFeatureEnable, setParentEnable);
            }, 4000);

            return () => clearTimeout(delayFetchLogs);
        }
    }, [scanState, isCompleted]);

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

    const handleInstantScan = async () => {
        setIsStarting(true);
        setScanCompleted(false);

        try {
            const fetchLogsWrapper = async () => {
                return fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, isLastLogIndex, setIsLastLogIndex, setFeatureEnable, setParentEnable);
            };
            const scanSuccessful = await instantScanning({ setScanCompleted, setErrorMessages, fetchLogs: fetchLogsWrapper });

            if (!scanSuccessful) {
                return;
            }

            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            setRenderKey((prevKey) => prevKey + 1);
        } catch (error) {
            // swallow — errors already pushed to errorMessages
        } finally {
            setIsStarting(false);
        }
    };

    const handleCancel = async () => {
        if (verifyStatus && !isPaused) {
            setOperationLoading(true);
        }
        setIsCanceling(true);
        await handleHardeningAuditActions({ scan_state: 'cancel', setIsScanInProgress, setIsPaused, setIsCompleted, setLogs, setIsLogsVisible, setErrorMessages });
        setIsCanceled(true);
        setIsLogsVisible(false);
        setIsPaused(false);
        setIsCanceling(false);
    };

    const handlePause = async () => {
        setOperationLoading(true);
        setIsPausing(true);
        await handleHardeningAuditActions({ scan_state: 'pause', setIsScanInProgress, setIsPaused, setIsCompleted, setLogs, setIsLogsVisible, setErrorMessages });
        await verifyAuditStatus();
        setVerifyStatus(true);
        setIsPausing(false);
    };

    const verifyAuditStatus = async () => {
        await verifyHardeningAuditStatus(setLoading, setVerifyStatus, setProgress, setScanState, setScanType, setErrorMessages, setFeatureEnable, setParentEnable);
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
        setProgress(1);
        setScanType(null);
        setScanState(null);
    };

    const handleToggleDetails = () => {
        if (scanType === "automatically") {
            setShowDetails((prev) => !prev);
        }
    };

    const handleResume = async () => {
        setIsModalOpen(true);
    };

    useEffect(() => {
        verifyAuditStatus();
    }, []);

    return {
        loading,
        showErrorAccordion,
        errorMessages,
        scanType,
        isCompleted,
        scanState,
        isCanceled,
        handleToggleDetails,
        showDetails,
        isPaused,
        handleInstantScan,
        handlePause,
        handleCancel,
        setIsLogsVisible,
        isLogsVisible,
        verifyStatus,
        isScanInProgress,
        isStarting,
        isOperationInProgress,
        handleResume,
        isPausing,
        isCanceling,
        resetAllStates,
        progress,
        progressBarColor,
        logs,
        setLogs,
        isModalOpen,
        setIsModalOpen,
        fetchLogs,
        setIsScanInProgress,
        setIsCompleted,
        setProgress,
        setIsOperationInProgress,
        isComponentActive,
        setIsFetchingLogs,
        setErrorMessages,
        setRenderKey,
        setIsPaused,
        setIsCanceled,
        renderKey,
        verifyAuditStatus,
        featureEnable,
        parentEnable,
        setParentEnable,
        setFeatureEnable,
        isOperationLoading,
        setOperationLoading,
        isLastLogIndex,
        setIsLastLogIndex,
        enableHardeningAudit,
        refreshHardeningAuditStatus,
    };
};
