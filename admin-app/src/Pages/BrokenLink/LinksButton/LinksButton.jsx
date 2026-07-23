import React from 'react';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { RiRestartFill } from "react-icons/ri";
import ShowCanvas from '../../../Components/ShowCanvasButton/ShowCanvas';
import { useBrokenLinkFeature } from '../../../Components/Hooks/Features/UseFeatures';
const LinksButton = ({ isScanInProgress, isStarting, isPaused, isCompleted, handleInstantScan, handlePause, handleCancel, handleResume, toggleLogs, isLogsVisible, verifyStatus, isOperationInProgress, isCanceled, isPausing, isCanceling, resetAllStates,checkBrokenLinkStatus,featureEnable,parentEnable }) => {
  const {enableBrokenLink} = useBrokenLinkFeature();

  return (
    <div className="flex justify-between items-center px-4">
      <div className="flex space-x-3 items-center">
        {!isPaused && verifyStatus && (
          <>
            <ActionButton onClick={handleInstantScan} isDisabled={featureEnable === false || parentEnable === false } isInProgress={isScanInProgress} isCompleted={isCompleted} isCanceled={isCanceled} isLoading={isStarting} loadingText="Starting..." defaultText="Scan Link" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
          </>
        )}
        {!isScanInProgress && !isPaused && !isCompleted && !isCanceled && verifyStatus && (
          <ShowCanvas featureId={enableBrokenLink?.featureId} onClose={checkBrokenLinkStatus} parentEnable={parentEnable} isDisabled={parentEnable === false} />
        )}
        {(isPaused || !verifyStatus) && (
          <ActionButton onClick={handleResume} isInProgress={isScanInProgress} isCompleted={isCompleted} isCanceled={isCanceled} defaultText="Resume Process" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
        )}
        {(isCompleted || isCanceled) && (
          <div className='flex cursor-pointer' onClick={resetAllStates} role="button" >
            <RiRestartFill className='text-[25px] text-[#007980] hover:text-opacity-80' />
            <p className='pl-[5px] text-md text-gray-600 font-semibold'>Refresh</p>
          </div>
        )}
      </div>

      <div className="flex space-x-3">
        {/* {!isOperationInProgress && !verifyStatus && !isCanceled && (
          <ActionButton onClick={handleCancel} isCompleted={isCompleted} isDisabled={isCanceling} defaultText="Cancel" inProgressText="Canceling..." className="px-3 py-1" />
        )} */}
        {(isScanInProgress || isPaused) && (
          <>
            <ActionButton onClick={handlePause} isInProgress={isPausing} isDisabled={!isScanInProgress || isCompleted || isPausing || isCanceling} defaultText="Pause" inProgressText="Pausing..." className="px-3 py-1 text-white rounded-md" />

            <ActionButton onClick={handleCancel} isInProgress={isCanceling} isDisabled={isCompleted || isCanceling || isPausing} defaultText="Cancel" inProgressText="Canceling..." className="px-3 py-1 text-white rounded-md" />

            <ActionButton onClick={toggleLogs} isDisabled={false} defaultText={isLogsVisible ? 'Hide Logs' : 'Show Logs'} className="px-3 py-1 text-white rounded-md bg-[#007980] hover:bg-opacity-80" />
          </>
        )}
      </div>
    </div>
  );
};

export default LinksButton;
