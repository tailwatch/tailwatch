import React, { useState } from 'react';

const ErrorAccordian = ({ errors }) => {
    const [isExpanded, setIsExpanded] = useState(false);

    // Remove duplicate errors
    const uniqueErrors = Array.from(new Set(errors));

    return (
        <div className="p-4 bg-gray-50 border rounded-lg mb-[20px]">
            <div
                className="flex items-center justify-between cursor-pointer bg-red-100 border border-red-300 px-4 py-2 rounded"
                onClick={() => setIsExpanded(!isExpanded)}
            >
                <h3 className="text-red-600 font-medium">
                    {isExpanded ? 'Hide Errors' : 'Show Errors'}
                </h3>
                <span className="text-red-500">
                    {isExpanded ? '▲' : '▼'}
                </span>
            </div>

            {isExpanded && (
                <div className="mt-3 max-h-60 overflow-y-auto bg-white border border-gray-300 rounded-lg p-3">
                    {uniqueErrors.length > 0 ? (
                        uniqueErrors.map((error, index) => (
                            <div
                                key={index}
                                className="bg-red-50 border-l-4 border-red-400 p-3 mb-2 rounded shadow-sm"
                            >
                                <div className="flex items-start">
                                    <span className="text-red-500 mr-2">⚠️</span>
                                    <p className="text-gray-700">{error}</p>
                                </div>
                            </div>
                        ))
                    ) : (
                        <p className="text-gray-500">No errors to display.</p>
                    )}
                </div>
            )}
        </div>
    );
};

export default ErrorAccordian;
