import React, { useEffect, useRef } from 'react';

export const Filter = ({isDropdownOpen,setIsDropdownOpen,selectedFilter,filterOptions,handleFilterSelect}) => {
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsDropdownOpen(false);
            }
        };
        
        if (isDropdownOpen) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, [isDropdownOpen, setIsDropdownOpen]);
    return (
        <div className="relative" ref={dropdownRef}>
            <button onClick={() => setIsDropdownOpen(!isDropdownOpen)} className="flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980]" >
                <span className="text-sm font-medium text-gray-700">
                    Filter: {selectedFilter}
                </span>
                <svg className={`w-4 h-4 text-gray-400 transition-transform duration-200 ${isDropdownOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24" >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            {isDropdownOpen && (
                <div className="absolute left-0 mt-1 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-10">
                    <div className="py-1">
                        {filterOptions.map((option) => (
                            <button key={option} onClick={() => handleFilterSelect(option)} className={`w-full text-left px-4 py-2 text-sm hover:bg-[#07C07E1A] ${selectedFilter === option ? 'bg-[#07C07E1A] text-[#007980] font-medium' : 'text-gray-700'}`}
                            >
                                {option}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}