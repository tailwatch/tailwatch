import axios from 'axios';
import { toast } from 'react-toastify';
/* global wptw_ajax */

export const getAlerts = async (page = 1, limit = 10, status = 'active') => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_errors");
    formData.append("data", JSON.stringify({ status, page, limit,priority:'high' }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    if (response.data.data.code === 200) {
      return response; 
    } else {
      toast.error('Failed to load alerts');
      throw new Error('Failed to load alerts');
    }
  } catch (error) {
    throw error;
  }
};