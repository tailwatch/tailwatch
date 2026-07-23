import { useState } from 'react'
import IconButton from '../Buttons/IconButton'
import { RiSettings3Line } from "react-icons/ri";
import { IoClose, IoRefresh } from "react-icons/io5";
import { useFeatureContent } from '../Hooks/useFeatureContent/useFeatureContent';
import CanvasContent from '../../Pages/Features/FaturesContent/CanvasContent';
const ShowCanvas = ({ featureId, onClose, tableStrip, parentEnable, isDisabled }) => {
    const { showCanvas, handleShowCanvas, handleCloseCanvas, selectedFeatureData, handleRestoreFeatureOptions, featuresData, fetchFeaturesData } = useFeatureContent();
    const [isLoading, setIsLoading] = useState(false);

    const handleConfigure = async () => {
        if (featureId) {
            setIsLoading(true);
            try {
                // if (featuresData.length === 0) {
                    const fetched = await fetchFeaturesData();
                    handleShowCanvas(featureId, fetched);
                // } else {
                    // handleShowCanvas(featureId);
                // }
            } finally {
                setIsLoading(false);
            }
        }
    };
    const buttonClassName = tableStrip ? "border border-[#007980] !px-3 text-sm" : "border border-[#007980]";
    const isButtonDisabled = parentEnable === false || isDisabled || isLoading;
    return (
        <div>
            <button
                onClick={isButtonDisabled ? undefined : handleConfigure}
                disabled={isButtonDisabled}
                className={`
                    inline-flex items-center font-[500] !py-2 !px-3 rounded-[5px] transition-colors
                    ${buttonClassName}
                    ${isDisabled ? 'bg-gray-300 text-white border-transparent cursor-not-allowed' : 'bg-white hover:bg-gray-100 text-[#007980] text-sm'}
                    ${isLoading ? 'cursor-not-allowed opacity-75' : ''}
                `}
            >
                {isLoading ? (
                    <svg className="animate-spin w-4 h-4 mr-2 text-[#007980]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                ) : (
                    <RiSettings3Line className="fill-current w-4 h-4 mr-2" />
                )}
                <span>{isLoading ? 'Loading...' : 'Change Settings'}</span>
            </button>
            {selectedFeatureData && (
                <div className={`fixed inset-0 bg-black bg-opacity-50 z-[1000] flex justify-end transition-opacity duration-300 ${showCanvas ? "opacity-100" : "opacity-0 pointer-events-none"}`}>
                    <div className={`bg-white h-full shadow-lg overflow-hidden w-full sm:w-[calc(100vw-100px)] lg:w-[calc(100vw-257px)] ${showCanvas ? "canvas-enter" : "canvas-exit"}`} style={{ borderTopLeftRadius: '15px', borderBottomLeftRadius: '15px' }} >
                        <div className="border-b border-gray-200 shadow-md p-3 sm:p-4 flex justify-between items-center gap-2">
                            <h2 className="text-base sm:text-lg lg:text-xl font-semibold truncate">
                                {JSON.parse(selectedFeatureData.value).title}
                            </h2>
                            <div className="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                                <IconButton icon={IoRefresh} text="Restore Default" onClick={() => handleRestoreFeatureOptions(selectedFeatureData?.option)} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-300" textColor="text-gray-700" className="text-xs sm:text-sm hidden sm:flex" tooltip="Reset to original settings" />
                                <IoRefresh className="sm:hidden cursor-pointer text-gray-600 hover:text-gray-900 w-6 h-6" onClick={() => handleRestoreFeatureOptions(selectedFeatureData?.option)} />
                                <IoClose className="cursor-pointer text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 flex items-center justify-center" onClick={() => handleCloseCanvas(onClose)} />
                            </div>
                        </div>
                        <div className="custom-scroll-bar px-3 sm:px-4 pb-16 overflow-y-auto h-full">
                            <CanvasContent featureData={selectedFeatureData} />
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}

export default ShowCanvas