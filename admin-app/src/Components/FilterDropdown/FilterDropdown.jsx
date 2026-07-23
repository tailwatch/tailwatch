import React from 'react'

const FilterDropdown = ({ value, onChange, options = ['plugin', 'theme'], className = '' }) => {
    return (
        <div className={`mb-4 flex items-center justify-end ${className}`}>
            <label htmlFor="filter" className="mr-2 text-gray-700 font-medium">
                Filter by:
            </label>
            <select
                id="filter"
                value={value}
                onChange={onChange}
                className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none"
            >
                <option value="">Select Filter</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        By {option.charAt(0).toUpperCase() + option.slice(1)}
                    </option>
                ))}
            </select>
        </div>
    );
};

export default FilterDropdown