import React from "react";
import './global.css';
import SubOptionsTable from "./SuboptionsTable/SuboptionsTable";
import CheckboxComponent from "./FormElements/CheckboxComponent";
import RadioComponent from "./FormElements/RadioComponent";
import MultipleCheckbox from "./FormElements/MultipleCheckbox";
import InputComponent from './FormElements/InputComponent';
import ColorPickerComponent from './FormElements/ColorPickerComponent';
import SelectComponent from "./FormElements/SelectComponent";
import ToggleSwitchComponent from './FormElements/ToggleSwitchComponent';
import TextareaComponent from "./FormElements/TextAreaComponent";
import ButtonComponent from "./FormElements/ButtonComponent";
import InfoComponent from "./FormElements/InfoComponent";
import DesignCanvas from "./FormElements/DesignCanvas";
import { useGlobalInput } from "../../../Components/Hooks/useGlobalInput/useGlobalInput";
import { useFeaturesData } from "../../../Components/Context/FeaturesDataContext";
import { resolveIsLocked } from "../../../Components/Utils/HelperFunctions/LockHelper";

const GlobalInputs = ({ id, type, label,links, disabled, placeholder, values, onChange,planRequired, description,field, subOptions, display, action, isSubOption, handleNestedSubmit, removebtn = "remove",hide }) => {
  const { checked, selectedValue, handleCheckboxChange, handleInputChange, handleRadioChange, handleSelectChange, setChecked,shouldShowSubOptions,handleMultipleCheckboxChange, multipleCheckboxValues } = useGlobalInput(id, values, onChange,type,display);
  const { proPluginActive } = useFeaturesData();
  // Render sub options as a grid/table when display is "grid"  
  const renderSubOptions = (subOptions, action, removebtn, handleNestedSubmit) => {
    if (display === "grid" && subOptions) {
      return <SubOptionsTable subOptions={subOptions} onChange={onChange} action={action} removebtn={removebtn} handleNestedSubmit={handleNestedSubmit} />;
    } else {
      // For flex display, render sub-options in a separate column
      if (display === "flex") {
        return (
          <div className="flex flex-col items-start gap-[10px] w-full sm:flex-row sm:flex-wrap sm:items-center sm:justify-center sm:w-auto">
            {Object.keys(subOptions).map((subKey) => {
              const subField = subOptions[subKey];
              if (subField.hide) return null;
              return (
                <div key={subKey} className="mb-4">
                  <GlobalInputs id={subField.id} type={subField.type} links={subField.links} planRequired={subField?.plan_required || field?.plan_required } disabled={resolveIsLocked(subField, proPluginActive) || subField.disabled || resolveIsLocked(field, proPluginActive)} handleNestedSubmit={handleNestedSubmit} label={subField.label} description={subField.description} placeholder={subField.placeholder} values={subField.values} onChange={onChange} subOptions={subField.sub_options} removebtn={subField.removebtn} action={subField.action} display={subField.display} parentDisplay={display} isSubOption={true}  hide={subField.hide} />
                </div>
              );
            })}
          </div>
        );
      }

      // Check if any suboptions have display="flex"
      const hasFlexSubOption = subOptions && Object.keys(subOptions).some(key => subOptions[key].display === "flex");

      return (
        // Only apply tree class if there are no flex suboptions
        <div className={hasFlexSubOption ? "" : "tree"}>
          {Object.keys(subOptions).map((subKey) => {
            const subField = subOptions[subKey];
            if (subField.hide) return null;
            // Only apply li class if this specific suboption doesn't have display="flex"
            const subFieldClass = subField.display === "flex" ? "" : "li";

            return (
              <div key={subKey} className={subFieldClass}>
                <GlobalInputs id={subField.id} type={subField.type} links={subField.links} hide={subField.hide} planRequired={subField?.plan_required || field?.plan_required } disabled={resolveIsLocked(subField, proPluginActive) || subField.disabled || resolveIsLocked(field, proPluginActive)} label={subField.label} handleNestedSubmit={handleNestedSubmit} description={subField.description} placeholder={subField.placeholder} values={subField.values} onChange={onChange} subOptions={subField.sub_options} removebtn={subField.removebtn} action={subField.action} display={subField.display} parentDisplay={display} />
              </div>
            );
          })}
        </div>
      );
    }
  };  
  let FormElement = null;
  let wrapperClass = "rounded-lg border-[rgb(197,197,197)]";

  const hasInteraction = () => {
    switch (type) { case "radio": return !!selectedValue; case "multiple_checkbox": return multipleCheckboxValues && Object.values(multipleCheckboxValues).some(option => option.selected); case "checkbox": case "toggleSwitch": return checked; case"copy":  case "input": case "email": case "number": case "password": case "textarea": case "select": case "color": return selectedValue !== "";  default: return false; }
  };

  if (type === "design") {
    wrapperClass = "";
  } else if (display === "flex") {
    // Special styling for elements with display: flex
    wrapperClass = "rounded-lg";
  } else if (!disabled && !isSubOption && !field?.is_locked) {
    // Standard styling for non-disabled, non-subOption elements
    if (type === "checkbox" || type === "input" || type ==="number" || type === "password" || type === "email" || type ==="copy" || type === "textarea" || type === "multiple_checkbox" || type === "color") {
      wrapperClass += ` ${hasInteraction() ? 'bg-gradient-to-br from-blue-50 to-[#85cbcf] border border-[#85cbcf] shadow-md' : 'bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-300 shadow-md '}`;
    }
    if (type === "toggleSwitch") {
      wrapperClass = "ml-[10px] mb-[20px]";
    }
  } else {
    // Default styling for disabled or subOption elements
    wrapperClass += 'bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-300 shadow-md';
  }

  switch (type) {
    case "radio":
      FormElement = (
        <RadioComponent id={id} display={display} label={label} description={description} values={values} selectedValue={selectedValue} disabled={disabled} handleRadioChange={handleRadioChange} renderSubOptions={renderSubOptions} />
      );
      break;
      case "multiple_checkbox":
      FormElement = (
        <MultipleCheckbox id={id} type={type} display={display} label={label} checked={checked} description={description} values={multipleCheckboxValues} handleMultipleCheckboxChange={handleMultipleCheckboxChange} />
      );
      break;
    case "checkbox":
      FormElement = (
        <CheckboxComponent id={id} type={type} label={label} description={description} checked={checked} planRequired={planRequired || field?.plan_required} disabled={disabled || field?.is_locked} commingSoon={field?.comming_soon === true} display={display} handleCheckboxChange={handleCheckboxChange} />
      );
      break;

    case "input": case "email": case "number": case "password": case "copy":
      FormElement = (
        <InputComponent id={id} type={type} label={label} description={description} placeholder={placeholder} field={field} planRequired={planRequired || field?.plan_required} selectedValue={selectedValue} disabled={disabled} display={display} handleInputChange={handleInputChange}/>
      );
      break;

    case "color":
      FormElement = (
        <ColorPickerComponent id={id} label={label} description={description} placeholder={placeholder} planRequired={planRequired || field?.plan_required} selectedValue={selectedValue} disabled={disabled} display={display} handleInputChange={handleInputChange} />
      );
      break;

    case "select":
      FormElement = (
        <SelectComponent id={id} label={label} description={description} values={values} selectedValue={selectedValue} handleSelectChange={handleSelectChange} display={display} renderSubOptions={renderSubOptions} />
      );
      break;
    case "toggleSwitch":
      FormElement = (
        <ToggleSwitchComponent id={id} type={type} label={label} description={description} checked={checked} disabled={disabled} display={display} handleCheckboxChange={handleCheckboxChange} />
      );
      break;

    case "textarea":
      FormElement = (
        <TextareaComponent id={id} label={label} description={description} placeholder={placeholder} selectedValue={selectedValue} handleInputChange={handleInputChange} />
      );
      break;
    case "button":
      FormElement = (
        <ButtonComponent id={id} label={label} hide={hide} />
      );
      break;
    case "info":
      FormElement = (
        <InfoComponent id={id} label={label} description={description} links={links} />
      );
      break;
    case "design":
      FormElement = (
        <DesignCanvas
          id={id}
          label={label}
          description={description}
          subOptions={subOptions}
          onChange={onChange}
          preview={field?.preview}
          renderField={(subField) => (
            <GlobalInputs
              id={subField.id}
              type={subField.type}
              links={subField.links}
              planRequired={subField?.plan_required || field?.plan_required}
              disabled={resolveIsLocked(subField, proPluginActive) || subField.disabled || resolveIsLocked(field, proPluginActive)}
              label={subField.label}
              description={subField.description}
              placeholder={subField.placeholder}
              values={subField.values}
              onChange={onChange}
              subOptions={subField.sub_options}
              removebtn={subField.removebtn}
              action={subField.action}
              display={subField.display}
              parentDisplay={display}
              isSubOption={true}
              field={subField}
              hide={subField.hide}
            />
          )}
        />
      );
      break;
    default:
      FormElement = null;
  }

  return (
    <div>
      {display !== "flex" && <div>&nbsp;</div>}
      <div className={`${display === "flex" ? "flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:gap-[50px]" : ""} ${type === "button" ? "pt-[28px]" : ""} ${type === "select" ? "pt-[10px]" : ""}`} >
        <div className={`form-element-wrapper ${wrapperClass} ${display === "flex" ? "flex items-center w-full sm:w-auto" : ""}`}
          onClick={() => {
            if ((type === "checkbox" || type === "toggleSwitch") && !disabled && !field?.is_locked && field?.comming_soon !== true) {
              const newChecked = !checked;
              setChecked(newChecked);
              const newValue = { selected: newChecked, value: selectedValue };
              onChange(id, newValue);
            }
          }}
        >
          {FormElement}
        </div>        
        {/* Render sub-options inline for flex display with proper alignment */}
        {display === "flex" && subOptions && shouldShowSubOptions() && (
          <div className="flex items-center w-full sm:w-auto">
            {renderSubOptions(subOptions, action, removebtn, handleNestedSubmit)}
          </div>
        )}
      </div>      
      {/* Render sub-options below for non-flex display (design renders its own) */}
      {display !== "flex" && type !== "design" && subOptions && shouldShowSubOptions() && (
        <div>
          {renderSubOptions(subOptions, action, removebtn, handleNestedSubmit)}
        </div>
      )}
    </div>
  );
};

export default GlobalInputs;