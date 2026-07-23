import React from 'react'
import InfoBar from '../../../Components/InfoBar/InfoBar';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { RiRestartFill } from "react-icons/ri";
import ShowCanvas from '../../../Components/ShowCanvasButton/ShowCanvas';

// Small helper — turns "building-baseline" → "Building Baseline".
const titleCaseScanState = (raw) => {
  if (!raw) return null;
  return String(raw).replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
};

const StatusProgressCard = ({ statusMessage, scanProgress }) => {
  if (!statusMessage) return null;
  const hasProgressData =
    scanProgress && (scanProgress.files_scanned != null || scanProgress.files_total != null || scanProgress.progress != null);

  // If no counts / percent are available, fall back to the plain status bar.
  if (!hasProgressData) {
    return <InfoBar type="progress" message={statusMessage} />;
  }

  const { files_scanned, files_total, progress, scan_state } = scanProgress;
  // Clamp progress to [0, 100] for the bar even if the backend returns 105 or negative.
  const clampedProgress = progress != null ? Math.max(0, Math.min(100, Number(progress))) : null;
  const stateLabel = titleCaseScanState(scan_state);

  const scannedLabel = files_scanned != null ? Number(files_scanned).toLocaleString() : null;
  const totalLabel = files_total != null ? Number(files_total).toLocaleString() : null;

  return (
    <InfoBar
      type="progress"
      message={
        <div className="w-full">
          <div className="flex items-center justify-between flex-wrap gap-2 mb-1.5">
            <div className="flex items-center gap-2 min-w-0">
              <p className="text-sm font-semibold text-gray-900">
                Setting up file monitoring. This first scan records your current files, so later scans can detect any changes.
              </p>             
            </div>            
          </div>

          <p className="text-xs text-gray-600 mb-2">
            {scannedLabel != null && totalLabel != null
              ? <>Scanned <span className="font-semibold text-gray-800 tabular-nums">{scannedLabel}</span> of <span className="font-semibold text-gray-800 tabular-nums">{totalLabel}</span> files</>
              : scannedLabel != null
                ? <>Scanned <span className="font-semibold text-gray-800 tabular-nums">{scannedLabel}</span> files</>
                : statusMessage}
          </p>

          {clampedProgress != null && (
            <div className="h-1.5 w-full bg-gray-200 rounded-full overflow-hidden">
              <div
                className="h-full bg-gradient-to-r from-[#007980] to-[#85cbcf] rounded-full transition-all duration-500 ease-out"
                style={{ width: `${clampedProgress}%` }}
              />
            </div>
          )}

          <p className="text-[11px] text-gray-500 mt-1.5">
            Please wait for the scan to complete before proceeding.
          </p>
        </div>
      }
    />
  );
};

const MoniteringActions = ({ isLicenseConnect, ismonitering, featureEnable,parentEnable, isScanInProgress, isStarting, isPaused, isCompleted, handleInstantScan, toggleLogs, isLogsVisible, isOperationInProgress, handleResume, verifyStatus, isCanceled, isPausing, isCanceling, handlePause, handleCancel, isDisabled, statusMessage, scanProgress, resetAllStates, checkIntegrityFileStatus, featureId }) => {

  return (
    <div>
      <StatusProgressCard statusMessage={statusMessage} scanProgress={scanProgress} />
      <div className="flex justify-between items-center mb-4">
        <div className="flex space-x-3 items-center">
          {!isPaused && verifyStatus && (
            <ActionButton onClick={handleInstantScan} isDisabled={ featureEnable === false || parentEnable===false || isLicenseConnect === true || isDisabled || isCanceled || isScanInProgress} isInProgress={isScanInProgress || ismonitering} isCompleted={isCompleted} isCanceled={isCanceled} isLoading={isStarting} loadingText="Starting..." defaultText="Start Scanning" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
          )}
          {!isScanInProgress && !isPaused && !isCompleted && !isCanceled && verifyStatus && (
            <ShowCanvas featureId={featureId} onClose={checkIntegrityFileStatus}/>
          )}
          {(isPaused || !verifyStatus) && (
            <ActionButton onClick={handleResume} isDisabled={ featureEnable === false || parentEnable===false || isDisabled || isScanInProgress} isInProgress={isScanInProgress} isCompleted={isCompleted} defaultText="Resume process" inProgressText="In Progress..." completedText="Completed" />
          )}
          {(isCompleted || isCanceled) && (
            <div className='flex cursor-pointer' onClick={resetAllStates} role="button" >
              <RiRestartFill className='text-[25px] text-[#007980] hover:text-opacity-80' />
              <p className='pl-[5px] text-md text-gray-600 font-semibold'>Refresh</p>
            </div>
          )}
        </div>

        <div className="flex space-x-3">
          {!isOperationInProgress && !verifyStatus && !isCanceled && (
            <ActionButton onClick={handleCancel} isDisabled={isCompleted || isCanceling || featureEnable === false || parentEnable===false } defaultText="Cancel" inProgressText="Canceling..." className={!isCompleted ? 'bg-[#007980] hover:bg-opacity-80 cursor-pointer' : 'bg-gray-400 cursor-not-allowed'} />
          )}

          {(isScanInProgress || isPaused) && (
            <>
              <ActionButton onClick={handlePause} isInProgress={isPausing} isDisabled={!isScanInProgress || isCompleted || isPausing || featureEnable === false || parentEnable===false || isCanceling} defaultText="Pause" inProgressText="Pausing..." className="px-3 py-1 text-white rounded-md" />
              <ActionButton onClick={handleCancel} isInProgress={isCanceling} isDisabled={isCompleted || isCanceling || featureEnable === false || parentEnable===false || isPausing} defaultText="Cancel" inProgressText="Canceling..." className="px-3 py-1 text-white rounded-md" />
              <ActionButton onClick={toggleLogs} defaultText={isLogsVisible ? 'Hide Logs' : 'Show Logs'} className="bg-[#007980] hover:bg-opacity-80" />
            </>
          )}
        </div>
      </div>

    </div>
  )
}

export default MoniteringActions