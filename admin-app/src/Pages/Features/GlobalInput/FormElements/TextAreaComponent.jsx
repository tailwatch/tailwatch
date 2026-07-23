import React from 'react';

const TextareaComponent = ({ id, label, description, placeholder, selectedValue, handleInputChange }) => {
  return (
    <div className="!p-4 !rounded-lg">
      <label htmlFor={id} className="!form-label !text-md !text-black !mb-2">{label}</label>
      <textarea 
        id={id} 
        placeholder={placeholder} 
        value={selectedValue} 
        onChange={handleInputChange} 
        className="border border-gray-300 !rounded !px-2 !mt-2 !w-full focus:outline-none focus:border-[#007980] !text-black" 
        rows="4" 
      />
      <p className="!text-black !text-sm">{description}</p>
    </div>
  );
};

export default TextareaComponent;