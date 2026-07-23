import React from 'react'
import ActionButton from '../../../Components/Buttons/ActionButton';
import { SkeletonButton } from '../../../Components/Skeleton/LoaderSkeleton';
import { UseSearchReplace } from '../../../Components/Hooks/Features/UseFeatures'
import { RiRestartFill } from "react-icons/ri";
import ShowCanvas from '../../../Components/ShowCanvasButton/ShowCanvas';

const SearchReplaceActions = ({ featureEnable, isScanInProgress, isStarting, isPaused, isCompleted, handleSearchReplace, toggleLogs, isLogsVisible, isOperationInProgress, handleResume, verifyStatus, isCanceled, isPausing, isCanceling, handlePause, handleCancel, isDisabled, resetAllStates, scanState, handleCancelAfterPause, verifyLoading,checkPauseResumeStatus,featureId,isSearchReplaceEnable }) => {    
    const isRevertingChanges = scanState === 'reverting_changes';
    const cancelHandler = isPausing ? handleCancelAfterPause : handleCancel;

    return (
        <div>
            <div className="flex justify-between items-center mb-4">
                <div className="flex space-x-3 items-center">
                    {verifyLoading ? (
                        <>
                            <SkeletonButton />
                        </>
                    ) : (
                        <>
                            {((!isPaused && verifyStatus) || isCanceled) && (
                                <ActionButton onClick={handleSearchReplace} isDisabled={isDisabled || isScanInProgress || isCompleted || isCanceled || featureEnable === false } isInProgress={isScanInProgress} isCompleted={isCompleted} isCanceled={isCanceled} isLoading={isStarting} loadingText="Starting..." defaultText="Run Search/Replace" inProgressText="In Progress..." completedText="Completed" canceledText="Canceled" />
                            )}
                            {!isScanInProgress && !isPaused && !isCompleted && !isCanceled && verifyStatus && (
                                <ShowCanvas featureId={featureId} onClose={checkPauseResumeStatus}/>
                            )}
                            {(isPaused || !verifyStatus) && !isCanceled && (
                                <ActionButton onClick={handleResume} isDisabled={isDisabled || isScanInProgress || isCompleted || featureEnable === false } isInProgress={isScanInProgress} isCompleted={isCompleted} defaultText="Resume process" inProgressText="In Progress..." completedText="Completed" />
                            )}
                            {(isCompleted || isCanceled) && (
                                <div className='flex cursor-pointer' onClick={resetAllStates} role="button" >
                                    <RiRestartFill className='text-[25px] text-[#007980] hover:text-opacity-80' />
                                    <p className='pl-[5px] text-md text-gray-600 font-semibold'>Refresh</p>
                                </div>
                            )}
                        </>
                    )}
                </div>
                <div className="flex space-x-3 mr-[15px]">
                    {verifyLoading ? (
                        <>
                            <SkeletonButton />
                            <SkeletonButton />
                            <SkeletonButton />
                        </>
                    ) : (
                        <>
                            {!isScanInProgress && !isOperationInProgress && !verifyStatus && !isCanceled && scanState === 'pause' && (
                                <ActionButton onClick={handleCancelAfterPause} isDisabled={isCompleted || isCanceling || featureEnable === false } defaultText="Cancel" inProgressText="Canceling..." className={!isCompleted ? 'bg-[#007980] hover:bg-opacity-80 cursor-pointer' : 'bg-gray-400 cursor-not-allowed'} />
                            )}
                            {(isScanInProgress || isPaused) && (
                                <>
                                    <ActionButton onClick={handlePause} isInProgress={isPausing} isDisabled={!isScanInProgress || isCompleted || isPausing || isCanceling || featureEnable === false  || isRevertingChanges} defaultText="Pause" inProgressText="Paused" className="px-3 py-1 text-white rounded-md" />
                                    <ActionButton onClick={cancelHandler} isInProgress={isCanceling} isDisabled={isCompleted || isCanceling || featureEnable === false  || isRevertingChanges} defaultText="Cancel" inProgressText="Canceled" className="px-3 py-1 text-white rounded-md" />
                                    <ActionButton onClick={toggleLogs} defaultText={isLogsVisible ? 'Hide Logs' : 'Show Logs'} className="bg-[#007980] hover:bg-opacity-80" />
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

export default SearchReplaceActions;
