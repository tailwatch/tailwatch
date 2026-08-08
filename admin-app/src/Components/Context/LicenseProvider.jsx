import React, { createContext, useContext, useState, useEffect, useRef } from "react";
import axios from "axios";

/* global tailwatch_ajax */

const LicenseContext = createContext();

export const useLicenseProvider = () => {
  const context = useContext(LicenseContext);
  if (!context) {
    throw new Error("useLicenseProvider must be used within a LicenseProvider");
  }
  return context;
};

export const LicenseProvider = ({ children }) => {
  const [licenseStatus, setLicenseStatus] = useState(null);
  const [planName, setPlanName] = useState(null);
  const [devices, setDevices] = useState([]);
  const [planStatus, setPlanStatus] = useState(null);
  const [suspensionReason, setSuspensionReason] = useState(null);
  const [startDate, setStartDate] = useState(null);
  const [endDate, setEndDate] = useState(null);
  const [loading, setLoading] = useState(true);
  const hasFetched = useRef(false);

  const checkLicense = async () => {
    try {
      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_verify_license");
      formData.append("nonce", tailwatch_ajax.nonce);

      const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      if (response.status === 200) {
  const data = response.data.data.data;
  setLicenseStatus(data.connection);
  setPlanName(data.planName ?? null);
  setDevices(data.devices ?? []);
  setPlanStatus(data.planStatus ?? null);
  setSuspensionReason(data.suspension_reason ?? null);
  setStartDate(data.start_date ?? null);
  setEndDate(data.end_date ?? null);
}
    } catch (error) {} finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (hasFetched.current) return;
    hasFetched.current = true;

    checkLicense();    
  }, []);

  return (
    <LicenseContext.Provider
      value={{
        licenseStatus,
        refreshLicense: checkLicense,
        planName,
        setPlanName,
        devices,
        setDevices,
        planStatus,
        suspensionReason,
        startDate,
        endDate,
        loading,
      }}
    >
      {children}
    </LicenseContext.Provider>
  );
};

export const useLicense = () => {
  return useContext(LicenseContext);
};