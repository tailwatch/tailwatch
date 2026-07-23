import { useState, useEffect, useRef } from "react";
import { UseDatabaseOptimizer } from '../Features/UseFeatures';
import { instantScanning, fetchLogs, verifyDatabaseOptimizeStatus, onPauseOrCancel, checkOptimizeStatus, getDbOptimizerStatus } from '../../../Pages/DatabaseOptimizer/DatabaseServices/DatabaseServices';

export const useDatabaseOptimizer = () => {
    const [scanCompleted, setScanCompleted] = useState(false);
    const [logs, setLogs] = useState([]);
    const [isCompleted, setIsCompleted] = useState(false);
    const [isLogsVisible, setIsLogsVisible] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [verifyStatus, setVerifyStatus] = useState(true);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isOperationInProgress, setIsOperationInProgress] = useState(false);
    const [isCanceled, setIsCanceled] = useState(false);        
    const [verifyLoading, setVerifyLoading] = useState(true);
    const [isPausing, setIsPausing] = useState(false);
    const [isCanceling, setIsCanceling] = useState(false);
    const [fetchTrigger, setFetchTrigger] = useState(0);
    const [scanState, setScanState] = useState(null);
    const { enableDatabase, refreshDatabaseStatus } = UseDatabaseOptimizer();
    const [scanType, setScanType] = useState(null);
    const [showDetails, setShowDetails] = useState(false);
    const [isScanInProgress, setIsScanInProgress] = useState(false);
    const [isStarting, setIsStarting] = useState(false);
    const [progress, setProgress] = useState(1);
    const [renderKey, setRenderKey] = useState(0);
    const [isFetchingLogs, setIsFetchingLogs] = useState(false);
    const [showErrorAccordion, setShowErrorAccordion] = useState(false);
    const [errorMessages, setErrorMessages] = useState([]);
    const [processType, setProcessType] = useState(null);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isOperationLoading, setOperationLoading] = useState(false);
    const progressBarColor = isScanInProgress ? "#007980" : (isPaused || !verifyStatus) ? "#616161" : "";
    const [checkTableStatus, setCheckTableStatus] = useState(false);
    const [isLastLogIndex,setIsLastLogIndex] = useState(null);
    const [optimizerStatus, setOptimizerStatus] = useState({ steps: {}, schedule: null, optimizerEnabled: null, licenseConnected: false, proPluginActive: false, currentPlan: null });
    const [optimizerStatusLoading, setOptimizerStatusLoading] = useState(true);
    const isComponentActive = useRef(true);
    const isChecking = useRef(false);

    const fetchOptimizerStatus = async () => {
        setOptimizerStatusLoading(true);
        const data = await getDbOptimizerStatus();
        setOptimizerStatus(data);
        if (data.optimizerEnabled !== null) {
            setParentEnable(data.optimizerEnabled);
            setFeatureEnable(data.optimizerEnabled);
        }
        setOptimizerStatusLoading(false);
    };

    useEffect(() => {
        if (errorMessages.length > 0) {
            setShowErrorAccordion(true);
        }
    }, [errorMessages]);

    useEffect(() => {
        if (scanState === "in-progress") {
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCanceling(false);
            setIsCanceled(false);
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

                fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsLogsVisible, setIsScanInProgress, setProgress, setRenderKey, setIsPausing, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
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

        try {
            const scanSuccessful = await instantScanning(
                setScanCompleted, () => fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsLogsVisible, setIsScanInProgress, setProgress, setRenderKey, setIsPausing, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex));

            if (!scanSuccessful) {
                return;
            }

            // Backend confirmed the optimization is running — now flip the in-progress UI
            setLogs([]);
            setIsScanInProgress(true);
            setIsPaused(false);
            setIsCanceling(false);
            setIsCanceled(false);
            setIsCompleted(false);
            setProgress(1);
            setIsLogsVisible(true);
            setRenderKey((prevKey) => prevKey + 1);
            setFetchTrigger(prev => !prev);
        } catch (error) {
            console.error("Error fetching Database Tables:", error);
        } finally {
            setIsStarting(false);
        }
    };

    const handleOptimizeAction = async (scan_state) => {
        onPauseOrCancel({ scan_state, setIsLogsVisible, setLogs, setIsScanInProgress, setIsCompleted, setIsPaused, setErrorMessages })
    };

    const handleCancelOptimize = async () => {
        if (verifyStatus && !isPaused) {
            setOperationLoading(true);
        }
        setIsCanceling(true);
        await handleOptimizeAction("cancel")        
        setIsCanceled(true)
        setIsScanInProgress(false);
        setIsLogsVisible(false);
        setIsPausing(false);
        setIsPaused(false);
        setIsCompleted(false);
        setRenderKey((prevKey) => prevKey + 1);
    };

    const handlePauseOptimize = async () => {
        setOperationLoading(true);
        setIsPausing(true);
        await handleOptimizeAction("pause");
        // await verifyDatabaseStatus();
        // setLoading(true);
        setIsPaused(true);
        setVerifyStatus(true);
        // setRenderKey((prevKey) => prevKey + 1);
        // setLoading(false);
    };

    const handleToggleDetails = () => {
        if (scanType === "automatically") {
            setShowDetails((prev) => !prev);
        }
    };

    const verifyDatabaseStatus = async () => {
        await checkOptimizeStatus({setCheckTableStatus});
        await verifyDatabaseOptimizeStatus({ setProgress, setScanState, setProcessType, setScanType, setVerifyStatus, setVerifyLoading, setFeatureEnable, setParentEnable });
    }

    // for LockCard 
    const checkDatabaseStatus = async () => {
        if (isChecking.current) return; // Exit if already running
        isChecking.current = true; // Set flag to true
        try {
            await refreshDatabaseStatus();
            setFetchTrigger((prevKey) => prevKey + 1);
            setRenderKey((prevKey) => prevKey + 1);
            setParentEnable(null);
            setFeatureEnable(null);
            setVerifyLoading(true);
            setCheckTableStatus(false);
            await verifyDatabaseStatus();            
        } finally {
            isChecking.current = false;
        }
    };

    const handleResumeOptimize = async () => {
        setIsModalOpen(true);
    };

    const resetAllStates = async () => {
        await checkOptimizeStatus({setCheckTableStatus});
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

    useEffect(() => {
        verifyDatabaseStatus();
    }, [])

    useEffect(() => {
        fetchOptimizerStatus();
    }, [fetchTrigger, renderKey])

    useEffect(() => {
        if (isCompleted) {
            setShowDetails(true);
            setVerifyStatus(true);
        }
    }, [isScanInProgress, isCompleted]);    

    return {
        verifyLoading, enableDatabase, showErrorAccordion, errorMessages, fetchTrigger, scanType, isCompleted, scanState, isCanceled, handleToggleDetails, showDetails, handleInstantScan,
        setIsLogsVisible, isLogsVisible, isScanInProgress, isStarting, isOperationInProgress, handleResumeOptimize, handleCancelOptimize, handlePauseOptimize, isPaused, isPausing, verifyStatus, isCanceling, resetAllStates, processType,
        checkDatabaseStatus, setIsScanInProgress, setLogs, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, fetchLogs, setRenderKey, setIsFetchingLogs, verifyDatabaseStatus,
        progress, progressBarColor, logs, setIsModalOpen, setIsPaused, setParentEnable, setFeatureEnable, setIsCanceled, renderKey, isModalOpen, setIsPausing, featureEnable, parentEnable, setOperationLoading, isOperationLoading, checkTableStatus, isLastLogIndex, setIsLastLogIndex,
        optimizerStatus, optimizerStatusLoading, fetchOptimizerStatus
    }

}
