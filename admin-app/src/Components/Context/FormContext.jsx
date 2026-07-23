import React, { createContext, useState, useContext } from "react";

const FormContext = createContext();

export const FormProvider = ({ children }) => {
  const [formData, setFormData] = useState({});
  const [loading, setLoading] = useState(false);
  const [currentFeatureId, setCurrentFeatureId] = useState(null);

  const handleInputChange = (id, value) => {
    setFormData((prevFormData) => ({
      ...prevFormData,
      [id]: value,
    }));
  };

  const clearFormData = () => {
    setFormData({});
  };

  const setFeatureId = (id) => {
    setCurrentFeatureId(id);
  };

  return (
    <FormContext.Provider value={{ formData, setFormData, handleInputChange, clearFormData, currentFeatureId, setFeatureId, setLoading, loading }} >
      {children}
    </FormContext.Provider>
  );
};

export const useFormData = () => useContext(FormContext);