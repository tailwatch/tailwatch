import React from "react";

export const CheckboxField = ({ checked, onChange, value, disabled = false, className = "" }) => {
    return (
        <input
            type="checkbox"
            checked={checked}
            onChange={onChange}
            value={value}
            disabled={disabled}
            className={`!h-4 !w-4 !rounded !border-gray-300 focus:!ring-2 focus:!ring-[#007980] disabled:!opacity-50 disabled:!cursor-not-allowed ${className}`}
            style={{ accentColor: '#007980' }}
        />
    );
};