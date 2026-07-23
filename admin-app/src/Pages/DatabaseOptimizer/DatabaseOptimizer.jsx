import { useRef, useEffect } from 'react'
import Header from '../../Components/Header/Header';
import DatabaseActions from './DatabaseActions/DatabaseActions';
import DatabaseTables from './DatabaseTables/DatabaseTables';
import ProgresBar from '../../Components/Progressbar/ProgresBar';
import ResumeModal from './ResumeModal/ResumeModal';
import OptimizeDetail from './DatabaseOptimizeDetail/OptimizeDetail';
import LogsScreen from '../../Components/LogsScreen/LogsScreen';
import InfoBar from '../../Components/InfoBar/InfoBar';
import { SkeletonButton } from '../../Components/Skeleton/LoaderSkeleton';
import ErrorAccordian from '../../Components/ErrorAccordian/ErrorAccordian';
import LoadingBar from 'react-top-loading-bar';
import LockCard from '../../Components/LockCard/LockCard';
import { Spinner } from '../../Components/Spinner/Spinner';
import { useDatabaseOptimizer } from '../../Components/Hooks/useDatabaseOptimizer/useDatabaseOptimizer';

const DatabaseOptimizer = () => {

  const { verifyLoading, enableDatabase, showErrorAccordion, errorMessages, fetchTrigger, scanType, isCompleted, scanState, isCanceled, handleToggleDetails, showDetails, handleInstantScan,
    setIsLogsVisible, isLogsVisible, isScanInProgress, isStarting, isOperationInProgress, handleResumeOptimize, handleCancelOptimize, handlePauseOptimize, isPaused, isPausing, verifyStatus, isCanceling, resetAllStates, processType,
    checkDatabaseStatus, setIsScanInProgress, setLogs, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, fetchLogs, setRenderKey, setIsFetchingLogs, verifyDatabaseStatus,
    progress, progressBarColor, parentEnable, setParentEnable, setFeatureEnable, logs, setIsModalOpen, setIsPaused, setIsCanceled, renderKey, isModalOpen, setIsPausing, featureEnable, setOperationLoading, isOperationLoading, checkTableStatus, isLastLogIndex, setIsLastLogIndex,
    optimizerStatus, optimizerStatusLoading
  } = useDatabaseOptimizer();

  const loadingBarRef = useRef(null);

  useEffect(() => {
    if (verifyLoading) {
      loadingBarRef.current.continuousStart();
    } else {
      loadingBarRef.current.complete();
    }
  }, [verifyLoading]);

  return (
    <div>
      {isOperationLoading && (
        <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
          <Spinner />
        </div>
      )}
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
      <Header title="Database Optimizer" />
      <div className='p-4'>

        {showErrorAccordion && (
          <div className='mt-[10px]'>
            <ErrorAccordian errors={errorMessages} />
          </div>
        )}
        <OptimizeDetail schedule={optimizerStatus?.schedule} optimizerEnabled={optimizerStatus?.optimizerEnabled} loading={optimizerStatusLoading} />

        <div>
          {(verifyLoading) ? (
            <SkeletonButton />
          ) : (
            <>
              {scanType === "automatically" && !isCompleted && scanState !== "pause" && !isCanceled && (
                <InfoBar type="progress"
                  message={<> Database Optimization is in progress by ' Auto Schedule '. <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2"> {showDetails ? "Hide" : "View "} </button> &nbsp; you can also View, Cancel, and Pause the process by clicking on the View Link. </>}
                />
              )}

              {(scanType === "automatically" && scanState === "pause" && !isCanceled) ? (
                <InfoBar type="paused" message={<> Database Optimization is Paused by ' Auto Schedule '. Click on <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; and resume the process. </>} />
              ) : null}
              {showDetails && (
                <>
                  <DatabaseActions checkTableStatus={checkTableStatus} handleInstantScan={handleInstantScan} featureEnable={featureEnable} isPaused={isPaused} isCompleted={isCompleted} toggleLogs={() => setIsLogsVisible(!isLogsVisible)} isLogsVisible={isLogsVisible}
                    isScanInProgress={isScanInProgress} isStarting={isStarting} isOperationInProgress={isOperationInProgress} isCanceled={isCanceled} handleResumeOptimize={handleResumeOptimize} handleCancelOptimize={handleCancelOptimize}
                    handlePauseOptimize={handlePauseOptimize} isPausing={isPausing} verifyStatus={verifyStatus} isCanceling={isCanceling} resetAllStates={resetAllStates} processType={processType} checkDatabaseStatus={checkDatabaseStatus}
                  />
                  {(isScanInProgress || isPaused || !verifyStatus) && !isCanceled && scanType !== "automatically" && processType !== 'db_backup' && (<ProgresBar progress={progress} bgColor={progressBarColor} />)}
                  <LogsScreen isCanceled={isCanceled} logs={logs} setLogs={setLogs} isPaused={isPaused} isLogsVisible={isLogsVisible} isCompleted={isCompleted} cancelMessage="The database optimization process has been canceled." successMessage="Database Optimized completed successfully!" />
                </>
              )}
            </>
          )}
        </div>
        <ResumeModal isLastLogIndex={isLastLogIndex} setIsLastLogIndex={setIsLastLogIndex} isOpen={isModalOpen} setOperationLoading={setOperationLoading} onClose={() => setIsModalOpen(false)} fetchLogs={fetchLogs} setIsScanInProgress={setIsScanInProgress} setLogs={setLogs} setIsCompleted={setIsCompleted} setProgress={setProgress}
          handleCancelOptimize={handleCancelOptimize} setIsOperationInProgress={setIsOperationInProgress} isComponentActive={isComponentActive} isCanceled={isCanceled} setIsLogsVisible={setIsLogsVisible} setRenderKey={setRenderKey}
          setIsFetchingLogs={setIsFetchingLogs} verifyDatabaseOptimizeStatus={verifyDatabaseStatus} setIsPausing={setIsPausing} setIsPaused={setIsPaused} setIsCanceled={setIsCanceled}
        />
        {parentEnable === false && (<LockCard
          featureName="Database Optimizer"
          featureDescription="Easily change your WordPress database table prefix in seconds to prevent automated SQL injection attacks."
          featureId={enableDatabase?.featureId} isActive={enableDatabase?.isActiveState} afterToggleCallback={checkDatabaseStatus} />)}
        <div>
          <DatabaseTables checkTableStatus={checkTableStatus} key={renderKey} steps={optimizerStatus?.steps} licenseConnected={optimizerStatus?.licenseConnected} proPluginActive={optimizerStatus?.proPluginActive} currentPlan={optimizerStatus?.currentPlan} loading={optimizerStatusLoading} />
        </div>
      </div>
    </div>
  )
}

export default DatabaseOptimizer;