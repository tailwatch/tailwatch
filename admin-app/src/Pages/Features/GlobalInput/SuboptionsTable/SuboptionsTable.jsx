import React, { useState } from 'react';
import GlobalInputs from '../GlobalInputs';
import IconButton from '../../../../Components/Buttons/IconButton';
import { Trash2, PlusCircle } from "lucide-react";
import {tableGradient } from '../../../../Components/Utils/Colors/Colors'
const SubOptionsTable = ({ subOptions, onChange, action, handleNestedSubmit, removebtn }) => {
  const [rowStates, setRowStates] = useState({});
  const handleToggleChange = (id, newValue) => {
    // enable or disable on click of toggle switch
    setRowStates(prev => ({
      ...prev,
      [id]: newValue.selected
    }));
    onChange(id, newValue);
  };

  const handleDeleteRow = (e, fieldId, fieldKey, fieldIndex) => {
    if (e) e.preventDefault();
    const fieldKeys = Object.keys(subOptions);
    let prevFieldId = null;
    let prevFieldKeyName = null;

    if (fieldIndex > 0) {
      const prevFieldKey = fieldKeys[fieldIndex - 1];
      const prevField = subOptions[prevFieldKey];
      prevFieldId = prevField.id;
      prevFieldKeyName = prevField.key;
    }

    if (handleNestedSubmit) {
      handleNestedSubmit(false, fieldId, fieldKey, false, prevFieldId, prevFieldKeyName, true);
    }
  };

  const handleAddRowClick = (e, fieldId, fieldKey) => {
    if (e) e.preventDefault();

    if (handleNestedSubmit) {
      handleNestedSubmit(true, fieldId, fieldKey, true);
    }
  };

  return (
    <div className="mt-4 mb-4">
      <div className="overflow-x-auto rounded-lg shadow-lg border border-gray-300">
        <table className="min-w-full bg-white rounded-lg overflow-hidden">
          <thead className={`${tableGradient} text-white text-sm uppercase tracking-wider`}>
            <tr>
              <th className="py-3 px-6 text-left">Options</th>
              <th className="py-3 px-6 text-left">Action</th>
            </tr>
          </thead>

          <tbody className="divide-y divide-gray-200">
            {Object.keys(subOptions).map((subKey, index) => {
              const subField = subOptions[subKey];
              const isToggleField = subField.type === "checkbox" || subField.type === "toggleSwitch";
              const isEnabled = rowStates[subField.id] !== undefined ? rowStates[subField.id] : (subField.values?.option?.selected || false);
              const hideButton = subField.hide === 'true';
              const hideRemBtn = subField.remove_btn === 'hide';

              return (
                <tr key={subKey} className={`hover:bg-gray-100 transition duration-200 ${index % 2 === 0 ? "bg-gray-50" : "bg-white"}`} >
                  <td className="py-4 px-6">
                    <div className="flex items-center">
                      <GlobalInputs id={subField.id} type={subField.type} disabled={subField.is_locked || subField.disabled} label={subField.label} description="" values={subField.values} placeholder={subField.placeholder} onChange={isToggleField ? handleToggleChange : onChange} subOptions={subField.sub_options} isSubOption={true} parentDisplay="grid" display={subField.display} />
                    </div>
                  </td>

                  <td className="py-4 px-6 items-center space-x-1">
                    {action === "button" ? (
                      <div className="flex items-center space-x-2">
                        {!hideRemBtn && removebtn === "remove" && (
                          <IconButton tooltip="Delete" icon={Trash2} onClick={(e) => handleDeleteRow(e, subField.id, subField.key, index)} bgColor="bg-gray-200" hoverBgColor="hover:bg-red-100" textColor="text-red-500 hover:text-red-600" roundedFull={true} className="p-2 transition duration-200 !rounded-[5px] hover:shadow-sm" />
                        )}
                        {!hideButton && (
                          <IconButton disabled={!isEnabled} tooltip="Add Row" icon={PlusCircle} onClick={(e) => handleAddRowClick(e, subField.id, subField.key, index)} bgColor="bg-gray-200" hoverBgColor={isEnabled ? "hover:bg-green-100" : ""} textColor={isEnabled ? "text-green-600 hover:text-green-700" : ""} roundedFull={true} className="p-2 transition !rounded-[5px] duration-200 hover:shadow-sm" />
                        )}
                      </div>
                    ) : (
                      isToggleField ? (
                        <span className={`px-3 py-1 rounded-full text-xs font-semibold ${isEnabled ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"}`}>
                          {isEnabled ? "Enabled" : "Disabled"}
                        </span>
                      ) : (
                        <span className="text-sm text-gray-600">
                          {subField.values?.option?.value || "--"}
                        </span>
                      )
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default SubOptionsTable;
