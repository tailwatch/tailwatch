import axios from "axios";
import { toast } from 'react-toastify';
import { CronHealer } from "../../../Components/CroneHealer/CronHealer";
import { startSecurityFeature } from "../../Home/HomeServices/HomeServices";
import { alertService } from "../../../Components/AlertService/AlertService";

/* global wptw_ajax */
let cronScheduled = false;
let isCronExecuting = false;
let cronTimeoutId = null;

const executeCronIfFailed = async () => {
  if (isCronExecuting) {
    return;
  }
  try {
    isCronExecuting = true;

    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_run_feature_implement_cron_if_failed");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.success) {
    } else {
    }
  } catch (error) {
  } finally {
    cronScheduled = false;
    isCronExecuting = false;
  }
};

const checkCronExecution = async (cron_running) => {
  if (cron_running === false && !cronScheduled && !isCronExecuting) {
    // Schedule cron execution if not running and not already scheduled
    cronScheduled = true;

    cronTimeoutId = setTimeout(async () => {
      try {
        await executeCronIfFailed();
        await CronHealer();

      } catch (error) {
      }
    }, 15000); // 15 seconds

  } else if (cron_running === true && cronScheduled) {
    // Cancel scheduled cron if it's running
    clearTimeout(cronTimeoutId);
    cronScheduled = false;
    cronTimeoutId = null;
  }
};

export const handleFeatureCompletion = async ({ setProgressValue, navigate }) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_recommended_features_process");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const { progress, is_completed, cron_running, function_completed, completion_timestamp, current_timestamp } = response.data.data;
    // Check if cronHealer should be executed
    if (function_completed && completion_timestamp && current_timestamp) {
      const timeDifference = current_timestamp - completion_timestamp;
      

      if (timeDifference >= 12) {
        
        await CronHealer();
      } else {
        
      }
    }

    // Check and handle cron execution based on cron_running status
    checkCronExecution(cron_running);


    if (typeof progress === "number" && typeof is_completed === "boolean") {
      if (is_completed) {
        setProgressValue(100);
        await startSecurityFeature()
        setTimeout(() => {
          navigate("/dashboard");
        }, 1500);
      } else {
        setProgressValue(progress);
        setTimeout(() => handleFeatureCompletion({ setProgressValue, navigate }), 4000);
      }
    } else {
      throw new Error("Invalid response structure.");
    }
  } catch (e) {
  }
};

export const verifyVisitStatus = async (setVisitStep, navigate) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_verify_visit_progress");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    const { visit_step, is_completed } = response.data.data;
    setVisitStep(visit_step);
    if (is_completed === true) {
      navigate('/dashboard');
    }

  } catch (e) {
  }
};
export const checkPhpVersion = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_check_php_version");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response?.data?.success) {
      return {
        required_php_version: response.data.data.required_php_version,
        current_php_version: response.data.data.current_php_version,
        message: response.data.data.message,
      };
    } else {
      throw new Error(response?.data?.data?.message || "PHP version check failed.");
    }
  } catch (e) {
    return { error: e.message };
  }
};

export const checkWpVersion = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_check_wordpress_version");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response?.data?.success) {
      return {
        required_wp_version: response.data.data.required_wp_version,
        current_wp_version: response.data.data.current_wp_version,
        message: response.data.data.message,
      };
    }
    else {
      throw new Error(response?.data?.data?.message || "Wordpress version check failed.");
    }
  } catch (e) {
    return { error: e.message };
  }
};
export const checkCronStatus = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_check_cron_status");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response?.data?.success) {
      return {
        message: response.data.data.message,
      };
    }
    else {
      throw new Error(response?.data?.data?.message || "Cron check failed.");
    }
  } catch (e) {
    return { error: e.message };
  }
};

export const handleHttpRequest = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_check_http_request");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response?.data?.success) {
      return {
        message: response.data.data.message,
      };
    }
    else {
      throw new Error(response?.data?.data?.message || "Http Request Not Allowed.");
    }
  } catch (e) {
    return { error: e.message };
  }
};
export const check_wptw_table = async () => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "check_wptw_table");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response?.data?.success) {
      return {
        message: response.data.data.message,
      };
    }
    else {
      throw new Error(response?.data?.data?.message || "Database does not exisit.");
    }
  } catch (e) {
    return { error: e.message };
  }
};

export const getVisitFeatures = async (setFeatures, setIsLoading, setFeatureData) => {
  setIsLoading(true);
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_feature");
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    if (response?.data?.success) {
      const fetchedFeatures = response?.data?.data?.data;
      setFeatures(fetchedFeatures);
      setFeatureData(response?.data?.data);
    }
  } catch (e) {
  } finally {
    setIsLoading(false);
  }

};

export const updateVisitFeatures = async (setFeatures, payload) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_update_feature_status");
    formData.append("nonce", wptw_ajax.nonce);
    // Send boolean true/false to backend
    formData.append("data", JSON.stringify(payload));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      alertService.warning(message, 'Process in Progress');
      return;
    }

    if (response?.data?.success) {
      const updatedFeature = response.data.data;
      setFeatures((prevFeatures) =>
        prevFeatures.map((feature) =>
          feature.featureKey === updatedFeature.featureKey
            ? { ...feature, ...updatedFeature }
            : feature
        )
      );
    }
  } catch (e) {
  } finally {
  }
};

export const handleSubmitButton = async ({ setLoading, setVisitStep, isProcessingRef, handleFeatureProcess, setting }) => {
  try {
    setLoading(true);
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_start_features_implementation");
    formData.append("nonce", wptw_ajax.nonce);
    formData.append("data", JSON.stringify({ "setup_configuration": setting }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    if (response?.data?.success) {
      setVisitStep('features_implemented');
      if (!isProcessingRef.current) {
        isProcessingRef.current = true;
        handleFeatureProcess();
      }
    } else {

    }
  } catch (e) {
  }
  finally {
    setLoading(false);
  }
}

//     const response = await axios.post(wptw_ajax.ajax_url, formData, {
//       headers: { "Content-Type": "multipart/form-data" },
//     });

//     const { progress, is_completed, cron_running } = response.data.data;

//     // Check and handle cron execution based on cron_running status
//     checkCronExecution(cron_running);
