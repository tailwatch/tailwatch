import { useState, useEffect, useRef, useCallback } from "react";
import { instantScanning, fetchLogs, verifyScannerStatus } from "../../../Pages/Scanner/ScannerServices/ScannerServices.jsx";
import { UseScannerFeature } from "../Features/UseFeatures.jsx";
import { toast } from 'react-toastify';
import { useDispatch } from "react-redux";
import { setMalwareStarted } from "../../Redux/ScanSlice.jsx";

export const useScanner = () => {
    const { enableScanner, refreshScannerStatus } = UseScannerFeature();
    const [scanCompleted, setScanCompleted] = useState(false);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [errorMessages, setErrorMessages] = useState([]);
    const [scanState, setScanState] = useState(null);
    const [scanType, setScanType] = useState(null);
    const [verifyLoading, setVerifyLoading] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [logs, setLogs] = useState([]);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [showDetails, setShowDetails] = useState(false);
    const [progress, setProgress] = useState(1);
    const [scannerStates, setScannerStates] = useState({ filesIntegrity: true, malwareScanner: true, websiteBackup: true });
    const [infoMessage, setInfoMessage] = useState("");
    const [trigerScannedDetails, setTrigerScannedDetails] = useState(0);
    const [showErrorAccordion, setShowErrorAccordion] = useState(false);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const [islicenseReq, setIsLicenseReq] = useState(null);
    const [scannedData, setScannedData] = useState({ totalMalicious: 0, totalResources: 0 });
    const [isLastLogIndex,setIsLastLogIndex] = useState(null);
    const [scanError, setScanError] = useState(null);
    const [isCanceled, setIsCanceled] = useState(false);
    const [cancelMessage, setCancelMessage] = useState("");

    const dispatch = useDispatch();
    const isComponentActive = useRef(true);
    const isChecking = useRef(false);
    const loadingBarRef = useRef(null);
    const pollingRef = useRef(false); // Prevent multiple concurrent polling loops

    const progressBarColor = isScanInProgress
        ? "linear-gradient(to right, #007980, #85cbcf)"
        : (!verifyStatus)
            ? "linear-gradient(to right, #616161, #d1d5db)"
            : "";

    // Component active/inactive lifecycle
    useEffect(() => {
        isComponentActive.current = true;
        return () => {
            isComponentActive.current = false;
        };
    }, []);

    // Loading bar effect
    useEffect(() => {
        if (loadingBarRef.current) {
            if (verifyLoading || isLoading) {
                loadingBarRef.current.continuousStart();
            } else {
                loadingBarRef.current.complete();
            }
        }
    }, [verifyLoading, isLoading]);

    // Error messages effect
    useEffect(() => {
        if (errorMessages.length > 0) {
            setShowErrorAccordion(true);
        }
    }, [errorMessages]);

    // Scan state effect
    useEffect(() => {
        if (scanState === "in-progress") {
            setIsScanInProgress(true);
            dispatch(setMalwareStarted(true));
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
    }, [scanState, scanType, dispatch]);

    // Fetch logs effect
    useEffect(() => {
        if (scanState === "in-progress" && !isCompleted) {
            // Prevent multiple polling loops from running concurrently
            if (pollingRef.current) {
                
                return;
            }

            pollingRef.current = true;

            const delayFetchLogs = setTimeout(() => {
                fetchLogs(
                    setLogs,
                    setIsCompleted,
                    true,
                    isComponentActive,
                    setProgress,
                    setIsScanInProgress,
                    setIsLogsVisible,
                    setErrorMessages,
                    setTrigerScannedDetails,
                    (isInProgress) => dispatch(setMalwareStarted(isInProgress)),
                    isLastLogIndex,
                    setIsLastLogIndex,
                    setScanError,
                    setIsCanceled,
                    setCancelMessage
                );
            }, 4000);

            return () => {
                clearTimeout(delayFetchLogs);
                pollingRef.current = false; // Reset on cleanup
            };
        } else {
            // Reset polling flag when scan is not in progress
            pollingRef.current = false;
        }
    }, [scanState, isCompleted, dispatch]); // Removed isLastLogIndex to prevent re-trigger loop

    const checkVerifyMalwareStatus = useCallback(async () => {
        await verifyScannerStatus({
            setVerifyLoading,
            setProgress,
            setScanState,
            setScanType,
            setScannerStates,
            setInfoMessage,
            setParentEnable,
            setFeatureEnable,
            setIsLicenseConnect,
            setIsLicenseReq
        });
    }, []);

    // Initial verification
    useEffect(() => {
        checkVerifyMalwareStatus();
    }, [checkVerifyMalwareStatus]);

    // Initial loading timer
    useEffect(() => {
        const timer = setTimeout(() => {
            setIsLoading(false);
        }, 500);
        return () => clearTimeout(timer);
    }, []);

    const handleScannedData = useCallback((data) => {
        setScannedData(data);
    }, []);

    const handleInstantScan = async (dryRun) => {
        setIsStarting(true);
        setScanCompleted(false);
        setScanError(null); // Clear any previous errors

        try {
            // Clear any stale cancel-from-previous-scan banner when a new scan starts.
            setIsCanceled(false);
            setCancelMessage("");

            const scanSuccessful = await instantScanning(
                setScanCompleted,
                setErrorMessages,
                () => fetchLogs(
                    setLogs,
                    setIsCompleted,
                    true,
                    isComponentActive,
                    setProgress,
                    setIsScanInProgress,
                    setIsLogsVisible,
                    setErrorMessages,
                    setTrigerScannedDetails,
                    (isInProgress) => dispatch(setMalwareStarted(isInProgress)),
                    isLastLogIndex,
                    setIsLastLogIndex,
                    setScanError,
                    setIsCanceled,
                    setCancelMessage
                ),
                dryRun,
                setScanError
            );

            if (!scanSuccessful) {
                return;
            }

            // Backend confirmed the scan is running — now flip the in-progress UI
            setIsScanInProgress(true);
            dispatch(setMalwareStarted(true));
            setIsCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
        } catch (error) {
        } finally {
            setIsStarting(false);
        }
    };

    const checkScannerStatus = useCallback(async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshScannerStatus();
            setTrigerScannedDetails((prevKey) => prevKey + 1);
            setFeatureEnable(null);
            setParentEnable(null);
            setIsLicenseConnect(null);
            setIsLicenseReq(null);
            await checkVerifyMalwareStatus();
        } finally {
            isChecking.current = false;
        }
    }, [refreshScannerStatus, checkVerifyMalwareStatus]);

    const resetAllStates = () => {
        setLogs([]);
        setIsCompleted(false);
        setIsLogsVisible(false);
        setIsScanInProgress(false);
        setScanCompleted(false);
        setVerifyStatus(true);
        setScanType(null);
        setScanState(null);
        setProgress(1);
        setIsCanceled(false);
        setCancelMessage("");
    };

    return {
        // States
        scanCompleted,
        isCompleted,
        isLogsVisible,
        verifyStatus,
        errorMessages,
        scanState,
        scanType,
        verifyLoading,
        isLoading,
        logs,
        isScanInProgress,
        isStarting,
        showDetails,
        progress,
        scannerStates,
        infoMessage,
        trigerScannedDetails,
        showErrorAccordion,
        featureEnable,
        parentEnable,
        isLicenseConnect,
        islicenseReq,
        scannedData,
        progressBarColor,
        setIsLicenseConnect,
        setIsLicenseReq,
        scanError,
        isCanceled,
        cancelMessage,

        // Refs
        loadingBarRef,

        // Feature data
        enableScanner,

        // Setters
        setLogs,
        setIsLogsVisible,
        setScanError,

        // Functions
        handleScannedData,
        handleInstantScan,
        checkVerifyMalwareStatus,
        checkScannerStatus,
        resetAllStates
    };
};
