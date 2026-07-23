import React from "react";
import GlobalInputs from "../../GlobalInput/GlobalInputs";
import { Spinner } from "../../../../Components/Spinner/Spinner";
import { useFormData } from "../../../../Components/Context/FormContext";
import { useFeaturesData } from "../../../../Components/Context/FeaturesDataContext";
import { resolveIsLocked } from "../../../../Components/Utils/HelperFunctions/LockHelper";

const FieldList = ({ fields, handleInputChange, handleNestedSubmit }) => {
  const { loading  } = useFormData();
  const { proPluginActive } = useFeaturesData();

  const renderFields = () => {
    return Object.keys(fields).map((fieldKey) => {
      const field = fields[fieldKey];
      let actionValue = undefined;
      let removeBtnValue = undefined; // Add variable for remove_btn

      // Check for action
      if (field.action) {
        actionValue = field.action;
      } else if (field.sub_options) {
        if (field.sub_options.action) {
          actionValue = field.sub_options.action;
        } else {
          const subOptionKeys = Object.keys(field.sub_options);
          for (const key of subOptionKeys) {
            if (field.sub_options[key] && field.sub_options[key].action) {
              actionValue = field.sub_options[key].action;
              break;
            }
          }
        }
      }

      // Check for remove_btn
      if (field.remove_btn) {
        removeBtnValue = field.remove_btn;
      } else if (field.sub_options) {
        if (field.sub_options.remove_btn) {
          removeBtnValue = field.sub_options.remove_btn;
        } else {
          const subOptionKeys = Object.keys(field.sub_options);
          for (const key of subOptionKeys) {
            if (field.sub_options[key] && field.sub_options[key].remove_btn) {
              removeBtnValue = field.sub_options[key].remove_btn;
              break;
            }
          }
        }
      }
      return (
        <div key={fieldKey}>
          <GlobalInputs id={field.id} links={field.links} type={field.type} label={field.label} description={field.description} placeholder={field.placeholder} values={field.values}
            onChange={handleInputChange} subOptions={field.sub_options} display={field.display} handleNestedSubmit={handleNestedSubmit} action={actionValue} removebtn={removeBtnValue} hide={field.hide}
            disabled={resolveIsLocked(field, proPluginActive)} planRequired={field?.plan_required} field={field}
          />
        </div>
      );
    });
  };

  return (
    <div>      
      {renderFields()}
      
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

export default FieldList;