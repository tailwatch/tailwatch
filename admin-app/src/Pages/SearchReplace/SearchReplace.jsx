import React, { useEffect, useRef } from 'react';
import { Spinner } from '../../Components/Spinner/Spinner.jsx';
import Header from '../../Components/Header/Header';
import SelectTables from './SelectTables/SelectTables';
import AdditionalSettings from './AdditionalSettings/AdditionalSettings';
import { SearchReplaceSkelton, SkeletonButton } from '../../Components/Skeleton/LoaderSkeleton';
import ProgresBar from '../../Components/Progressbar/ProgresBar';
import LogsScreen from '../../Components/LogsScreen/LogsScreen';
import SearchReplaceActions from './SearchReplaceActions/SearchReplaceActions';
import ResumeModal from './ResumeModal/ResumeModal';
import InfoBar from '../../Components/InfoBar/InfoBar.jsx'
import LockCard from '../../Components/LockCard/LockCard.jsx';
import InputField from '../../Components/Fields/InputField.jsx';
import LoadingBar from 'react-top-loading-bar';
import { SkeletonStatus } from '../../Components/Skeleton/LoaderSkeleton';
import { useSearchReplace } from '../../Components/Hooks/useSearchReplace/useSearchReplace.jsx';
const SearchReplace = () => {
    const { isLoadingVisible, parentEnable, loading, verifyLoading, infoBarVisible, infoMessage, setInfoBarVisible, featureEnable, enableSearchReplace, searchText, setSearchText, isScanInProgress, isStarting, verifyStatus, isPaused, replaceText, setReplaceText, tables,
        selectedTables, setSelectedTables, caseInsensitive, setCaseInsensitive, replaceGuids, setReplaceGuids, dryRun, setDryRun, checkPauseResumeStatus, isCompleted, handleSearchReplace, setIsLogsVisible, isLogsVisible, handleResume, isOperationInProgress, isCanceled, isPausing,
        isCanceling, handleCancel, handlePause, isDisabled, resetAllStates, scanState, handleCancelAfterPause, progress, progressBarColor, infoRevertChange, setInfoRevertChange, logs, setLogs, isModalOpen, setIsModalOpen, fetchLogs, setIsScanInProgress, setIsCompleted, setIsOperationInProgress, isComponentActive,
        setProgress, setIsResuming, isResuming, setIsPausing, setIsPaused, setIsCanceled, setIsCanceling, setOperationLoading, isOperationLoading, isLastLogIndex, setIsLastLogIndex } = useSearchReplace();

    const loadingBarRef = useRef(null);
    useEffect(() => {
        if (isLoadingVisible || loading || verifyLoading) {
            loadingBarRef.current.continuousStart();
        } else {
            loadingBarRef.current.complete();
        }
    }, [isLoadingVisible, loading, verifyLoading]);

    return (
        <div>
            {isOperationLoading && (
                <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
                    <Spinner />
                </div>
            )}
            <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
            <Header title="Search & Replace" />
            <div className='p-4 bg-white/95'>
                {/* <div className="bg-gradient-to-br from-blue-50 to-[#85cbcf] rounded-md mb-4 px-8 py-6 relative overflow-hidden">
                    <div className="absolute inset-0 bg-gradient-to-r from-blue-600/20 to-cyan-500/20 animate-pulse"></div>
                    <p className="text-gray-600 mt-1 relative z-10">Advanced tool for database content management</p>
                </div> */}
                {infoBarVisible && (
                    <div className='pt-4'>
                        <InfoBar type="danger" message={infoMessage} showCloseButton={true} onClose={() => setInfoBarVisible(false)} />
                    </div>
                )}
                {loading ? (
                    <div className='pb-4'>
                        <SkeletonStatus />
                    </div>
                ) : featureEnable === false ? (<div className="pt-4"> <InfoBar type="info" message="Please Configure the Search Replace Settings" /> </div>
                ) : null}

                {loading || isLoadingVisible ? (
                    <SearchReplaceSkelton />
                ) : (
                    <div>
                        <div className={`${parentEnable === false ? "filter blur-sm " : ""}`}>
                            <div className=" mx-auto">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                    <div className="relative group">
                                        <label className="block text-base font-semibold text-gray-700 mb-2">Search for</label>
                                        <div className="relative">
                                            <InputField type="text" value={searchText} onChange={(e) => setSearchText(e.target.value)} disabled={isScanInProgress || !verifyStatus || isPaused || featureEnable === false}
                                                className="w-full px-3 py-2 bg-gray-50 border-2 border-gray-200 rounded-xl text-sm transition-all duration-300 focus:outline-none focus:border-[#007980] focus:bg-white focus:shadow-md disabled:opacity-50 disabled:cursor-not-allowed" placeholder="Enter text to search..." />
                                        </div>
                                    </div>
                                    <div className="relative group">
                                        <label className="block text-base font-semibold text-gray-700 mb-2">Replace with</label>
                                        <div className="relative">
                                            <InputField type="text" value={replaceText} onChange={(e) => setReplaceText(e.target.value)} disabled={isScanInProgress || !verifyStatus || isPaused || featureEnable === false} className="w-full px-3 py-2 bg-gray-50 border-2 border-gray-200 rounded-xl text-sm transition-all duration-300 focus:outline-none focus:border-[#007980] focus:bg-white focus:shadow-md disabled:opacity-50 disabled:cursor-not-allowed" placeholder="Enter replacement text..." />
                                        </div>
                                    </div>
                                </div>
                                <SelectTables tables={tables} selectedTables={selectedTables} setSelectedTables={setSelectedTables} disabled={isScanInProgress || !verifyStatus || isPaused || featureEnable === false} />
                                <AdditionalSettings caseInsensitive={caseInsensitive} setCaseInsensitive={setCaseInsensitive} replaceGuids={replaceGuids} setReplaceGuids={setReplaceGuids} dryRun={dryRun} setDryRun={setDryRun} disabled={isScanInProgress || !verifyStatus || isPaused || enableSearchReplace?.searchReplaceFeature} />
                            </div>
                        </div>
                        {parentEnable === false && (<LockCard featureId={enableSearchReplace.featureId} featureName="Search & Replace" featureDescription="Search and replace any content across your website with ease. Perform safe dry runs before applying changes." isActive={enableSearchReplace.is_active} afterToggleCallback={checkPauseResumeStatus} />)}
                    </div>
                )}
                <div>
                    {(isLoadingVisible) ? (
                        <div className=''>
                            <SkeletonButton />
                        </div>
                    ) : (
                        <>
                            <div className={`pt-4 ${parentEnable === false ? "filter blur-sm" : ""}`}>
                                <SearchReplaceActions featureEnable={featureEnable} isPaused={isPaused} isCompleted={isCompleted} handleSearchReplace={handleSearchReplace} toggleLogs={() => setIsLogsVisible(!isLogsVisible)} isLogsVisible={isLogsVisible} verifyStatus={verifyStatus} handleResume={handleResume}
                                    isScanInProgress={isScanInProgress} isStarting={isStarting} isOperationInProgress={isOperationInProgress} isCanceled={isCanceled} isPausing={isPausing} isCanceling={isCanceling}
                                    handleCancel={handleCancel} handlePause={handlePause} isDisabled={isDisabled} resetAllStates={resetAllStates} scanState={scanState} handleCancelAfterPause={handleCancelAfterPause}
                                    verifyLoading={verifyLoading} checkPauseResumeStatus={checkPauseResumeStatus} isSearchReplaceEnable={enableSearchReplace?.searchReplaceFeature} featureId={enableSearchReplace.featureId}
                                />
                            </div>
                            <div className=' mb-4'>
                                {(isScanInProgress || isPaused || !verifyStatus) && !isCanceled && (<ProgresBar progress={progress} bgColor={progressBarColor} />)}
                                {(infoRevertChange || scanState === "reverting_changes") && !isCompleted && (
                                    <div className='pt-4'>
                                        <InfoBar type="danger" message="Reverting the changes. Dont stop the process" showCloseButton={true} onClose={() => setInfoRevertChange(false)} />
                                    </div>
                                )}
                                <LogsScreen logs={logs} setLogs={setLogs} isPaused={isPaused} isCanceled={isCanceled} isLogsVisible={isLogsVisible} isCompleted={isCompleted} cancelMessage="Search&Replace process is cancel." successMessage="Search&Replace is Completed successfully!" />
                            </div>
                        </>
                    )}
                </div>
                <ResumeModal isLastLogIndex={isLastLogIndex} setIsLastLogIndex={setIsLastLogIndex} isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} fetchLogs={fetchLogs} setIsScanInProgress={setIsScanInProgress} setLogs={setLogs} setIsCompleted={setIsCompleted} setIsOperationInProgress={setIsOperationInProgress} isComponentActive={isComponentActive}
                    isCanceled={isCanceled} setProgress={setProgress} setIsLogsVisible={setIsLogsVisible} setIsResuming={setIsResuming} isResuming={isResuming} handleCancelAfterPause={handleCancelAfterPause} setIsPausing={setIsPausing}
                    setIsPaused={setIsPaused} setIsCanceled={setIsCanceled} setInfoRevertChange={setInfoRevertChange} setIsCanceling={setIsCanceling} setOperationLoading={setOperationLoading}
                />
            </div>
        </div>
    );
};

export default SearchReplace;
