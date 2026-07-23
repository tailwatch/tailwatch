import { useEffect, useRef } from "react";
import MoniteringFiles from "./MoniteringFiles/MoniteringFiles";
import Header from "../../Components/Header/Header.jsx";
import MoniteringActions from "./MoniteringActions/MoniteringActions.jsx";
import ProgresBar from '../../Components/Progressbar/ProgresBar.jsx';
import LogsScreen from '../../Components/LogsScreen/LogsScreen.jsx';
import ResumeModal from './ResumeModal/ResumeModal.jsx';
import InfoBar from "../../Components/InfoBar/InfoBar.jsx";
import { SkeletonButton } from '../../Components/Skeleton/LoaderSkeleton';
import FileMoniteringDetails from "./FileMoniteringDetails/FileMoniteringDetails.jsx";
import { SkeletonStatus } from "../../Components/Skeleton/LoaderSkeleton";
import LoadingBar from 'react-top-loading-bar';
import LockCard from '../../Components/LockCard/LockCard.jsx';
import { useFileMonitering } from "../../Components/Hooks/useFileMonitering/useFileMonitering.jsx";
import { Spinner } from "../../Components/Spinner/Spinner.jsx";
import ScanErrorModal from "../Scanner/ScanErrorModal/ScanErrorModal.jsx";

