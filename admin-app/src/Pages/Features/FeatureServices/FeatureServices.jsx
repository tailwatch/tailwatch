import axios from 'axios';
import { toast } from 'react-toastify' ;
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const updatePushNotification = async ({ feature_id, id, push_notification,GetData,setFeaturesData }) => {
    try {
      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_update_push_notification_value");
      formData.append("data",JSON.stringify({ feature_id, id, push_notification }));
      formData.append("nonce", tailwatch_ajax.nonce);

      const response = await axios.post(tailwatch_ajax.ajax_url, formData);

      if (response.data.data.code === 200) {
        if (push_notification) {
          toast.success("Push Notification is Enabled");
        } else {
          toast.success("Push Notification is Disabled");
        }
      } else {
        throw new Error("Failed to update notification status");
      }
    } catch (error) {
      // Error modal will be shown by axios interceptor
      throw error;
    } finally {
      try {
        const { features } = await GetData();
        setFeaturesData(features);
      } catch (error) {
        // Ignore errors during refresh
      }
    }
  };

  export const restoreFeatureOptions = async ({ featureOption,fetchFeaturesData,setLoading }) => {
    try {
      setLoading(true);
      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_reset_feature_by_option");
      formData.append("data",JSON.stringify({ feature_option: featureOption, remain_active:true }));
      formData.append("nonce", tailwatch_ajax.nonce);

      const response = await axios.post(tailwatch_ajax.ajax_url, formData);
       if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
            const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
            alertService.warning(message, 'Process in Progress');
            return;
        }
      if (response.data.data.code === 200) {
        await fetchFeaturesData();
        toast.success("Feature options restored successfully");
      } else {
        throw new Error("Failed to restore feature options");
      }
    } catch (error) {
      // Error modal will be shown by axios interceptor
      throw error;
    } finally {
      setLoading(false);
    }
  };
