import axios from 'axios';
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

export const handleConnectGoogle = async () => {    
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_smtp_get_gmail_oauth_url');
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
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
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_disconnect_google');
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
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