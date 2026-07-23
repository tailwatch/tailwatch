import React from 'react';

const InputField = ({
  label,
  value,
  onChange,
  disabled = false,
  placeholder = '',
  type = 'text',
  className = '',
  required = false,
  name = '',
  id = '',
  error = '',
  fullWidth = true,
  autoFocus = false,
  readOnly,
  onBlur = () => {},
  onFocus = () => {},
  onKeyPress = () => {},
  autoComplete = 'off',
  inputProps = {},
}) => {
  return (
    <div className={`${fullWidth ? 'w-full' : ''}`}>
      {label && (
        <label 
          htmlFor={id || name} 
          className="!block !text-sm !font-medium !text-black !mb-1"
        >
          {label}{required && <span className="!text-red-500 !ml-1">*</span>}
        </label>
      )}
      <input
        type={type}
        id={id || name}
        name={name}
        value={value}
        onChange={onChange}
        disabled={disabled}
        placeholder={placeholder}
        required={required}
        readOnly={readOnly}
        autoFocus={autoFocus}
        onBlur={onBlur}
        onFocus={onFocus}
        onKeyPress={onKeyPress}
        autoComplete={autoComplete}
        {...inputProps}
        className={`!block !w-full !p-2 !border !border-gray-300 !rounded-md !shadow-sm focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] ${
          disabled ? '!bg-gray-100 !cursor-not-allowed' : ''
        } ${error ? '!border-red-500' : ''} ${className}`}
      />
      {error && <p className="mt-1 text-sm text-red-500">{error}</p>}
    </div>
  );
};

export default InputField;