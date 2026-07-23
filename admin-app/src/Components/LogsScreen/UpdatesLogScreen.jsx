import React, { useRef } from 'react';
import { FaCheck } from 'react-icons/fa';
import DOMPurify from 'dompurify';

const UpdatesLogsScreen = ({
    isLogsVisible,
    isCanceled,
    isCompleted,
    cancelMessage,
    successMessage,
    responseMessage
}) => {
    const logsContainerRef = useRef(null);
    return (
        <div className="mt-4">

            {isLogsVisible && (
                <div>
                    <div className="relative">
                        <div ref={logsContainerRef} className="h-[300px] overflow-y-auto bg-[#161515] px-4 rounded-md">
                            {responseMessage ? (
                                <ul>
                                    <li className="text-md mt-[10px] text-[#e3e3e3]">
                                        <div
                                            dangerouslySetInnerHTML={{
                                                __html: DOMPurify.sanitize(responseMessage.replace(/\n/g, '<br>')),
                                            }}
                                        />
                                    </li>

                                </ul>
                            ) : (
                                <p className="text-sm text-[#e3e3e3]">Please wait. Fetching Logs....</p>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {!isLogsVisible && isCanceled && (
                <div className="mt-[5px] flex items-center p-5 border border-white bg-[#007980] rounded-md text-white font-medium text-sm">
                    <FaCheck className="mr-2" />
                    <p>{cancelMessage}</p>
                </div>
            )}

            {!isLogsVisible && isCompleted && !isCanceled && (
                <div className="mt-[5px] flex items-center p-5 border border-white bg-[#007980] rounded-md text-white font-medium text-sm">
                    <FaCheck className="text-white mr-2" />
                    <p>{successMessage}</p>
                </div>
            )}
        </div>
    );
};

export default UpdatesLogsScreen;
