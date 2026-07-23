import React from 'react'
import { useEffect,useState } from "react";
import axios from "axios";
import { useLocation } from "react-router-dom";
import { toast } from "react-toastify";

/* global wptw_ajax */

const CheckLicense = () => {
    const location = useLocation();
    const [licenseStatus, setLicenseStatus] = useState(null);
    const [planName,setPlanName] = useState(null);
    const [pluginStatus,setPluginStatus] = useState(null);

    useEffect(() => {        
        const checkLicense = async () => {
          try {
            const formData = new FormData();
            formData.append("action", "wptw_global_ajax_handler");
            formData.append("action_type", "wptw_verify_license");
            formData.append("nonce", wptw_ajax.nonce);
    
            const response = await axios.post(wptw_ajax.ajax_url, formData, {
              headers: {
                "Content-Type": "multipart/form-data",
              },
            });            
            if (response.status === 200) {
              const connectionStatus = response.data.data.data.connection;
              setLicenseStatus(connectionStatus);               
              setPlanName(response.data.data.data.planName);
              setPluginStatus(response.data.data.data.premium_plugin_status);
            }else{

            }            
          } catch (error) {            
          }
        };
    
        checkLicense();
      }, [location]); 
      return {licenseStatus,planName,pluginStatus};
    };

export default CheckLicense