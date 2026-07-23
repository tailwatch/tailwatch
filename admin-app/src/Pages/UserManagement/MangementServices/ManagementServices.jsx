import axios from "axios";
import { toast } from 'react-toastify';
/* global wptw_ajax */

export const blockUser = async ({ payload, setLoading, onClose, getData }) => {
  setLoading?.(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_block_user');
    formData.append('data', JSON.stringify(payload));
    formData.append('nonce', wptw_ajax.nonce);
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response?.data?.data?.code === 200) {
      toast.success(response.data.data.message || 'User blocked successfully');
      onClose?.();
      // Wait for the user list (wptw_get_user_status) to refetch before resolving,
      // so the caller's loading state stays active until the row reflects the new block.
      await getData?.();
      return true;
    }
    toast.error(response?.data?.data?.message || 'Failed to block user');
    return false;
  } catch (error) {
    toast.error(error?.response?.data?.data?.message || 'Network error');
    return false;
  } finally {
    setLoading?.(false);
  }
};

export const unblockUser = async ({ payload, setLoading, getData }) => {
  setLoading?.(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_unblock_user');
    formData.append('data', JSON.stringify(payload));
    formData.append('nonce', wptw_ajax.nonce);
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response?.data?.data?.code === 200) {
      toast.success(response.data.data.message || 'User unblocked successfully');
      // Wait for wptw_get_user_status to refetch so the badge + menu reflect
      // the unblocked state by the time this promise resolves.
      await getData?.();
      return true;
    }
    toast.error(response?.data?.data?.message || 'Failed to unblock user');
    return false;
  } catch (error) {
    toast.error(error?.response?.data?.data?.message || 'Network error');
    return false;
  } finally {
    setLoading?.(false);
  }
};

export const fetchUserData = async ({ setUserData, setLoading,setPagination, page = 1, limit = 10  }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_user_status');
    formData.append('data', JSON.stringify({page,limit }));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });    
    if (response.data.data.code === 200 && response.data.data.data) {
      setUserData(response.data.data.data);
      if (setPagination && response.data.data.pagination) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages,total_items: response.data.data.pagination.total });
      }
    } else {
      console.error('Failed to retrieve User Data:', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching User Data:', error);
  } finally {
    setLoading(false);
  }
};

export const fetchTempLoginData = async ({  setTempLoginData, setLoading,setFeatureEnable, setParentEnable,setIsLicenseConnect,setIsLicenseReq,  setPagination, page = 1, limit = 10  }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_all_temporary_logins');
    formData.append('data', JSON.stringify({page,limit }));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });    
    
    if (response.data.success === true) {
      const processedData = Object.keys(response.data.data.data)
        .filter(key => typeof response.data.data.data[key] === 'object' && response.data.data.data[key].user_id)
        .map(key => ({
          user_id: response.data.data.data[key].user_id,
          username: response.data.data.data[key].username,
          email: response.data.data.data[key].email,
          role: response.data.data.data[key].role || 'N/A',
          last_login: response.data.data.data[key].last_login || '',
          login_count: response.data.data.data[key].login_count || 0,
          created_time: response.data.data.data[key].created_time || '',
          expiry_time: response.data.data.data[key].expiry_time || '',
          first_name: response.data.data.data[key].first_name || '',
          last_name: response.data.data.data[key].last_name || '',
          is_expired: response.data.data.data[key].is_expired || false,
          login_url: response.data.data.data[key].login_url || '',
        }));

      setTempLoginData(processedData);
      if (setPagination && response.data.data.pagination) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages,total_items: response.data.data.pagination.total });
      }
    }else if(response.data.data.code === 402){      
      setIsLicenseReq(response.data.data);
    } else {
      console.error('Failed to retrieve User Data:', response.data.data.message);
      setTempLoginData([]);
    }
    if (response.data.data.code === 400) {
      const { feature_enable, parent_enable } = response.data.data;
      setFeatureEnable(feature_enable);
      setParentEnable(parent_enable);
    }  
  } catch (error) {
    const errorCode = error?.response?.data?.data?.code;
    if(errorCode === 403){
      setIsLicenseConnect(true);
    }
    if(errorCode === 402){
      setIsLicenseReq(error.response.data.data);
    }
    setTempLoginData([]);
  } finally {
    setLoading(false);
  }
};