const FileMonitering = () => {
  const { featureEnable, ismonitering, setFeatureEnable, parentEnable, setParentEnable, isLoadingVisible, showFileMonteringLogs, trigerMoniteringDetails, loadingStrip, isCompleted, isCanceled, summaryData, isScannerEnabled, navigate, scanType, scanState, handleToggleDetails,
    showDetails, isPaused, handleInstantScan, setIsLogsVisible, isLogsVisible, verifyStatus, handleResume, isScanInProgress, isStarting, isOperationInProgress, isPausing, isCanceling, handleCancel, handlePause, statusMessage,
    isDisabled, resetAllStates, checkIntegrityFileStatus, isModalOpen, fetchLogs, setIsScanInProgress, setLogs, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, setIsFetchingLogs, isLastLogIndex, setIsLastLogIndex,
    checkMonitoringStatus, setRenderKey, renderKey, setTrigerMoniteringDetails, setIsPaused, setIsCanceled, handleSummaryData, progress, progressBarColor, logs, setIsModalOpen, setOperationLoading, isOperationLoading, isLicenseConnect, setIsLicenseConnect, scanError, setScanError, scanErrorDismissed, setScanErrorDismissed, scanProgress } = useFileMonitering();
  const loadingBarRef = useRef(null);

  useEffect(() => {
    if (isLoadingVisible) {
      loadingBarRef.current.continuousStart();
    } else {
      loadingBarRef.current.complete();
    }
  }, [isLoadingVisible]);

  return (
    <div className="mb-[24px]">
      {isOperationLoading && (
        <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
          <Spinner />
        </div>
      )}
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
      <Header title="File Integrity Watch" />
      <div className="px-4 pt-4">
        <FileMoniteringDetails key={trigerMoniteringDetails} featureEnable={featureEnable} setFeatureEnable={setFeatureEnable} parentEnable={parentEnable} setParentEnable={setParentEnable} isLicenseConnect={isLicenseConnect} setIsLicenseConnect={setIsLicenseConnect} />

        {loadingStrip ? (
          <SkeletonStatus />
        ) : (
          <>
            {isCompleted && !isCanceled && !scanError && !scanErrorDismissed && (
              <InfoBar type="info" message={<> <p className="text-sm font-medium"> New Scan Files: <span className="font-semibold">{summaryData.newScanFiles}</span> &nbsp; | &nbsp; Total Captured Files: <span className="font-semibold">{summaryData.totalCapturedFiles}</span> {isScannerEnabled && (<> &nbsp; | &nbsp; Malware scanning has started to clean the captured files. <button className="text-blue-500 underline ml-2" onClick={() => navigate('/dashboard/malwarescanner')} > Go to Scanner </button> </>)} </p> </>} />
            )}
          </>
        )}

        <div>
          {(isLoadingVisible) ? (
            <SkeletonButton />
          ) : (
            <>
              {scanType === "automatically" && !isCompleted && scanState !== "pause" && !isCanceled && (
                <InfoBar type="progress" message={<> File Integrity is in progress by ' Auto Schedule '. <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; you can also View , Cancel and Pause the proccess by click on View Link. </>} />
              )}
              {(scanType === "automatically" && scanState === "pause" && !isCanceled) ? (
                <InfoBar type="paused" message={<> File Monitering is Paused by ' Auto Schedule '. Click on <button onClick={handleToggleDetails} className="text-blue-500 underline ml-2" > {showDetails ? "Hide" : "View "} </button> &nbsp; and resume the process. </>} />) : null}

              {showDetails && (
                <>
                  <MoniteringActions isLicenseConnect={isLicenseConnect} ismonitering={ismonitering} parentEnable={parentEnable} featureEnable={featureEnable} isPaused={isPaused} isCompleted={isCompleted} handleInstantScan={handleInstantScan} toggleLogs={() => setIsLogsVisible(!isLogsVisible)} isLogsVisible={isLogsVisible} featureId={showFileMonteringLogs.featureId}
                    verifyStatus={verifyStatus} handleResume={handleResume} isScanInProgress={isScanInProgress} isStarting={isStarting} isOperationInProgress={isOperationInProgress} isCanceled={isCanceled}
                    isPausing={isPausing} isCanceling={isCanceling} handleCancel={handleCancel} handlePause={handlePause} statusMessage={statusMessage} scanProgress={scanProgress} isDisabled={isDisabled} resetAllStates={resetAllStates} checkIntegrityFileStatus={checkIntegrityFileStatus}
                  />
                  {(isScanInProgress || isPaused || !verifyStatus) && !isCanceled && scanType !== "automatically" && (<ProgresBar progress={progress} bgColor={progressBarColor} />)}
                  <LogsScreen logs={logs} setLogs={setLogs} isPaused={isPaused} isCanceled={isCanceled} isLogsVisible={isLogsVisible} isCompleted={isCompleted} cancelMessage="File Scanning Cancel!" successMessage="File Scanning is Completed successfully!" />
                </>
              )}
            </>
          )}
        </div>
      </div>
      {(parentEnable === false || isLicenseConnect === true) && <LockCard featureName="File Integrity Watch" featureDescription="Track every file change on your website in real time. Automatically scan modified files for malware at set intervals, compare before-and-after versions, and store change history for up to 24 hours or more." featureId={showFileMonteringLogs?.featureId} isActive={showFileMonteringLogs?.isActiveState} isLocked={showFileMonteringLogs?.isLocked} afterToggleCallback={checkIntegrityFileStatus} />}
      <MoniteringFiles key={renderKey} onSummaryDataUpdate={handleSummaryData} setIsLicenseConnect={setIsLicenseConnect} />
      <ResumeModal isLastLogIndex={isLastLogIndex} setIsLastLogIndex={setIsLastLogIndex} setOperationLoading={setOperationLoading} isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} fetchLogs={fetchLogs} setIsScanInProgress={setIsScanInProgress} setLogs={setLogs}
        setIsCompleted={setIsCompleted} setProgress={setProgress} handleCancel={handleCancel} setIsOperationInProgress={setIsOperationInProgress} isComponentActive={isComponentActive}
        isCanceled={isCanceled} setIsLogsVisible={setIsLogsVisible} setIsFetchingLogs={setIsFetchingLogs} checkMonitoringStatus={checkMonitoringStatus}
        setRenderKey={setRenderKey} setTrigerMoniteringDetails={setTrigerMoniteringDetails} setIsPaused={setIsPaused} setIsCanceled={setIsCanceled}
      />

      {scanError && (
        <ScanErrorModal
          scanError={scanError}
          onRetry={() => { setScanErrorDismissed(true); setScanError(null); }}
          onClose={() => { setScanErrorDismissed(true); setScanError(null); }}
        />
      )}
    </div>
  );
};

export default FileMonitering;