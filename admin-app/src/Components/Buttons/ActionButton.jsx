import React from 'react';
import {bgprimary } from '../Utils/Colors/Colors';

const ActionButton = ({
  onClick,
  isDisabled,
  isInProgress,
  isCompleted,
  isCanceled,
  isFeatureEnable,
  defaultText,
  inProgressText,
  completedText,
  canceledText,
  className,
  isLoading,
  loadingText = 'Loading...',
}) => {
  // Ensure isDisabled is part of the conditional class logic
  const buttonClass = `!px-3 !py-2 !rounded-md !text-white !text-sm inline-flex items-center justify-center gap-2 ${
    isDisabled || isCanceled || isInProgress || isCompleted || isFeatureEnable || isLoading
      ? '!bg-gray-500 cursor-not-allowed'  // Apply grey background when disabled
      : `${bgprimary} hover:bg-opacity-80` // Apply default style when active
  } ${className || ''}`;

  return (
    <button
      onClick={onClick}
      className={buttonClass}
      disabled={isInProgress || isCompleted || isCanceled || isFeatureEnable || isDisabled || isLoading}
    >
      {isLoading && (
        <svg className="animate-spin h-3.5 w-3.5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
        </svg>
      )}
      <span>
        {isLoading ? loadingText :
          isCanceled ? canceledText :
          isCompleted ? completedText :
          isInProgress ? inProgressText :
          defaultText}
      </span>
    </button>
  );
};


export default ActionButton;
