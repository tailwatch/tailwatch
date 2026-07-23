import axios from "axios";
import { toast } from "react-toastify";
import { updateLocalStorage } from "../../../Components/Utils/HelperFunctions/LocalStorageHelper";
/* global wptw_ajax */

export const pieChartData = async () => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_disk_and_db_usage');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.data.code === 200) {
      const data = response.data.data;
      const database = data.database || {};
      const tables = database.tables || [];
      updateLocalStorage('pieChart', { 'site size': data.files.total_site_size });

      return {
        total_site_size: data.files.total_site_size,
        plugins: data.files.plugins,
        root: data.files.root,
        themes: data.files.themes,
        uploads: data.files.uploads,
        'wp-admin': data.files['wp-admin'],
        'wp-content': data.files['wp-content'],
        'wp-includes': data.files['wp-includes'],
        total_size: database.total_size || "0 B",
        tables: tables,

      };
    } else {
      toast.error(`Failed to retrieve data: ${response.data.data.message}`);
      return null;
    }
  } catch (error) {
    return null;
  }
};

export const logsCount = async (setWidgetData, setLoadingLogs) => {
  setLoadingLogs(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_dashboard_logs_count');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.data.code === 200) {
      const logs = response.data.data.logs_data.map((log) => ({
        title: log.feature_name,
        count: log.total_count,
        message: log.message
      }));

      setWidgetData(logs);
    } else {
      toast.error('Failed to retrieve logs:', response.data.data.message);
    }
  } catch (error) {
  } finally {
    setLoadingLogs(false);
  }
};


export const verifySslDetail = async (setVerifysslData, setLoadingVerify, setIsDisabled) => {
  setLoadingVerify(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_return_ssl_verify_status');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.data.code === 200) {
      const sslData = [
        {
          name: "SSL Details",
          details: {
            Ssl_Connection: response.data.data.ssl_connected ? "Connected" : "Disconnected",
            Expiry_Time: response.data.data.expiry_time,
            Https_Redirect: response.data.data.https_redirect ? "Active" : "Inactive",
            last_run: response.data.data.last_run,
            Message: response.data.data.message,

          },
        },
      ];
      setVerifysslData(sslData);
    } else if (response.data.data.code === 400) {
      if (response.data.data.feature_enable === false || response.data.data.parent_enable === false) {
        setIsDisabled(true);
      } else {
        setIsDisabled(false);
      }
    }
  } catch (error) {
  } finally {
    setLoadingVerify(false);
  }
};

export const instantVerifySsl = async (setLoading, verifySslDetail, setVerifysslData, setLoadingVerify, setIsDisabled) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_verify_ssl_connection');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    if (response.data.success) {
      await verifySslDetail(setVerifysslData, setLoadingVerify, setIsDisabled);
      return response.data.data.message;
    } else {
      toast.error('Failed to run instant verify ssl');
      return null;
    }
  } catch (error) {
    return null;
  } finally {
    setLoading(false);
  }
};


export const startSecurityFeature = async () => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_start_security_features_process');    
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });            
    
    // Return the response so we can check success
    return response;
    
  } catch (error) {
    return null;
  }
};

export const activateFeatures = async (setIsLoading, payload) => {
  setIsLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_activate_features_bulk');
    formData.append('data', JSON.stringify({feature_options:payload}));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });    

    if (response.data.data.code === 200) {
      return response.data.data;
    } else {
      toast.error('Failed to activate features');
      return null;
    }
  } catch (error) {
  } finally {
    setIsLoading(false);
  }
};

export const graphData = async (setLoadingGraph, setValue, setNotificationsData) => {  
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_features_calculate_score');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });    
    
    if (response.data.success === true) {
      setValue(response.data.data.total_score);

      const rawData = response.data.data.all_disabled_features;

      const filteredData = rawData
        .filter((item) => item.message || item.disabled_features.length > 0)
        .map((item) => ({
          key: item.feature_key || "Unknown",
          label: item.feature_name || "Unknown",
          featureMessage: item.message || "",
          disabledFeatures: item.disabled_features || [],
          planRequired: item.plan_required || "Free",
          isUpgradeRequired: item.is_upgrade_feature,
        }));

      setNotificationsData(filteredData);
      return response.data.data;
    } else {
      toast.error('Failed to retrieve User Data:', response.data.data.message);
    }
  } catch (error) {
  } 
};

export const scanningFeaturesDetails = async (setScanningFeatures, setLoadingScanning) => {
  setLoadingScanning(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_scanning_feature_detail');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.data.code === 200) {
      const logs = response.data.data.features.map((feature) => ({
        name: feature.name,
        details: feature.details,
      }));

      setScanningFeatures(logs);
    } else {
      toast.error('Failed to retrieve logs:', response.data.data.message);
    }
  } catch (error) {
  } finally {
    setLoadingScanning(false);
  }
};


export const executeCronIfFailed = async () => {

  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_execute_security_features_cron_if_failed");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
   
  } catch (error) {
    console.error("Error executing cron", error);
  } finally {

  }
};