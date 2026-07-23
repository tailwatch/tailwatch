import React from 'react';

const Checkbox = ({
  id,
  label,
  description,
  checked,
  onChange,
  disabled,
  disableFlex = false,
}) => (
  <div className={`${disableFlex ? 'text-center' : 'flex'} items-center`}>
    <div className={`${disableFlex ? 'text-center' : 'flex'} items-center`}>
      <input
        id={id}
        type="checkbox"
        aria-describedby={`${id}-description`}
        className="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600"
        checked={checked}
        onChange={onChange}
        disabled={disabled}
      />
    </div>
    <div className="ms-2 text-sm">
      {label && (
        <label htmlFor={id} className="font-medium text-black">
          {label}
        </label>
      )}
      {description && (
        <p id={`${id}-description`} className="text-xs font-normal text-black">
          {description}
        </p>
      )}
    </div>
  </div>
);

export default Checkbox;
