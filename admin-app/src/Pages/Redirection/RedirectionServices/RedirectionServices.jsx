import axios from "axios";
import { toast } from 'react-toastify';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const getUserRoles = async ({ setUserRoles, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_user_roles_data');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data.code === 200) {
      setUserRoles(response.data.data.data);
    } else {
      console.error('Failed to User Roles', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching User Roles :', error);
  } finally {
    setLoading(false);
  }
};

export const getAllRedirectRules = async ({setRedirectRules, setFeatureEnable, setParentEnable,page = 1, limit = 10, setPagination}) => {  
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_all_redirect_rules');
    formData.append('data', JSON.stringify({page, limit}));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.data.code === 200) {
      // Process the data and set it to state
      setRedirectRules(response.data.data.data);    
       if (setPagination && response.data.data) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages,total_items: response.data.data.pagination.total });
      }
    } else {
      setRedirectRules([]);
    }
    if (response.data.success === false) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    }

  } catch (error) {
    console.error('Error fetching Redirect Rules:', error);
    setRedirectRules([]);
  }
};

export const getAllPostTypes = async ({ setPostTypes, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_all_post_types');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data.code === 200) {
      setPostTypes(response.data.data.data);
    } else {
      console.error('Failed to load Post Types', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching Post Types :', error);
  } finally {
    setLoading(false);
  }
};

export const createRedirection = async ({ setShowModal, setLoading, payload, fetchRedirectRules }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_create_redirection_rules');
    formData.append('data', JSON.stringify(payload));
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data.code === 200) {
      toast.success("Redirection Rule created successfully");
      setShowModal(false);
      await fetchRedirectRules();
    } else if (response.data.success === false) {
      toast.error(response.data.data.message);
      console.error('Failed to load create rule', response.data.data.message);
    }
  } catch (error) {
    console.error('Network Error');
  } finally {
    setLoading(false);
  }
}

export const updateRedirectRule = async (updateData, setIsUpdating, fetchRedirectRules) => {
  setIsUpdating(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_update_redirect_rule");
    formData.append("nonce", tailwatch_ajax.nonce);

    let payload;
    const isToggleCase = 'enabled' in updateData;
    if (isToggleCase) {
      const { id, enabled } = updateData;
      payload = { id, enabled };
    } else {
      const { id, payload: fullPayload, setShowModal, setLoading } = updateData;
      payload = { id, ...fullPayload };
      setLoading(true);
    }

    formData.append("data", JSON.stringify(payload));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {
      toast.success("Redirect Rule Updated Successfully");
      await fetchRedirectRules();
      if (!isToggleCase) {
        updateData.setShowModal(false);
        updateData.setLoading(false);
      }
    } else {
      console.error("Failed to Update Redirect Rule:", response.data.data.message);
      toast.error("Failed to Update Redirect Rule");
      if (!isToggleCase && 'setLoading' in updateData) {
        updateData.setLoading(false);
      }
    }
  } catch (error) {
    console.error("Error Updating Redirect Rule:", error);
    if ('setLoading' in updateData) {
      updateData.setLoading(false);
    }
  } finally {
    setIsUpdating(false);
  }
};

export const handleDelete = async (deleteData, setIsDeleting, fetchRedirectRules) => {
  const { ids, is_delete } = deleteData;  
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_entries_and_logs");
    formData.append("data", JSON.stringify({ ids, is_delete, key: "default_redirection_rules" }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {

      await fetchRedirectRules();
      toast.success("Redirect Rule deleted successfully");
    } else {
      console.error("Failed to delete logs:", response.data.data.message);
      toast.error("Failed to delete logs");
    }
  } catch (error) {
    console.error("Error deleting logs:", error);
  } finally {
    setIsDeleting(false);
  }
};

export const getRedirectionLogs = async ({ setRedirectLogs, setLoading, page = 1, limit = 10,setFeatureEnable, setParentEnable, setPagination }) => {
  // setLoading(true);  
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_redirection_logs');
    formData.append('data', JSON.stringify({ page, limit }));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.data.code === 200) {
      setRedirectLogs(response.data.data.data);
      if (setPagination && response.data.data) {
        setPagination({ page: response.data.data.page, limit: response.data.data.limit, total_pages: response.data.data.total_pages,total_items: response.data.data.total });
      }
    } else {
      console.error('Failed to fetch Redirect Rules', response.data.data.message);
      setRedirectLogs([]);
    }if (response.data.success === false) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    }
  } catch (error) {
    console.error('Error fetching Redirect Rules:', error);
  } finally {
    // setLoading(false);
  }

}

export const handleDeleteLogs = async (deleteData, setIsDeleting, fetchRedirectionLogs) => {
  const { ids, is_delete } = deleteData;  
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_entries_and_logs");
    formData.append("data", JSON.stringify({ ids, is_delete, key: "default_redirection_logs" }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {
      await fetchRedirectionLogs();
      toast.success("Logs deleted successfully");
    } else {
      console.error("Failed to delete logs:", response.data.data.message);
      toast.error("Failed to delete logs");
    }
  } catch (error) {
    console.error("Error deleting logs:", error);
  } finally {
    setIsDeleting(false);
  }
};

export const fetchLogsErrorData = async ({ setLoading, setLogsData, page = 1, limit = 10,setFeatureEnable,setParentEnable, setPagination }) => {

  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_logs_feature');
    formData.append('data', JSON.stringify({ key: "default_feature_logs", option: "default_monitoring_logs", type: "404",page,limit }));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    if (response.data.success) {
      const parsedData = response.data.data.data.map(item => {
        try {
          return { ...item, parsedValue: JSON.parse(item.value) };
        } catch (e) {
          console.error('Error parsing value:', item.value, e);
          return item;
        }
      });      
      setLogsData(parsedData);
      if (setPagination && response.data.data) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages,total_items: response.data.data.pagination.total });
      }
    } else {
      setLogsData([]);
    }
    if (response.data.success === false) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    }
  } catch (error) {
    console.error("Error fetching data:", error);
    setLogsData([]);
  }
  setLoading(false);
};

export const handleDeleteErrorLogs = async (deleteData, setIsDeleting, fetchLogsData) => {
  const { ids, is_delete } = deleteData;  
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_logs");
    formData.append("data", JSON.stringify({ ids, key: "default_feature_logs", type: "404_logs", option: "default_monitoring_logs", is_delete }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {
      await fetchLogsData();
      toast.success("Logs deleted successfully");
    } else {
      console.error("Failed to delete logs:", response.data.data.message);
      toast.error("Failed to delete logs");
    }
  } catch (error) {
    console.error("Error deleting logs:", error);
  } finally {
    setIsDeleting(false);
  }
};