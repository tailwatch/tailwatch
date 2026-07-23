import React, { useState } from 'react';
import { FaLock, FaInfoCircle, FaCopy, FaCheck } from 'react-icons/fa';
import { Crown } from 'lucide-react';

const InputComponent = ({ id, type, label,field, description, planRequired, placeholder, selectedValue, disabled, display, handleInputChange }) => {
    
    const [copied, setCopied] = useState(false);

    const inputWrapperClass = display === "flex" ? "gap-2 pt-2 flex items-center" : "p-4 rounded-lg cursor-pointer";

    const handleCopy = () => {
        navigator.clipboard.writeText(selectedValue);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className={inputWrapperClass}>
            <div>
                <div className="flex items-center mb-1">
                    <label htmlFor={id} className={`form-label text-black font-semibold ${display === "flex" ? "text-sm" : "text-lg"}`}>
                        {label}
                    </label>

                    {/* ✅ Crown badge next to label when disabled/locked */}
                    {disabled && (
                        <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold shadow-sm">
                            <Crown className="w-3.5 h-3.5 text-amber-500" />
                            <span>{planRequired || 'Pro'}</span>
                        </span>
                    )}

                    {display === "flex" && description && (
                        <div className="ml-2 relative group">
                            <span className="absolute bottom-full mb-2 left-1/2 transform -translate-x-1/2 w-44 bg-gray-800 text-white text-xs rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none z-10">
                                {description}
                            </span>
                            <FaInfoCircle className="text-gray-500 cursor-pointer" />
                        </div>
                    )}
                </div>

                {type === "copy" ? (
                    <div className="flex items-center relative">
                        <input
                            type="text"
                            id={id}
                            value={selectedValue}
                            onChange={handleInputChange}
                            placeholder={placeholder}
                            readOnly={true}
                            className="border text-sm text-black rounded-l px-2 py-1 w-full sm:w-64 bg-gray-100 border-gray-400 placeholder:text-sm focus:outline-none focus:border-[#007980]"
                        />
                        <div
                            className="flex items-center justify-center bg-gray-200 border border-gray-400 border-l-0 rounded-r px-3 py-[6px] cursor-pointer hover:bg-gray-300 transition-colors duration-200"
                            onClick={handleCopy}
                            title="Copy to clipboard"
                        >
                            {copied ? <FaCheck className="text-green-600" /> : <FaCopy className="text-gray-600" />}
                        </div>
                    </div>
                ) : (
                    <div className="relative">
                        <input
                            type={type}
                            id={id}
                            value={disabled ? '' : selectedValue}
                            onChange={handleInputChange}
                            placeholder={disabled ? '' : placeholder}
                            disabled={disabled}
                            className={`border text-sm text-black rounded px-2 py-1 w-full sm:w-64 ${disabled ? 'bg-gray-100 border-gray-300 cursor-not-allowed' : 'border-gray-300 focus:outline-none focus:border-[#007980]'} placeholder:text-sm`}
                        />
                        {/* ✅ Lock icon inside disabled input */}
                        {disabled && (
                            <div className="absolute inset-y-0 left-2 flex items-center gap-1.5 text-gray-400 text-xs pointer-events-none">
                                <FaLock className="w-3 h-3 text-amber-500" />
                                <span className="text-gray-400">Locked</span>
                            </div>
                        )}
                    </div>
                )}

                {display !== "flex" && <p className="text-black text-sm">{description}</p>}
            </div>
        </div>
    );
};

export default InputComponent;