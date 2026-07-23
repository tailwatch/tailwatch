import React from 'react';
import PropTypes from 'prop-types';

const DeleteButton = ({ onClick, isDisabled, status, defaultText, className }) => {  
  const buttonClass = `!px-3 !py-2 rounded-md text-sm text-white ${ isDisabled ? "bg-gray-400 cursor-not-allowed" : "bg-[#d44d4d] hover:bg-opacity-80"} ${
    status === 'in-progress' || status === 'pause'
      ? 'bg-gray-400 cursor-not-allowed'
      : 'bg-[#d44d4d] hover:bg-opacity-80'
  } ${className}`;

  return (
    <button
      className={buttonClass}
      disabled={isDisabled}
      onClick={onClick}
    >
      {defaultText}
    </button>
  );
};


export default DeleteButton;
