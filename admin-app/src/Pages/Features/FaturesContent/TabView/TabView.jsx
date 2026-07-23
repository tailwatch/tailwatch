import React from "react";
import { Spinner } from '../../../../Components/Spinner/Spinner';
import GlobalInputs from "../../GlobalInput/GlobalInputs";
import { useFormData } from "../../../../Components/Context/FormContext";

const TabView = ({ tabConfig, activeTab, setActiveTab, data, handleInputChange, handleNestedSubmit }) => {
  const { loading  } = useFormData();
  
  const renderTabFields = (fieldIds) => {    
    if (!Array.isArray(fieldIds) || !data.options) {
      return null;
    }

    return fieldIds.map(fieldId => {
      if (!data.options[fieldId]) {
        return null;
      }

      const field = data.options[fieldId];            
      let actionValue;
      let removeBtn;

      // First check if action exists directly on the field
      if (field.action) {
        actionValue = field.action;
      }
      
      // First check if remove_btn exists directly on the field
      if (field.remove_btn) {
        removeBtn = field.remove_btn;
      }      

      // If action or removeBtn not found on field, then check in sub_options
      if ((!actionValue || !removeBtn) && field.sub_options) {
        const subOptionKeys = Object.keys(field.sub_options);
        for (const key of subOptionKeys) {
          const subOpt = field.sub_options[key];
          if (subOpt) {
            if (!actionValue && subOpt.action) {
              actionValue = subOpt.action;
            }
            if (!removeBtn && subOpt.remove_btn) {
              removeBtn = subOpt.remove_btn;
            }
            if (actionValue && removeBtn) break; // exit early if both found
          }
        }
      }
      
 

      return (
        <div key={fieldId} className="">
          <GlobalInputs
            id={field.id}
            type={field.type}
            label={field.label}
            description={field.description}
            placeholder={field.placeholder}
            values={field.values}
            onChange={handleInputChange}
            subOptions={field.sub_options}
            display={field.display}
            links={field.links}
            handleNestedSubmit={handleNestedSubmit}
            action={actionValue}
            removebtn={removeBtn}
            field={field} 
            hide={field.hide}
            planRequired={field?.values?.option?.plan_required}
          />
        </div>
      );
    });
  };

  return (
    <div className="w-full relative">
      <div className="flex border-b mt-2">
        {tabConfig.map((tab, index) => (
          <button
            key={tab.tab_id || index}
            className={`px-4 py-2 ${activeTab === index
              ? 'border-b-2 border-[#007980] text-[#007980] font-medium'
              : 'text-gray-500 hover:text-gray-700'}`}
            onClick={() => setActiveTab(index)}
            type="button"
          >
            {tab.tab_title}
          </button>
        ))}
      </div>      
      <div>
        {tabConfig[activeTab] && renderTabFields(tabConfig[activeTab].field_ids)}
      </div>
      {loading && (
        <div className="fixed inset-0 flex items-center justify-center bg-white bg-opacity-50 backdrop-blur-sm z-50">
          <div className="flex flex-col items-center">
            <Spinner />
            <p className="mt-2 text-gray-700 font-medium">Updating data</p>
          </div>
        </div>
      )}
    </div>
  );
};

export default TabView;