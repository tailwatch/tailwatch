import { useEffect, useState, useRef, useCallback } from "react";
import { resetAllSettings, resetSettingStatus, verifyRunningProcess } from "../../../Pages/Settings/GeneralTab/GeneralTabServices/GeneralTabServices";
import { setResetDefault } from "../../Redux/ScanSlice";
import { useDispatch } from "react-redux";

export const useGeneralSettings = ({ setIsRunning, activeTab }) => {
    const [loading, setLoading] = useState(false);
    const [isReset, setIsReset] = useState(false);
    const [resetId, setResetId] = useState(null);
    const [resetProgress, setResetProgress] = useState(0);
    const [isInitializing, setIsInitializing] = useState(true);
    const [isResetComplete, setIsResetComplete] = useState(null);
    const intervalRef = useRef(null);
    const hasInitialized = useRef(false);
    const dispatch = useDispatch();

    const startResetProgressMonitoring = useCallback((resetIdToUse) => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
        }

        setLoading(true);
        intervalRef.current = setInterval(async () => {
            try {
                const result = await resetSettingStatus(resetIdToUse);
                if (result.details?.reset_id) {
                    setResetId(result.details.reset_id);
                }
                const currentIndex = result.details?.current_index ?? result.current_index;
                const total = result.details?.total ?? result.total;

                if (currentIndex !== undefined && total !== undefined && total > 0) {
                    const progress = Math.round((currentIndex / total) * 100);
                    setResetProgress(progress);
                }
                if (result.isCompleted) {
                    clearInterval(intervalRef.current);
                    setLoading(false);
                    setIsResetComplete('completed');
                    setResetProgress(100);
                    setResetId(null);
                }
            } catch (error) {
                console.error('Error monitoring reset progress:', error);
                clearInterval(intervalRef.current);
                setLoading(false);
                setResetProgress(0);
            }
        }, 4000);
    }, []);

    const verifyProcess = useCallback(async () => {
        if (hasInitialized.current) return;

        try {
            setIsInitializing(true);
            const result = await verifyRunningProcess({ setIsRunning });
            if (result.running === 'reset' && result.details?.reset_id) {
                dispatch(setResetDefault(true));
                setResetId(result.details.reset_id);
                const currentIndex = result.details?.current_index ?? result.current_index;
                const total = result.details?.total ?? result.total;

                if (currentIndex !== undefined && total !== undefined && total > 0) {
                    const progress = Math.round((currentIndex / total) * 100);
                    setResetProgress(progress);
                }
                startResetProgressMonitoring(result.details.reset_id);
            }

            hasInitialized.current = true;
        } catch (error) {
            console.error('Error verifying process:', error);
        } finally {
            setIsInitializing(false);
            dispatch(setResetDefault(false));
        }
    }, [setIsRunning, startResetProgressMonitoring, dispatch]);

    useEffect(() => {
        if (activeTab === 'general' && !hasInitialized.current) {
            verifyProcess();
        }
    }, [activeTab, verifyProcess]);

    useEffect(() => {
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, []);

    const handleResetAllSettings = async ({ resetSettings, resetData } = {}) => {
        if (!resetSettings && !resetData) {
            return;
        }
        try {
            const newResetId = await resetAllSettings({ setIsReset, setLoading, setResetId, resetSettings, resetData });
            if (newResetId) {
                setLoading(true);
                dispatch(setResetDefault(true));
                setResetProgress(0);
                await new Promise(resolve => setTimeout(resolve, 100));
                startResetProgressMonitoring(newResetId);
            } else {
                return;
            }
        } catch (error) {
            setLoading(false);
            setResetProgress(0);
            console.error('Error in handleResetAllSettings:', error);
            dispatch(setResetDefault(false));
        }
    };

    const closeCompletionModal = () => {
        setIsResetComplete(null);
        dispatch(setResetDefault(false));
        setResetProgress(0);
    };

    return {
        handleResetAllSettings,
        loading,
        isReset,
        resetProgress,
        isInitializing,
        isResetComplete,
        closeCompletionModal,
    };
};
