import React, { useState, useEffect, useRef } from "react";
import { graphData, pieChartData, logsCount, scanningFeaturesDetails, verifySslDetail, startSecurityFeature, executeCronIfFailed } from '../../../Pages/Home/HomeServices/HomeServices';
import toast from "react-hot-toast";

export const useHome = () => {
    const ref = useRef(null);
    const [loadingLogs, setLoadingLogs] = useState(true);
    const [loadingVerify, setLoadingVerify] = useState(true);
    const [loadingDisk, setLoadingDisk] = useState(false);
    const [diskData, setDiskData] = useState(null);
    const [isValue, setValue] = useState(null);
    const [notificationsData, setNotificationsData] = useState([]);
    const [widgetData, setWidgetData] = useState([]);
    const [verifySslData, setVerifysslData] = useState([]);
    const [isDisabled, setIsDisabled] = useState(false);
    const [searchTerm, setSearchTerm] = useState("");
    const [loadingGraph, setLoadingGraph] = useState(true);
    const [loadingScanning, setLoadingScanning] = useState(true);
    const [scanningFeatures, setScanningFeatures] = useState([]);
    const [verifyingFeatures, setVerifyingFeatures] = useState(false);

    const fetchData = async () => {
        setLoadingDisk(true);
        const data = await pieChartData();
        if (data) {
            const transformedData = {
                ...data, database: { totalSize: data.total_size, tables: data.tables, },
            };
            setDiskData(transformedData);
        }
        setLoadingDisk(false);
    };


    const pollGraphData = async () => {
        const result = await graphData(setLoadingGraph, setValue, setNotificationsData);

        if (result && result.process_running && result.process_running === true) {
            setTimeout(pollGraphData, 4000);
        } else {
            setLoadingGraph(false);
        }
    };

    const fetchCalculateData = async () => {
        setLoadingGraph(true);
        const result = await graphData(setLoadingGraph, setValue, setNotificationsData);

        if (result?.process_running) {
            pollGraphData();
        } else {
            setLoadingGraph(false);
        }
    };



    useEffect(() => {
        const fetchGraphData = async () => {
            logsCount((data) => {
                const updatedData = data.map(widget => ({
                    ...widget,
                    message: widget.message
                        ? `This ${widget.message.replace(/Feature/i, 'feature')}`
                        : widget.message
                }));
                setWidgetData(updatedData);


            }, setLoadingLogs);

            // Fetch SSL details
            verifySslDetail(setVerifysslData, setLoadingVerify, setIsDisabled);
            fetchCalculateData();
            scanningFeaturesDetails(setScanningFeatures, setLoadingScanning);


        };

        fetchGraphData();
        fetchData();
    }, []);



    // for Top Loading Bar 
    useEffect(() => {
        ref.current.continuousStart();
    }, []);

    useEffect(() => {
        if (!loadingLogs && !loadingVerify && !loadingDisk) {
            ref.current.complete();
        }
    }, [loadingLogs, loadingVerify, loadingDisk]);



    const handleCalculateScore = async () => {
        let cronInterval = null;
        let isPolling = false;

        try {
            setVerifyingFeatures(true);
            setLoadingGraph(true);
            const startResponse = await startSecurityFeature();

            if (!startResponse || !startResponse.data.success) {
                toast.error('Failed to start security features process');
                setVerifyingFeatures(false);
                setLoadingGraph(false);
                return;
            }

            isPolling = true;

            const pollGraphData = async () => {
                try {
                    const data = await graphData(setLoadingGraph, setValue, setNotificationsData);

                    if (data && data.cron_running === false) {
                        if (!cronInterval) {
                            
                            await executeCronIfFailed();
                            cronInterval = setInterval(async () => {
                                
                                await executeCronIfFailed();
                            }, 10000);
                        }
                    } else if (data && data.cron_running === true) {
                        if (cronInterval) {
                            
                            clearInterval(cronInterval);
                            cronInterval = null;
                        }
                    }

                    if (data && data.process_running === true && data.process_running === true) {
                        
                        setTimeout(pollGraphData, 4000);
                    } else {
                        
                        if (cronInterval) {
                            clearInterval(cronInterval);
                            cronInterval = null;
                        }
                        isPolling = false;
                        setVerifyingFeatures(false);
                        setLoadingGraph(false);
                    }
                } catch (error) {
                    isPolling = false;
                    setVerifyingFeatures(false);
                    setLoadingGraph(false);
                    if (cronInterval) {
                        clearInterval(cronInterval);
                        cronInterval = null;
                    }
                }
            };

            await pollGraphData();

        } catch (error) {
            if (cronInterval) {
                clearInterval(cronInterval);
                cronInterval = null;
            }
            setVerifyingFeatures(false);
            setLoadingGraph(false);
        }
    };


    return {
        searchTerm, setSearchTerm, ref, loadingLogs, widgetData, loadingGraph, isValue, setLoadingGraph, setValue, setNotificationsData, notificationsData, diskData, fetchData, loadingDisk, loadingVerify, verifySslData,
        setVerifysslData, setLoadingVerify, isDisabled, setIsDisabled, loadingScanning, scanningFeatures, handleCalculateScore, verifyingFeatures
    }
}



