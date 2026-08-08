import React, { createContext, useContext, useEffect, useState } from "react";
import { updateLocalStorage } from "../Utils/HelperFunctions/LocalStorageHelper";
import axios from "axios";

/* global tailwatch_ajax */
const UserContext = createContext();

export const useUser = () => {
  const context = useContext(UserContext);
  if (!context) {
    throw new Error("useUser must be used within a UserProvider");
  }
  return context;
};

export const UserProvider = ({ children }) => {
  const [userData, setUserData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [planName, setPlanName] = useState(null);
  const [planNameLoaded, setPlanNameLoaded] = useState(null);
  const [devices, setDevices] = useState([]);
  const [devicesLoaded, setDevicesLoaded] = useState(false);

  const fetchUserData = async () => {
    try {
      setLoading(true);
      setError(null);

      const ajaxUrl = tailwatch_ajax.ajax_url;
      const nonce = tailwatch_ajax.nonce;

      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_get_user_info");
      formData.append("nonce", nonce);

      const response = await axios.post(ajaxUrl, formData);

      if (response.data.success) {
        const data = response.data.data.data;
        setUserData(data);
        // User PII (email/role) is kept in React context only — never persisted to
        // localStorage, where any script on the admin page could read it. Purge any
        // copy left by older builds.
        updateLocalStorage("userSection", {}, ["userData"]);
      } else {
        throw new Error(response.data.data || "Failed to fetch user data");
      }
    } catch (error) {
      setError(error.message);
    } finally {
      setLoading(false);
    }
  };

  const refreshUserData = () => {
    fetchUserData();
  };

  const clearUserData = () => {
    setUserData(null);
    updateLocalStorage("userSection", {}, ["userData"]);
  };

  useEffect(() => {
    fetchUserData();      
  }, []);

  const value = {
    userData,
    loading,
    error,
    refreshUserData,
    clearUserData,
    isLoggedIn: !!userData,
    planName,
    setPlanName,
    devices,
    devicesLoaded,
    planNameLoaded,
  };

  return <UserContext.Provider value={value}>{children}</UserContext.Provider>;
};