export const tempUserRoles = async ({ setTempRoles, setTempLang, setTempSlug, setLoading }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_roles_and_languages');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.status === 200) {
      // Set the state with the correct data from the response
      setTempRoles(response.data.data.data.roles);
      setTempLang(response.data.data.data.languages);
      setTempSlug(response.data.data.data.redirect_slugs);
    } else {
      console.error('Failed to retrieve User Data:', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching User Data:', error);
  } finally {
    setLoading(false);
  }
};

export const fetchRoles = async ({ setRoles }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_all_roles');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    
    if (response.data.success && response.data.data.data) {
      setRoles(response.data.data.data);
    } else {
      console.error('Failed to retrieve roles:', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching roles:', error);
  }
};

export const userRoleChangeSubmit = async (userId, newRole, setUserData, setShowRoleChangePopup) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_change_user_role');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ 'user_id': userId, 'new_role': newRole }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    });
    if (response.data.success) {
      setUserData((prevData) =>
        prevData.map((u) =>
          u.id === userId ? { ...u, role: newRole } : u
        )
      );
      toast.success("User role updated successfully!");
      return true;
    } else {
      toast.error(response.data?.data?.message || "Failed to change user role.");      
      return false;
    }
  } catch (error) {
    console.error('Error updating user role:', error);
    return false;
  } finally {
    setShowRoleChangePopup(false);
  }
};

export const updateTwoFa = async ({ userId, username, state, setUserData, setLoading }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_2fa_authentication_status');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ user_id: userId, is_enabled: state }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.success) {
      await fetchUserData({ setUserData, setLoading });
      return {
        success: true,
        message: response?.data?.data?.message || '2FA updated successfully',
      };
    } else {
      return {
        success: false,
        message: response?.data?.data?.message || 'Failed to update 2FA.',
      };
    }
  } catch (error) {
    console.error('Error updating 2FA:', error);
    return false;
  }
};

export const createTempUser = async (userData) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_create_new_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(userData));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const updateTempUser = async (userData) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_temporary_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(userData));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const manageLoginStatus = async ({ user_id, enable, expiry_time, reset_login_count }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_manage_login_status');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ user_id, enable, expiry_time, reset_login_count })); // ✅ added

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const deleteTempUsers = async ({ setIsDeleting, payload, fetchUserTempData }) => {
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_delete_temporary_login');
    formData.append('data', JSON.stringify( payload ));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    if (response.data.success === true){
      fetchUserTempData();
      toast.success("Deleted Succesfully")
    }else{
      toast.error(response.data.data.message);      
    }
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  } finally {
    setIsDeleting(false);
  }
};

export const getTemporaryUsers = async ({ user_id }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_temporary_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ user_id }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.status === 200) {
      return response.data.data.data;
    } else {
      console.error('Failed to retrieve User Data:', response.data.data.message);
      return null;
    }
  } catch (error) {
    console.error('Error fetching User Data:', error);
    return null;
  } finally {
  }
};

export const updateRestrictLogin = async (userData) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_user_expiry_status');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(userData));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const getUserContentSummary = async (user_id) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_user_content_summary');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ user_id }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const deleteUser = async (payload) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_delete_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(payload));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const bulkDeleteUsers = async (payload) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_bulk_delete_users');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(payload));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const createUser = async (userData) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_create_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(userData));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const updateUser = async (userData) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_update_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify(userData));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};

export const generateLoginLink = async ({ user_id }) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_login_as_another_user');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ user_id }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    

    return response.data;
  } catch (error) {
    console.error('Network Error', error);
    throw error;
  }
};
