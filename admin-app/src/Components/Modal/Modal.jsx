import React from 'react';
import { AiOutlineClose } from 'react-icons/ai';
import { Spinner } from '../Spinner/Spinner';

const Modal = ({ 
  isOpen, 
  onClose, 
  title, 
  message, 
  onConfirm, 
  onCancel, 
  confirmLabel, 
  cancelLabel, 
  isSubmitting, 
  isProcessing, 
  disabled = false 
}) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-gray-800 bg-opacity-50 flex items-center justify-center z-[999]">
      <div className="relative bg-white p-6 rounded-md shadow-lg">        
        <button
          onClick={onClose}
          className="absolute top-2 right-2 text-gray-500 hover:text-gray-700"
          disabled={isSubmitting}
        >
          <AiOutlineClose className="h-6 w-6" />
        </button>        
        <h2 className="text-xl font-semibold mb-4">{title}</h2>        
        <p className="mb-4">{message}</p>        
        <div className="flex justify-between gap-x-[20px]">
          {onConfirm && (
            <button
              onClick={onConfirm}
              className="px-4 py-2 mb-[10px] rounded-md text-white bg-[#007980] hover:bg-opacity-80"
              disabled={isSubmitting || disabled}
            >
              {isProcessing ? 'Processing...' : confirmLabel}
            </button>
          )}
          {onCancel && (
            <button
              onClick={onCancel}
              className="px-4 py-2 mb-[10px] rounded-md text-white bg-[#007980] hover:bg-opacity-80"
              disabled={isSubmitting || isProcessing || disabled}
            >
              {isProcessing ? 'Processing...' : cancelLabel}
            </button>
          )}
        </div>        
        {(isSubmitting || isProcessing) && (
          <div className="absolute inset-0 bg-gray-800 bg-opacity-50 z-10 flex items-center justify-center">
            <Spinner />
          </div>
        )}
      </div>
    </div>
  );
};

export default Modal;
