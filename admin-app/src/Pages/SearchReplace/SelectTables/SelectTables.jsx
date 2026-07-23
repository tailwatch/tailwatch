import React from 'react';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { SettingItem } from '../AdditionalSettings/SettingItem';
const SelectTables = ({ tables, selectedTables, setSelectedTables, disabled }) => {
    const showDeselectAll = selectedTables.length >= 2;

    const toggleSelectAll = () => {
        if (showDeselectAll) {
            setSelectedTables([]);
        } else {
            setSelectedTables(tables);
        }
    };

    const handleCheckboxChange = (table) => {
        setSelectedTables((prevSelected) => prevSelected.includes(table) ? prevSelected.filter((item) => item !== table) : [...prevSelected, table]
        );
    };

    return (
        <div className="mb-6">
            <div className="flex justify-between items-center mb-3">
                <h2 className="text-lg font-bold text-gray-800 relative pl-4">
                    <div className="absolute left-0 top-1/2 transform -translate-y-1/2 w-1 h-5 bg-gradient-to-r from-amber-500 to-rose-500 rounded-full"></div>
                    Select Database Tables
                </h2>
                <ActionButton defaultText={showDeselectAll ? 'Deselect All' : 'Select All'} onClick={toggleSelectAll} isDisabled={disabled || tables.length === 0}/>
            </div>

            {/* Tables Container */}
            <div className="bg-gray-50 rounded-xl border-2 border-gray-200 overflow-hidden">
                {tables.length === 0 ? (
                    <div className="flex items-center justify-center py-10">
                        <div className="text-center">
                            <div className="w-12 h-12 mx-auto mb-3 bg-gray-200 rounded-full flex items-center justify-center">
                                <svg className="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2 2v-5m16 0h-2M4 13h2" />
                                </svg>
                            </div>
                            <p className="text-base font-medium text-gray-600">No data exists</p>
                        </div>
                    </div>
                ) : (
                    <div className="p-3">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 overflow-y-auto"
                            style={{ maxHeight: '320px', scrollbarWidth: 'thin', scrollbarColor: '#cbd5e0 #f7fafc' }}
                        >
                            {tables.map((table, index) => (
                                <SettingItem key={index} id={`table-${index}`} label={table} checked={selectedTables.includes(table)} onChange={() => handleCheckboxChange(table)} disabled={disabled} />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default SelectTables;
