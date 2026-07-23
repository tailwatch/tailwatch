import React from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { FiMail } from 'react-icons/fi';
import { XCircle, CheckCircle } from 'lucide-react';
import DOMPurify from 'dompurify';

export const EmailModal = ({ handleModalClose, currentDetail }) => {
  const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    try {
      const date = new Date(dateString);
      return date.toLocaleString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch (e) {
      return dateString;
    }
  };

  const emailContent = (
    <div className="space-y-4">
      {/* Email header details */}
      <div className="flex items-center mb-6">
        <div className="bg-[#07C07E1A] rounded-full p-3 text-blue-600">
          <FiMail className="w-8 h-8 text-[#007980]" />
        </div>
        <div className="ml-4">
          <h3 className="font-medium text-gray-900">Subject: {currentDetail.subject || 'N/A'}</h3>
          <p className="text-sm text-gray-700">From: {currentDetail.delivery_url || 'N/A'}</p>
          <p className="text-sm text-gray-700">To: {currentDetail.sent_to || 'N/A'}</p>
          <p className="text-sm text-gray-700">Date: {formatDate(currentDetail.delivery_time)}</p>
        </div>
      </div>
      {/* <div className="space-y-2 text-sm border-b pb-4">
        <p className="text-base text-black">
          From: <span className="text-gray-500">{currentDetail.delivery_url}</span>
        </p>
        <p className="text-base text-black">
          To: <span className="text-gray-500">{currentDetail.sent_to}</span>
        </p>
        <p className="text-base text-black">
          Date: <span className="text-gray-500">{currentDetail.delivery_time}</span>
        </p>
        <p className="text-base text-black">
          Subject: <span className="text-gray-500">{currentDetail.subject}</span>
        </p>
        <p className="text-base text-black">
          Delivery URL: <span className="text-gray-500">{currentDetail.delivery_url}</span>
        </p>
      </div> */}

      {/* Email message body */}
      <div className="border border-gray-200 prose max-w-none">
        {currentDetail.message ? (
          <div
            className="text-gray-700 leading-relaxed bg-white rounded-lg p-4"
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(currentDetail.message) }}
          />
        ) : (
          <div className="text-gray-500 italic bg-gray-50 rounded-lg p-4 text-center">
            No message content available
          </div>
        )}
      </div>
    </div>
  );

  return (
    <PopUpModal title="View Email"  onClose={handleModalClose} width="w-full max-w-4xl" height="max-h-[90vh]" showExpandIcon={true} showCloseIcon={true} cancelButtonText="Close" >
      {emailContent}
    </PopUpModal>
  );
};

export const ErrorModal = ({ handleModalClose, currentError }) => {
  const hasError = typeof currentError === "string" && currentError.trim() !== "";

  return (
    <PopUpModal title="Status Details" onClose={handleModalClose} width="w-full max-w-2xl" height="max-h-[30vh]" showCloseIcon={true} showExpandIcon={true} cancelButtonText="Close" >
      {hasError ? (
        <div className="bg-red-50 p-4 rounded-md shadow-sm border border-red-200 mb-4">
          <div className="flex items-start">
            <XCircle className="text-red-500 mt-1 mr-2 flex-shrink-0" size={20} />
            <p className="text-base text-gray-800 font-medium">
              {currentError}
            </p>
          </div>
        </div>
      ) : (
        <div className="bg-green-50 p-4 rounded-md shadow-sm border border-green-200 mb-4">
          <div className="flex items-start">
            <CheckCircle className="text-green-500 mt-1 mr-2 flex-shrink-0" size={20} />
            <p className="text-base text-gray-800 font-medium">
              All set. No errors detected.
            </p>
          </div>
        </div>
      )}
    </PopUpModal>
  );
};