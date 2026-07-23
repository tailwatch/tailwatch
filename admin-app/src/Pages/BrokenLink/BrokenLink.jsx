import React, { useState, useEffect, useRef } from 'react'
import Header from '../../Components/Header/Header';
import BrokenLinkLogs from './BrokenlinkLogs/BrokenLinkLogs';
import LinksButton from './LinksButton/LinksButton';
import LinkDetails from './LinkDetails/LinkDetails';
import ErrorAccordian from '../../Components/ErrorAccordian/ErrorAccordian';
import InfoBar from '../../Components/InfoBar/InfoBar';
import LogsScreen from '../../Components/LogsScreen/LogsScreen';
import ProgresBar from '../../Components/Progressbar/ProgresBar';
import ResumeModal from './ResumeModal/ResumeModal';
import { SkeletonButton } from '../../Components/Skeleton/LoaderSkeleton';
import { useBrokenLink } from '../../Components/Hooks/useBrokenLink/useBrokenLink';
import LoadingBar from 'react-top-loading-bar';
import { Spinner } from '../../Components/Spinner/Spinner';
import LockCard from '../../Components/LockCard/LockCard';
const BrokenLink = () => {
    const [isBarLoading, setIsBarLoading] = useState(false);
    const { loading, showErrorAccordion, errorMessages, scanType, isCompleted, scanState, isCanceled, handleToggleDetails, showDetails, isPaused, handleInstantScan, handlePause, handleCancel, setIsLogsVisible, setParentEnable, setFeatureEnable,
        isLogsVisible, verifyStatus, isScanInProgress, isStarting, isOperationInProgress, handleResume, isPausing, isCanceling, resetAllStates, progress, progressBarColor, logs, setLogs, isModalOpen, setIsModalOpen, fetchLogs, featureEnable, parentEnable,
        setIsScanInProgress, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, setIsFetchingLogs, setErrorMessages, setRenderKey, setIsPaused, setIsCanceled, renderKey, verifyLinkStatus, refreshBrokenLinkStatus, enableBrokenLink, isOperationLoading, setOperationLoading, isLicenseConnect, setIsLicenseConnect, isLastLogIndex, setIsLastLogIndex
    } = useBrokenLink();

    const loadingBarRef = useRef(null);
    const isChecking = useRef(false);

    const checkBrokenLinkStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshBrokenLinkStatus();
            setRenderKey((prevKey) => prevKey + 1);
            setIsLicenseConnect(null);
            setParentEnable(null);
            setFeatureEnable(null);
            await verifyLinkStatus();
        } finally {
            isChecking.current = false;
        }
    };

    useEffect(() => {
        const isAnyLoading = loading;

        if (isAnyLoading && !isBarLoading) {
            loadingBarRef.current.continuousStart();
            setIsBarLoading(true);
        } else if (!isAnyLoading && isBarLoading) {
            loadingBarRef.current.complete();
            setIsBarLoading(false);
        }
    }, [loading, isBarLoading]);

    return (
        <div>
            {isOperationLoading && (
                <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                    <Spinner />
                </div>
            )}
            <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
            <Header title="Broken Links" />
            {/* <LinkDetails /> */}
            <div className='px-4 pt-4'>
                {(featureEnable === false || parentEnable === false || isLicenseConnect=== true) && (<InfoBar type="info" message="Please Configure your Settings First" />)}
            </div>
            {loading ? (
                <div className='px-4'>
                    <SkeletonButton />
                </div>
            ) : (
                <>
                    <div className='px-4'>
                        {showErrorAccordion && (
                            <ErrorAccordian errors={errorMessages} />
                        )}
                        {scanType === "automatically" && !isCompleted && scanState !== "completed" && scanState !== "pause" && !isCanceled && (
                            <InfoBar type="progress"
                                message={<>Scan Broken Link is in progress by ' Auto Schedule '. <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; you can also View, Cancel, and Pause the process by clicking on the View Link. </>}
                            />
                        )}

                        {(scanType === "automatically" && scanState === "pause" && !isCanceled) ? (
                            <InfoBar type="paused"
                                message={<>Scan Broken Link is Paused by ' Auto Schedule '. Click on <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; and resume the process. </>}
                            />
                        ) : null}
                    </div>
                    <LinksButton isPaused={isPaused} isCompleted={isCompleted} handleInstantScan={handleInstantScan} handlePause={handlePause} handleCancel={handleCancel} toggleLogs={() => setIsLogsVisible(!isLogsVisible)} isLogsVisible={isLogsVisible} verifyStatus={verifyStatus} isScanInProgress={isScanInProgress} isStarting={isStarting} isOperationInProgress={isOperationInProgress} isCanceled={isCanceled} handleResume={handleResume} isPausing={isPausing} isCanceling={isCanceling} resetAllStates={resetAllStates} checkBrokenLinkStatus={checkBrokenLinkStatus} featureEnable={featureEnable} parentEnable={parentEnable} />
                    <div className='px-4 py-2'>
                        {(isScanInProgress || isPaused || !verifyStatus) && !isCanceled && scanType !== "automatically" && (<ProgresBar progress={progress} bgColor={progressBarColor} />)}
                        <LogsScreen logs={logs} setLogs={setLogs} isPaused={isPaused} isCanceled={isCanceled} isLogsVisible={isLogsVisible} isCompleted={isCompleted} cancelMessage="Broken Link is canceled!" successMessage="Broken Link is Completed successfully!" />
                    </div>
                </>
            )}
            {(parentEnable === false || isLicenseConnect === true) && <LockCard featureName="Broken Link"  featureDescription="Automatically scan your site for broken links at regular intervals and maintain a history of results. Instantly fix issues with one-click 301 redirect rules without manual effort or SEO impact." featureId={enableBrokenLink?.featureId} isLocked={isLicenseConnect} isActive={enableBrokenLink?.isActiveState} afterToggleCallback={checkBrokenLinkStatus} />}
            <ResumeModal isLastLogIndex={isLastLogIndex} setIsLastLogIndex={setIsLastLogIndex} setOperationLoading={setOperationLoading} isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} fetchLogs={fetchLogs} handleCancel={handleCancel} setIsScanInProgress={setIsScanInProgress} setLogs={setLogs} setIsCompleted={setIsCompleted} setProgress={setProgress} setIsOperationInProgress={setIsOperationInProgress} isComponentActive={isComponentActive} setIsLogsVisible={setIsLogsVisible} setIsFetchingLogs={setIsFetchingLogs}
                setErrorMessages={setErrorMessages} setRenderKey={setRenderKey} setIsPaused={setIsPaused} setIsCanceled={setIsCanceled} handleInstantScan={handleInstantScan} />
            <BrokenLinkLogs key={renderKey} setParentEnable={setParentEnable} setIsLicenseConnect={setIsLicenseConnect} setFeatureEnable={setFeatureEnable} />
        </div>
    )
}

export default BrokenLink