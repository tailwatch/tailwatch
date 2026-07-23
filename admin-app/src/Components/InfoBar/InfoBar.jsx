import React from 'react';
import { IoClose } from "react-icons/io5";
import { GiPauseButton } from "react-icons/gi";
import { FaInfoCircle } from "react-icons/fa";

const InfoBar = ({ type, message, onClose, showCloseButton = false }) => {
  const isInfo = type === "info";

  const alertClasses = {
    danger: {
      container: "text-[#4d4d4d] border-t-4 border-red-300 bg-red-50 dark:text-[#4d4d4d]",
      button: "bg-red-50 text-red-500 hover:bg-red-200 focus:ring-red-400 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-gray-700"
    },
    info: {
      container: "text-[#4d4d4d] bg-gradient-to-br from-amber-100 via-orange-50 to-rose-100 dark:text-[#4d4d4d] rounded",
      button: "bg-blue-50 text-blue-500 hover:bg-blue-200 focus:ring-blue-400 dark:bg-gray-800 dark:text-blue-400 dark:hover:bg-gray-700"
    },
    success: {
      container: "text-[#4d4d4d] border-t-4 border-green-300 bg-green-50 dark:text-[#4d4d4d]",
      button: "bg-green-50 text-green-500 hover:bg-green-200 focus:ring-green-400 dark:bg-gray-800 dark:text-green-400 dark:hover:bg-gray-700"
    },
    warning: {
      container: "text-[#4d4d4d] border-t-4 border-yellow-300 bg-yellow-50 dark:text-[#4d4d4d]",
      button: "bg-yellow-50 text-[#4d4d4d] hover:bg-yellow-200 focus:ring-yellow-400 dark:bg-gray-800 dark:text-yellow-400 dark:hover:bg-gray-700"
    },
    progress: {
      container: "text-[#4d4d4d] border-t-4 border-blue-300 bg-blue-50 dark:text-[#4d4d4d]",
      button: "bg-blue-50 text-blue-500 hover:bg-blue-200 focus:ring-blue-400 dark:bg-gray-800 dark:text-blue-400 dark:hover:bg-gray-700"
    },
    paused: {
      container: "text-[#4d4d4d] border-t-4 border-blue-300 bg-blue-50 dark:text-[#4d4d4d]",
      button: "bg-blue-50 text-blue-500 hover:bg-blue-200 focus:ring-blue-400 dark:bg-gray-800 dark:text-blue-400 dark:hover:bg-gray-700"
    }
  };

  const content = (
    <div className={`flex items-center p-4 ${alertClasses[type]?.container}`} role="alert">
      {type === "progress" ? (
        <svg className='w-[30px]' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><path fill="#847A7A" stroke="#847A7A" stroke-width="2" transform-origin="center" d="m148 84.7 13.8-8-10-17.3-13.8 8a50 50 0 0 0-27.4-15.9v-16h-20v16A50 50 0 0 0 63 67.4l-13.8-8-10 17.3 13.8 8a50 50 0 0 0 0 31.7l-13.8 8 10 17.3 13.8-8a50 50 0 0 0 27.5 15.9v16h20v-16a50 50 0 0 0 27.4-15.9l13.8 8 10-17.3-13.8-8a50 50 0 0 0 0-31.7Zm-47.5 50.8a35 35 0 1 1 0-70 35 35 0 0 1 0 70Z"><animateTransform type="rotate" attributeName="transform" calcMode="spline" dur="1.6" values="0;120" keyTimes="0;1" keySplines="0 0 1 1" repeatCount="indefinite"></animateTransform></path></svg>
      ) : type === "paused" ? (
        <GiPauseButton className="text-blue-500" />
      ) : (
        <FaInfoCircle className="flex-shrink-0 w-5 h-5 text-orange-600" />
      )}
      <div className="ms-3 text-sm font-medium">
        {message}
      </div>
      {showCloseButton && (
        <IoClose onClick={onClose} className="ms-auto text-[20px] cursor-pointer text-black" />
      )}
    </div>
  );

  // If it's info type, wrap in a gradient border div
  if (isInfo) {
    return (
      <div className="pt-[3px] rounded bg-gradient-to-r from-orange-500 to-amber-500 mb-4">
        {content}
      </div>
    );
  }

  return <div className="mb-4">{content}</div>;
};

export default InfoBar;
