import React, { useState, useRef, useEffect } from 'react';
import { FaChevronDown, FaChevronUp } from 'react-icons/fa';
import { GiCheckMark } from "react-icons/gi";

const MultipleCheckbox = ({ id, label, description, values, display, handleMultipleCheckboxChange }) => {      
  const optionsArray = Object.values(values);
    
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const dropdownRef = useRef(null);
    
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsDropdownOpen(false);
      }
    };
    
    if (display === 'dropdown') {
      document.addEventListener('mousedown', handleClickOutside);
      return () => document.removeEventListener('mousedown', handleClickOutside);
    }
  }, [display]);
    
  const containerClasses = display === 'tab_view' ? 'flex flex-row gap-6 flex-wrap' : 'flex flex-col gap-4';
  
  const selectedCount = optionsArray.filter(option => option.selected).length;
  const selectedLabels = optionsArray.filter(option => option.selected).map(option => option.label);
    
  const CustomCheckbox = ({ checked }) => (
    <div className="relative !w-6 !h-6">      
      <span className={`absolute !inset-0 !rounded-md !border-2 !transition-all !duration-200 ${ 
        checked  ? '!bg-gradient-to-br !from-blue-50 !to-[#85cbcf] !border !border-[#85cbcf]' : '!bg-white !border-gray-300' }`}></span>      
      {checked && (
        <span className="absolute !bottom-[6px] !right-[-6px] !text-gray-500 !drop-shadow-md">
          <GiCheckMark className="!w-6 !h-6" />
        </span>
      )}
    </div>
  );
  
  const renderCheckboxes = () => (
    <div className={containerClasses}>
      {optionsArray.map((option, index) => (
        <label key={option.value || index} htmlFor={`${id}-${option.value}`}  className="!cursor-pointer !flex !flex-row !items-center !gap-3"  onClick={(e) => e.stopPropagation()} >
          <div className="!relative">
            <input type="checkbox" id={`${id}-${option.value}`} className="!hidden" checked={option.selected} onChange={() => handleMultipleCheckboxChange(option.value)} onClick={(e) => e.stopPropagation()} />
            <CustomCheckbox checked={option.selected} />
          </div>
          <span className="!text-sm !font-semibold !text-black">
            {option.label}
          </span>
        </label>
      ))}
    </div>
  );
  
  return (
    <div className="!p-5 !rounded-lg !border !border-gray-300 !shadow-md !transition-shadow !duration-300">      
      {label && (
        <div className="mb-4">
          <h3 className="text-lg font-medium text-gray-900 mb-1">{label}</h3>
          {description && (
            <p className="text-sm text-black">{description}</p>
          )}
        </div>
      )}
            
      {display === 'dropdown' ? (
        <div className="relative" ref={dropdownRef}>          
          <button type="button" onClick={() => setIsDropdownOpen(!isDropdownOpen)} className="w-full flex items-center justify-between px-4 py-3 bg-white border border-gray-300 rounded-lg shadow-sm text-left text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980]" >
            <span>
              {selectedCount === 0  ? 'Select options...'  : selectedCount === 1  ? selectedLabels[0] : `${selectedCount} options selected` }
            </span>
            {isDropdownOpen ? (
              <FaChevronUp className="w-4 h-4 text-gray-400" />
            ) : (
              <FaChevronDown className="w-4 h-4 text-gray-400" />
            )}
          </button>          
          {isDropdownOpen && (
            <div className="absolute z-10 w-full mt-2 bg-white border border-gray-300 rounded-lg shadow-lg">
              <div className="p-4 max-h-60 overflow-y-auto">
                {renderCheckboxes()}
              </div>
            </div>
          )}
        </div>
      ) : (        
        renderCheckboxes()
      )}
    </div>
  );
};

export default MultipleCheckbox;