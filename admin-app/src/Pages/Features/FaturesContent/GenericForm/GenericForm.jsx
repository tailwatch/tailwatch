import React, { useState, useEffect } from "react";
import "react-loading-skeleton/dist/skeleton.css";
import { toast } from "react-toastify";
import { updateData } from "../../../../Components/Api/Api";
import ActionButton from "../../../../Components/Buttons/ActionButton";
import FormContent from "../FormContent/FormContent";
import { useFormData } from "../../../../Components/Context/FormContext";
import { useFeaturesData } from "../../../../Components/Context/FeaturesDataContext";

const GenericForm = ({ featureData, onFormSubmit }) => {
  const [data, setData] = useState(() => {
    try {
      return featureData.value ? JSON.parse(featureData.value) : {};
    } catch (error) {
      return {};
    }
  });
  
  // using context
  const { formData, handleInputChange, clearFormData,setFeatureId,setLoading, loading  } = useFormData();
  const { fetchFeaturesData } = useFeaturesData();  

  useEffect(() => {
    try {
      if (featureData.value) {
        setData(JSON.parse(featureData.value));        
      } else {
        setData({});
      }
      if (featureData.id) {
        setFeatureId(featureData.id);
      }
    }
     catch (error) {
      setData({});
    }
  }, [featureData]);    

  const handleNestedSubmit = async (isDuplicate = false, fieldId = null, fieldKey = null, isHide = false, prevFieldId = null, prevFieldKeyName = null, isDelete = false) => {
    setLoading(true);    
    // Prepare the options array from formData
    const optionsToSend = Object.entries(formData).map(([key, option]) => {
    // Check if this is multiple checkbox data (has options array)
    if (option && option.options && Array.isArray(option.options)) {
      // For multiple checkbox, return the complete structure
      return {
        id: option.id,
        options: option.options,
        // Add is_duplicate if it exists
        ...(option.hasOwnProperty('is_duplicate') && { is_duplicate: option.is_duplicate }),
      };
    } else {
      // For regular fields, keep the original format
      return {
        id: option.id || key, // Use option.id if available, otherwise use the key
        selected: option.selected,
        value: option.value,
        // Add is_duplicate if it exists
        ...(option.hasOwnProperty('is_duplicate') && { is_duplicate: option.is_duplicate }),
      };
    }
  });
      
    if (isDelete) {                        
      const currentFieldOption = { id: fieldId, key: fieldKey, is_delete: true };  
      optionsToSend.push(currentFieldOption);
            
      if (prevFieldId && prevFieldKeyName) {
        const prevFieldOption = { id: prevFieldId, key: prevFieldKeyName, is_hide: false };
        optionsToSend.push(prevFieldOption);
      }
    } else if (isDuplicate && fieldId && fieldKey && isHide) {            
            
      const findFieldById = (options, id) => {
        for (const key in options) {
          const field = options[key];
          if (field.id === id) return field;
          if (field.sub_options) {
            const found = findFieldById(field.sub_options, id);
            if (found) return found;
          }
        }
        return null;
      };
  
      const currentField = findFieldById(data.options, fieldId);
      if (currentField && currentField.values && currentField.values.option) {
        const newOption = { id: fieldId, key: fieldKey, is_duplicate: true, is_hide: true};        
        optionsToSend.push(newOption);
      }
    }
  
    const formDataObj = { id: featureData.id, options: optionsToSend };      
  
    try {
      const updateResponse = await updateData(formDataObj);
      if (updateResponse?.success) {
         await fetchFeaturesData();
        toast.success("Data updated successfully!");
        if (onFormSubmit) onFormSubmit();
        clearFormData(); // Use the context method to clear form data
      } else {
        // Resync UI from server so optimistic checkbox/input state reverts
        await fetchFeaturesData();
        clearFormData();
        if (!updateResponse?.blocked_by_process) {
          throw new Error(`${updateResponse?.data?.message || 'Failed to update'}`);
        }
      }
    } catch (error) {
      try {
        await fetchFeaturesData();
        clearFormData();
      } catch (_) {
      }
    } finally {
      setLoading(false);
    }
  };
  
  const onSubmit = async (e) => {
    e.preventDefault();
    await handleNestedSubmit(false);
    clearFormData(); // Use the context method to clear form data
  };

  return (
    <form onSubmit={onSubmit}>
      <FormContent data={data} handleInputChange={handleInputChange} handleNestedSubmit={handleNestedSubmit} />
      <div className="mb-8 mt-4">
        <ActionButton defaultText="Submit" isDisabled={loading} />
      </div>
    </form>
  );
};

export default GenericForm;