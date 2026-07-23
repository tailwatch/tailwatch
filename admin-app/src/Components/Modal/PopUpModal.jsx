import React, { useEffect, useState } from "react";
import { IoClose, IoArrowBack, IoExpand, IoContract } from "react-icons/io5";
import { CustomRoleSkeleton } from "../Skeleton/LoaderSkeleton";

const PopUpModal = ({ title, children, loading, onSave, disabled, disabledIcon, onClose, onBack, error, saveButtonText, cancelButtonText, notificationModal = false, showCloseIcon = true, showBackIcon = false, showExpandIcon = false, width, height, isLoading, glowColor = "#007980", customHeader, leftButtonLabel, onLeftButtonClick, secondaryButtonText, onSecondaryButtonClick, initiallyExpanded = false }) => {
  const [showModal, setShowModal] = useState(false);
  const [isExpanded, setIsExpanded] = useState(initiallyExpanded);
  const defaultWidth = "w-full max-w-md sm:max-w-lg md:max-w-[40rem]";
  const defaultHeight = "max-h-[90vh]";
  const modalWidth = width || defaultWidth;
  const modalHeight = height || defaultHeight;

  useEffect(() => {
    setShowModal(true);
  }, []);

  const handleClose = () => {
    setShowModal(false);
    setTimeout(() => {
      onClose();
    }, 300);
  };

  const handleToggleExpand = () => {
    setIsExpanded(!isExpanded);
  };

  return (
    <div className="fixed top-0 left-0 w-full h-full bg-gray-900 bg-opacity-70 backdrop-blur-sm flex justify-center items-center z-[1000] p-5">
      <div
        className={`bg-white overflow-hidden ${notificationModal ? "" : "p-5"} rounded-lg shadow-2xl transform transition-all duration-500 ease-in-out
            ${showModal ? "scale-100 opacity-100" : "scale-90 opacity-0"} 
            ${modalWidth} ${modalHeight} relative z-10`}
        style={{ boxShadow: `0 0 15px 4px ${glowColor}40, 0 0 30px 5px ${glowColor}20`, borderRadius: "12px", border: `2px solid ${glowColor}30`, maxWidth: isExpanded ? "80%" : undefined, width: isExpanded ? "80%" : undefined, transition: "all 0.5s ease-in-out, opacity 0.3s ease-in-out, transform 0.3s ease-in-out" }}
      >
        {customHeader ? (
          customHeader
        ) : (
      <div className="flex items-center justify-between mb-4 border-b pb-3">
  <div className="flex items-center min-w-0">
    {showBackIcon && (
      <IoArrowBack
        onClick={disabledIcon ? undefined : onBack}
        size={22}
        className={`mr-3 flex-shrink-0 ${disabledIcon ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:scale-110'}`}
      />
    )}
    <h3 className="text-lg font-semibold truncate">
      {title}
    </h3>
  </div>

  <div className="flex items-center flex-shrink-0">
    {showExpandIcon && (
      isExpanded ? (
        <IoContract
          onClick={handleToggleExpand}
          size={20}
          className="cursor-pointer hover:scale-110 mr-3"
          title="Collapse"
        />
      ) : (
        <IoExpand
          onClick={handleToggleExpand}
          size={20}
          className="cursor-pointer hover:scale-110 mr-3"
          title="Expand"
        />
      )
    )}

    {showCloseIcon && (
      <IoClose
        onClick={disabledIcon ? undefined : handleClose}
        size={25}
        className={`${disabledIcon ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:scale-110'}`}
      />
    )}
  </div>
</div>

        )}

        {error && <div className="text-red-500 text-center mb-3">{error}</div>}

        <div className={`space-y-4 ${loading ? "flex justify-center items-center" : "overflow-y-auto custom-scroll-bar"}`} style={{ maxHeight: "calc(90vh - 150px)" }} >
          {loading ? <CustomRoleSkeleton /> : children}
        </div>

        {!loading && (
          <div className="flex justify-between mt-5">
            <div>
              {leftButtonLabel && onLeftButtonClick && (
                <button
                  onClick={onLeftButtonClick}
                  className="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300 transition-all"
                >
                  {leftButtonLabel}
                </button>
              )}
            </div>
            <div className="flex">
              {cancelButtonText && (
                <button onClick={handleClose} className="mr-3 px-3 py-2 text-sm bg-gray-300 rounded hover:bg-gray-400 transition-all" >
                  {cancelButtonText}
                </button>
              )}
              {secondaryButtonText && (
                <button disabled={disabled} onClick={onSecondaryButtonClick} className={`mr-3 px-3 py-2 text-sm bg-gray-200 rounded hover:bg-gray-300 transition-all ${disabled ? "opacity-50 cursor-not-allowed" : ""}`} >
                  {secondaryButtonText}
                </button>
              )}
              {saveButtonText && (
                <button onClick={onSave} disabled={disabled || isLoading} className={`px-3 py-2 text-sm text-white rounded bg-[#007980] hover:bg-opacity-80 transition-all flex items-center gap-2 ${disabled || isLoading ? "opacity-50 cursor-not-allowed" : ""}`} >
                  {isLoading && (
                    <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" >
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" /> <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 100 16v-4l-3 3 3 3v-4a8 8 0 01-8-8z" />
                    </svg>
                  )}
                  {isLoading ? "loading..." : saveButtonText}
                </button>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default PopUpModal;
