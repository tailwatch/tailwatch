import axios from 'axios';
import { toast } from 'react-toastify';
/* global wptw_ajax */

export const handleConnectGoogle = async () => {    
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_smtp_get_gmail_oauth_url');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });         

    if (response.data.data.code === 200 && response.data.data.url) {      
      return response.data.data.url;
    } else {
      toast.error('Failed to connect to Google');
      return null;
    }
  } catch (error) {
    return null;
  }
};

export const handleDisconnectGoogle = async () => {    
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_disconnect_google');
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    if (response.data.data.code === 200) {      
      return true; // Return true if disconnect was successful
    } else {
      toast.error('Failed to disconnect from Google');
      return false;
    }
  } catch (error) {
    return false;
  }
};