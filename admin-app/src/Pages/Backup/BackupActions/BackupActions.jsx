import ActionButton from '../../../Components/Buttons/ActionButton';
import { RiRestartFill } from "react-icons/ri";
import ShowCanvas from '../../../Components/ShowCanvasButton/ShowCanvas';
import { FiRefreshCw } from 'react-icons/fi';
const BackupActions = ({ refreshing, siteSize, handleRefreshSiteSize, parentEnable, featureEnable, isScanInProgress, isStarting, isPaused, isCompleted, handleInstantScan, handlePauseBackup, handleCancelBackup, handleResumeBackup, toggleLogs, isLogsVisible, verifyStatus, isOperationInProgress, isCanceled, isPausing, isCanceling, resetAllStates, IsEnableBackup, featureId, checkBackupStatus }) => {

  return (
    <div>
      <div className="flex flex-col gap-3 mb-4 sm:flex-row sm:justify-between sm:items-center">
        <div className="flex flex-wrap gap-2 items-center">
          {!isPaused && verifyStatus && (
            <>
              <ActionButton onClick={handleInstantScan} isInProgress={isScanInProgress} isCompleted={isCompleted} isCanceled={isCanceled} isDisabled={featureEnable === false || parentEnable === false} isFeatureEnable={IsEnableBackup} isLoading={isStarting} loadingText="Starting..." defaultText="Take Backup Now" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
            </>
          )}
          {!isScanInProgress && !isPaused && !isCompleted && !isCanceled && verifyStatus && (
            <ShowCanvas featureId={featureId} onClose={checkBackupStatus} />
          )}         
          {(isPaused || !verifyStatus) && (
            <ActionButton onClick={handleResumeBackup} isInProgress={isScanInProgress} isCompleted={isCompleted} isCanceled={isCanceled} isFeatureEnable={IsEnableBackup} defaultText="Resume Process" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
          )}
           <div className="flex items-center gap-1">
            <span className="text-sm text-gray-600">
              Site Size: <span className="font-semibold text-gray-800">{siteSize || 'Loading...'}</span>
            </span>
            <button onClick={handleRefreshSiteSize} disabled={refreshing} className={`p-2 rounded-full hover:bg-gray-100 transition-colors ${refreshing ? 'animate-spin' : ''}`} title="Refresh site size" >
              <FiRefreshCw className="w-4 h-4 text-gray-600" />
            </button>
          </div>
          {(isCompleted || isCanceled) && (
            <div className='flex cursor-pointer' onClick={resetAllStates} role="button" >
              <RiRestartFill className='text-[25px] text-[#007980] hover:text-opacity-80' />
              <p className='pl-[5px] text-md text-gray-600 font-semibold'>Refresh</p>
            </div>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          {!isOperationInProgress && !verifyStatus && !isCanceled && !isCompleted && !isScanInProgress && !isPaused && (
            <ActionButton onClick={handleCancelBackup} isDisabled={isCanceling || IsEnableBackup} defaultText="Cancel" inProgressText="Canceling..." className="px-3 py-1" />
          )}
          {(isScanInProgress || isPaused) && (
            <>
              <ActionButton onClick={handlePauseBackup} isInProgress={isPausing} isDisabled={!isScanInProgress || isCompleted || isPausing || IsEnableBackup || isCanceling} defaultText="Pause" inProgressText="Pausing..." className="px-3 py-1 text-white rounded-md" />

              <ActionButton onClick={handleCancelBackup} isInProgress={isCanceling} isDisabled={isCompleted || isCanceling || IsEnableBackup || isPausing} defaultText="Cancel" inProgressText="Canceling..." className="px-3 py-1 text-white rounded-md" />

              <ActionButton onClick={toggleLogs} isDisabled={false} defaultText={isLogsVisible ? 'Hide Logs' : 'Show Logs'} className="px-3 py-1 text-white rounded-md bg-[#007980] hover:bg-opacity-80" />
            </>
          )}
        </div>
      </div>
    </div>

  );
};

export default BackupActions;