import axios from 'axios';
import { getLocalStorage } from '../../../Components/Utils/HelperFunctions/LocalStorageHelper';
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

export const fetchData = async (key, option, setLoading, setTabData, paginationParams = null,setFeatureEnable,setParentEnable, filters = null) => {
  setLoading(true);
  try {
    const requestData = { key, option };

    // Add pagination parameters if provided
    if (paginationParams) {
      requestData.page = paginationParams.page;
      requestData.limit = paginationParams.limit;
    }

    // Dynamic filters: multi-value facet arrays (e.g. username, type, ip_address,
    // action, facet_1, facet_2) + optional date_from / date_to.
    if (filters && typeof filters === 'object') {
      Object.keys(filters).forEach((f) => {
        const v = filters[f];
        if (Array.isArray(v) ? v.length > 0 : (v !== '' && v != null)) {
          requestData[f] = v;
        }
      });
    }

    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_logs_feature');
    formData.append('data', JSON.stringify(requestData));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }      
    });    
    if (response.data.success) {
      const parsedData = response.data.data.data.map(item => {
        try {
          return { ...item, parsedValue: JSON.parse(item.value) };
        } catch (e) {
          return item;
        }
      });
      setTabData(parsedData);      
      if (paginationParams?.setPagination && response.data.data.pagination) {
        paginationParams.setPagination({ 
          page: response.data.data.pagination.page, 
          limit: response.data.data.pagination.limit, 
          total_pages: response.data.data.pagination.total_pages,
          total_items: response.data.data.pagination.total 
        });
      }
    }else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
    } else {
      setTabData([]);
    }
  } catch (error) {
    setTabData([]);
  }
  setLoading(false);
};

// Fetch the dynamic filter options for a log view: { column: { label, values[] } }.
// Values come from SELECT DISTINCT, so the UI only shows filters present in the data.
export const fetchFilterOptions = async (key, option) => {
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_logs_filter_options');
    formData.append('data', JSON.stringify({ key, option }));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    if (response.data?.success && response.data?.data?.data) {
      return response.data.data.data;
    }
    return {};
  } catch (error) {
    return {};
  }
};

export const handleDelete = async ({deleteData,setIsDeleting,fetchTabData}) => {
  const { ids, key, option, is_delete } = deleteData;
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_logs");
    formData.append("data", JSON.stringify({ ids, key, option, is_delete }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {
      await fetchTabData();
      toast.success("Logs deleted successfully");
    } else {
      toast.error("Failed to delete logs");
    }
  } catch (error) {
  } finally {
    setIsDeleting(false);
  }
};

// Send a single SMTP configuration test email through the site's configured
// provider. Returns the backend response payload ({ code, message }) or null.
export const sendTestEmail = async (testEmail) => {
  const formData = new FormData();
  formData.append('action', 'tailwatch_global_ajax_handler');
  formData.append('action_type', 'tailwatch_smtp_test_email');
  formData.append('data', JSON.stringify({ test_email: testEmail }));
  formData.append('nonce', tailwatch_ajax.nonce);

  const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
    headers: { 'Content-Type': 'multipart/form-data' }
  });
  return response.data?.data ?? null;
};

export const checkLocalStorage = async (setShowTabs) => {
  try {
    const localStorageData = getLocalStorage('features_data', 'data');
    if (localStorageData && Array.isArray(localStorageData)) {
      const logs = localStorageData.find(item => item.option === "default_log_activity");
      const emailLogs = localStorageData.find(item => item.option === "default_email_configure");
      const errorLogs = localStorageData.find(item => item.option === "default_monitoring_logs");
      
      setShowTabs({
        showLogs: logs && logs.is_active === "0",
        featureId: logs ? logs.id : null,
        isActive: logs ? logs.is_active : null,

        showEmailLogs: emailLogs && emailLogs.is_active === "0",
        emailLogsFeatureId: emailLogs ? emailLogs.id : null,
        emailLogsIsActive: emailLogs ? emailLogs.is_active : null,

        showErorLogs: errorLogs && errorLogs.is_active === "0",
        errorLogsFeatureId: errorLogs ? errorLogs.id : null,
        errorLogsIsActive: errorLogs ? errorLogs.is_active : null,
      });
    }
  } catch (error) {
  }
};
