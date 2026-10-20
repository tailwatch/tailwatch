import { useEffect, useRef } from 'react';
import BackupActions from './BackupActions/BackupActions';
import ProgresBar from '../../Components/Progressbar/ProgresBar';
import Header from '../../Components/Header/Header';
import ResumeModal from './ResumeModal/ResumeModal';
import BackupFiles from './BackupFiles/BackupFiles';
import { SkeletonButton } from '../../Components/Skeleton/LoaderSkeleton';
import BackupDetails from './BackupDetails/BackupDetails';
import InfoBar from '../../Components/InfoBar/InfoBar';
import ErrorAccordian from '../../Components/ErrorAccordian/ErrorAccordian';
import LoadingBar from 'react-top-loading-bar';
import LockCard from '../../Components/LockCard/LockCard';
import { useBackup } from '../../Components/Hooks/useBackup/useBackup';
import { Spinner } from '../../Components/Spinner/Spinner';
import LogsScreen from '../../Components/LogsScreen/LogsScreen';

const NewBackup = () => {

    const { loading, setLoading, enableBackup, renderBackupDetails, showErrorAccordion, errorMessages, scanType, isCompleted, scanState, verifyStatus, isScanInProgress, isStarting, isOperationInProgress, isCanceled, isModalOpen, setIsModalOpen, fetchLogs,
        isPaused, isPausing, isCanceling, resetAllStates, checkBackupStatus, progress, progressBarColor, logs, setLogs, showDetails, setIsLogsVisible, isLogsVisible, handleToggleDetails, handleInstantScan, handlePauseBackup, handleCancelBackup, handleResumeBackup, setFeatureEnable, setParentEnable, siteSize, refreshing, handleRefreshSiteSize, backupStatus,
        setIsScanInProgress, setIsCompleted, setProgress, setIsPaused, setIsCanceled, setIsOperationInProgress, setErrorMessages, isComponentActive, setRenderKey, verifyStatusBackup, setIsFetchingLogs, setRenderBackupDetail, renderKey, featureEnable, parentEnable, setOperationLoading, isOperationLoading, isLastLogIndex, setIsLastLogIndex, isFailed, setIsFailed } = useBackup();
   
    const loadingBarRef = useRef(null);
    useEffect(() => {
        if (loading) {
            loadingBarRef.current.continuousStart();
        } else {
            loadingBarRef.current.complete();
        }
    }, [loading]);   

    return (
        <div>
            {isOperationLoading && (
                <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                    <Spinner />
                </div>
            )}
            <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
            <Header title="Backup Vault" />

            <div className="px-4 pt-4">
                <BackupDetails key={renderBackupDetails} featureEnable={featureEnable} parentEnable={parentEnable} setFeatureEnable={setFeatureEnable} setParentEnable={setParentEnable} />
                {showErrorAccordion && (
                    <ErrorAccordian errors={errorMessages} />
                )}
                <div>
                    {(loading) ? (
                        <SkeletonButton />
                    ) : (
                        <>
                            {isFailed && (
                                <InfoBar type="danger"
                                    message="The last backup stopped before it finished and was marked as failed. Your previous backups are untouched — start a new one when you are ready, and check the log below for where it stopped."
                                />
                            )}

                            {scanType === "automatically" && !isCompleted && !isFailed && scanState !== "completed" && scanState !== "pause" && !isCanceled && (
                                <InfoBar type="progress"
                                    message={<> Backup is in progress by ' Auto Schedule '. <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; you can also View, Cancel, and Pause the process by clicking on the View Link. </>}
                                />
                            )}

                            {(scanType === "automatically" && scanState === "pause" && !isCanceled) ? (
                                <InfoBar type="paused"
                                    message={<> Backup is Paused by ' Auto Schedule '. Click on <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; and resume the process. </>}
                                />
                            ) : null}
                            {showDetails && (
                                <>
                                    <div className="">
                                        <BackupActions refreshing={refreshing} siteSize={siteSize} handleRefreshSiteSize={handleRefreshSiteSize} parentEnable={parentEnable} featureEnable={featureEnable} isPaused={isPaused} isCompleted={isCompleted} handleInstantScan={handleInstantScan} handlePauseBackup={handlePauseBackup} handleCancelBackup={handleCancelBackup} handleResumeBackup={handleResumeBackup} toggleLogs={() => setIsLogsVisible(!isLogsVisible)} isLogsVisible={isLogsVisible} verifyStatus={verifyStatus} isScanInProgress={isScanInProgress} isStarting={isStarting} isOperationInProgress={isOperationInProgress} isCanceled={isCanceled} IsEnableBackup={enableBackup?.backupFeature} isPausing={isPausing} isCanceling={isCanceling} featureId={enableBackup.featureId} resetAllStates={resetAllStates} checkBackupStatus={checkBackupStatus} isFailed={isFailed} />
                                    </div>

                                    {(isScanInProgress || isPaused || isFailed || !verifyStatus) && !isCanceled && scanType !== "automatically" && (<ProgresBar progress={progress} bgColor={progressBarColor} />)}
                                    {/* A failure ends the run the way a cancel does, so it takes the same terminal
                                        banner — in its own words, because "canceled" would credit the operator with
                                        a decision they never made. */}
                                    <LogsScreen logs={logs} setLogs={setLogs} isPaused={isPaused} isCanceled={isCanceled || isFailed} isLogsVisible={isLogsVisible} isCompleted={isCompleted} cancelMessage={isFailed ? "Backup failed — it stopped before it finished." : "Backup is canceled!"} successMessage="Backup is Completed successfully!" />
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
            <ResumeModal isLastLogIndex={isLastLogIndex} setIsLastLogIndex={setIsLastLogIndex} setOperationLoading={setOperationLoading} isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} fetchLogs={fetchLogs} setIsScanInProgress={setIsScanInProgress} setLogs={setLogs} setIsCompleted={setIsCompleted} setProgress={setProgress} setIsOperationInProgress={setIsOperationInProgress} isComponentActive={isComponentActive} setIsLogsVisible={setIsLogsVisible} setRenderKey={setRenderKey} checkBackupStatus={verifyStatusBackup} setIsFetchingLogs={setIsFetchingLogs}
                setRenderBackupDetail={setRenderBackupDetail} setErrorMessages={setErrorMessages} setIsPaused={setIsPaused} setIsCanceled={setIsCanceled} setIsFailed={setIsFailed} handleInstantScan={handleInstantScan} />
            {parentEnable === false && <LockCard featureName="Backup Vault" featureDescription="Back up your whole website, files and database together, on a schedule." featureId={enableBackup.featureId} isActive={enableBackup.isActiveState} afterToggleCallback={checkBackupStatus} />}
            <div className='px-4'>
                <BackupFiles key={renderKey} setErrorMessages={setErrorMessages} checkBackupStatus={checkBackupStatus} backupStatus={backupStatus} />
            </div>
        </div>
    );
};

export default NewBackup;