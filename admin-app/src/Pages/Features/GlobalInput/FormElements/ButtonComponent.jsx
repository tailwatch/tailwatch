import React from 'react';
import { useButtonComponent } from '../../../../Components/Hooks/useButtonComponent/useButtonComponent';

const ButtonComponent = ({ label, id, disabled = false, variant = 'primary', size = 'medium', className = '' }) => {  
 
  const { handleClick } = useButtonComponent({ id });

  const variants = {
    primary: 'bg-teal-600 hover:bg-teal-700 text-white',
    secondary: 'bg-white border border-teal-600 text-teal-600 hover:bg-gray-100',
    outline: 'bg-transparent border border-teal-600 text-teal-600 hover:bg-teal-50',
    text: 'bg-transparent text-teal-600 hover:underline'
  };

  const sizes = { small: 'py-1 px-3 text-sm', medium: 'py-2 px-4 text-base', large: 'py-3 px-6 text-lg' };

  return (
    <button type="button" className={`rounded-md font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-opacity-50 ${variants[variant]} ${sizes[size]} ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'} ${className}`} onClick={handleClick} disabled={disabled} data-button-id={id} aria-disabled={disabled} >
      {label}
    </button>
  );
};

export default ButtonComponent;