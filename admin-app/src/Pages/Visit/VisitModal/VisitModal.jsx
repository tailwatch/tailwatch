import { RefreshCw } from "lucide-react";
import DOMPurify from 'dompurify';

/* global tailwatch_ajax */

const VisitModal = ({ isOpen, message }) => {
  const handleBackToWordPress = () => {
    window.location.href = tailwatch_ajax.admin_url;
  };

  const handleRefresh = () => {
    window.location.reload();
  };

  if (!isOpen) return null;

  // Sanitize HTML to prevent XSS attacks
  const sanitizedMessage = DOMPurify.sanitize(message);

  return (
    <div className="fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-[9999]">
      <div className="bg-white rounded-lg shadow-lg p-6 w-[500px] relative z-[10000]">
        <p
          className="text-gray-700 mb-4"
          dangerouslySetInnerHTML={{ __html: sanitizedMessage }}
        ></p>
        <div className="flex space-x-3">
          <button
            onClick={handleBackToWordPress}
            className="bg-[#007980] hover:bg-opacity-80 text-white py-2 px-4 rounded flex items-center gap-2"
          >
            Back to WordPress
          </button>
          <button
            onClick={handleRefresh}
            className="bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 cursor-pointer px-4 rounded flex items-center justify-center"
          >
            <RefreshCw size={20} color="#007980" />
          </button>
        </div>
      </div>
    </div>
  );
};

export default VisitModal;
