import React, { useState } from 'react'
import { UseDatabaseOptimizer, useSubOptionsCheck } from '../../../Components/Hooks/Features/UseFeatures'
import ActionButton from '../../../Components/Buttons/ActionButton';
import { RiRestartFill } from "react-icons/ri";
import ShowCanvas from '../../../Components/ShowCanvasButton/ShowCanvas';

/* global tailwatch_ajax */
const DatabaseActions = ({ checkTableStatus, isScanInProgress, isStarting, featureEnable, isPaused, isCompleted, handleInstantScan, toggleLogs, isLogsVisible, handlePauseOptimize, handleCancelOptimize, isOperationInProgress, handleResumeOptimize, verifyStatus, isCanceled, isPausing, isCanceling,   resetAllStates, processType, checkDatabaseStatus }) => {
  const { enableDatabase } = UseDatabaseOptimizer();
  const { areAllSubOptionsFalse } = useSubOptionsCheck();
  const isDatabaseFeatureEnabled = enableDatabase?.isActiveState === '0';
  const isBackupProcess = processType === 'db_backup';
  const [isResetting, setIsResetting] = useState(false);

  const handleRefreshClick = async () => {
    if (isResetting) return;
    setIsResetting(true);
    try {
      await resetAllStates();
    } finally {
      setIsResetting(false);
    }
  };

  return (
    <div>
      <div className="flex justify-between items-center mb-4">
        <div className="flex space-x-3 items-center">

          {((!isPaused && verifyStatus) || isCanceled) && (
            <ActionButton onClick={handleInstantScan} isDisabled={isScanInProgress || checkTableStatus === true || areAllSubOptionsFalse || isCompleted || isDatabaseFeatureEnabled || featureEnable === false } isInProgress={isScanInProgress} isCompleted={isCompleted} isFeatureEnable={isDatabaseFeatureEnabled} isCanceled={isCanceled} isLoading={isStarting} loadingText="Starting..." defaultText="Start Optimize" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
          )}
          {!isScanInProgress && !isPaused && !isCompleted && !isCanceled && verifyStatus &&  (
            <ShowCanvas featureId={enableDatabase?.featureId} onClose={checkDatabaseStatus}/>
          )}
          {(isPaused || !verifyStatus) && !isCanceled && !isBackupProcess && (
            <ActionButton onClick={handleResumeOptimize} isDisabled={isScanInProgress || isCompleted || isDatabaseFeatureEnabled} isInProgress={isScanInProgress} isCompleted={isCompleted} defaultText="Resume process" inProgressText="In Progress..." completedText="Completed" />
          )}
          {(isCompleted || isCanceled) && (
            <div
              className={`flex items-center ${isResetting ? 'cursor-not-allowed opacity-60 pointer-events-none' : 'cursor-pointer'}`}
              onClick={handleRefreshClick}
              role="button"
              aria-disabled={isResetting}
            >
              <RiRestartFill className={`text-[25px] text-[#007980] hover:text-opacity-80 ${isResetting ? 'animate-spin' : ''}`} />
              <p className='pl-[5px] text-md text-gray-600 font-semibold'>{isResetting ? 'Refreshing...' : 'Refresh'}</p>
            </div>
          )}
        </div>

        <div className="flex space-x-3">
        {isBackupProcess && isScanInProgress && !isPaused && (
            <div className="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-2 rounded">
              <p className="text-sm">This process starts from backup, so you cannot stop or pause it.</p>
            </div>
          )}

          {isBackupProcess && isPaused && (
            <div className="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-2 rounded">
              <p className="text-sm">This process is paused by a backup operation and cannot be resumed from here.</p>
            </div>
          )}

          {!isBackupProcess && !isOperationInProgress && !verifyStatus && !isCanceled && (
            <ActionButton onClick={handleCancelOptimize} isDisabled={isCompleted || isCanceling || isDatabaseFeatureEnabled} isInProgress={isCanceling} defaultText="Cancel" inProgressText="Canceling..." />
          )}

          {!isBackupProcess && (isScanInProgress || isPaused) && (
            <>
              <ActionButton onClick={handlePauseOptimize} isDisabled={!isScanInProgress || isCompleted || isPausing || isDatabaseFeatureEnabled || isCanceling} isInProgress={isPausing} defaultText="Pause" inProgressText="Paused" />
              <ActionButton onClick={handleCancelOptimize} isDisabled={isCompleted || isCanceling || isDatabaseFeatureEnabled } isInProgress={isCanceling} defaultText="Cancel" inProgressText="Canceled" />
              <ActionButton onClick={toggleLogs} isDisabled={false} defaultText={isLogsVisible ? 'Hide Logs' : 'Show Logs'} />
            </>
          )}
        </div>
      </div>

    </div>
  )
}

export default DatabaseActions