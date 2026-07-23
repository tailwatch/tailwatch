import axios from "axios";
import { updateLocalStorage, getLocalStorage } from '../Utils/HelperFunctions/LocalStorageHelper';
import { showLocalStorageError } from '../ErrorModal/localStorageErrorHandler';
import { alertService } from '../AlertService/AlertService';
import toast from "react-hot-toast";

/* global wptw_ajax */

const GetData = async () => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_feature');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    });

    const featuresData = response.data.data.data;
    const proPluginActive = response?.data?.data?.license_status?.pro_plugin_active ?? false;

    const convertedFeaturesData = featuresData.map(feature => ({
      ...feature,
      is_active: String(feature.is_active)
    }));

    updateLocalStorage('features_data', { data: convertedFeaturesData, pro_plugin_active: proPluginActive });

    return { features: convertedFeaturesData, featureslicenseStatus: response.data.data };
  } catch (error) {
    throw error;
  }
};

const InsertData = async (featureId, newActiveState) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_feature_status');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ 'featureId': featureId, 'is_active': newActiveState }));

    const response1 = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    });

    if (response1.data?.data?.code === 409 && response1.data?.data?.data?.blocked_by_process === true) {
      const message = response1.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      alertService.warning(message, 'Process in Progress');
      return;
    }

    if (response1.data.data === 'This feature is locked and cannot be updated.') {
      return response1.data.data;
    }

    // Only persist the new is_active to localStorage and run the push-notification
    // follow-up when the backend confirms success. For `success: false` responses,
    // return the payload so the caller can show response.data.message and roll back.
    if (response1.data?.success !== true) {
      return response1.data;
    }

    let featuresData = getLocalStorage('features_data', 'data');
    if (featuresData) {
      const index = featuresData.findIndex(feature => feature.id === featureId);
      if (index !== -1) {
        featuresData[index].is_active = newActiveState ? "1" : "0";
        updateLocalStorage('features_data', { data: featuresData });
      }
    }

    let notificationSettings = null;
    let pushOptions = null;

    try {
      const notificationItem = localStorage.getItem('settings_push_notification');
      if (notificationItem) {
        notificationSettings = JSON.parse(notificationItem);
      }
    } catch (error) {
      console.error('Error parsing settings_push_notification from localStorage:', error);
      localStorage.removeItem('settings_push_notification');
      showLocalStorageError(error);
    }

    try {
      const pushOptionsItem = localStorage.getItem('settings_push_options');
      if (pushOptionsItem) {
        pushOptions = JSON.parse(pushOptionsItem);
      }
    } catch (error) {
      console.error('Error parsing settings_push_options from localStorage:', error);
      localStorage.removeItem('settings_push_options');
      showLocalStorageError(error);
    }

    const isMobileDashboardConnected = notificationSettings && notificationSettings.mobiledashboard;
    const isAnyOptionSelected = pushOptions && Object.values(pushOptions).some(option => option.values.option.selected);

    if (isMobileDashboardConnected && isAnyOptionSelected) {
      const formData2 = new FormData();
      formData2.append('action', 'wptw_global_ajax_handler');
      formData2.append('action_type', 'wptw_push_notification_activity');
      formData2.append('nonce', wptw_ajax.nonce);
      formData2.append('data', JSON.stringify({ 'featureId': featureId, 'is_active': newActiveState }));

      await axios.post(wptw_ajax.ajax_url, formData2, {
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      });
    }

    return response1.data;
  } catch (error) {
    throw error;
  }
};

const updateData = async (formDataObj) => {
  try {
    const url = new URL(wptw_ajax.ajax_url);

    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_inner_feature');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(formDataObj));

    const response = await axios.post(url.toString(), formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });    
    if(response.data?.success === false) {
      toast.error(response.data?.data?.message || 'An error occurred while updating the feature.', 'Error');
      return
    }

    if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
      const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
      alertService.warning(message, 'Process in Progress');
      return { success: false, blocked_by_process: true };
    }

    return response.data;
  } catch (error) {
    throw error;
  }
};

export { GetData, InsertData, updateData };
