import axios from "axios";
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

export const googleAuthData = async ({ setAuthData, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_2fa_qr_code');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data.code === 200) {
      setAuthData(response.data.data);
    } else {
      console.error('Failed to retrieve Qr Code:', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching Qr Code :', error);
  } finally {
    setLoading(false);
  }
};

export const getTwoFaStatus = async ({ set2FaStatus, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_2fa_status');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data.code === 200) {
      set2FaStatus(response.data.data.data);
      return response.data.data.data; 
    } else {
      console.error('Failed to retrieve Qr Code:', response.data.data.message);
      return null; 
    }
  } catch (error) {
    console.error('Error fetching Qr Code :', error);
    return null;
  } finally {
    setLoading(false);
  }
};

export const verifyTwoFaStatus = async ({ code, set2FaStatus, setAuthData, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_verify_2fa_code');
    formData.append('nonce', tailwatch_ajax.nonce);
    if (!code) {
      throw new Error('The "code" parameter is required and cannot be null or undefined.');
    }
    formData.append('data', JSON.stringify({ "twofa_code": code }));
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.data.code === 200) {
      const codes = response.data.data.backup_codes;
      if (setAuthData && Array.isArray(codes)) {
        setAuthData((prev) => ({ ...(prev || {}), backup_codes: codes }));
      }
      await getTwoFaStatus({ set2FaStatus, setLoading });
    } else {
      console.error('Failed to verify 2FA code:', response.data.data.message);
      toast.error(response.data.data.message || 'Verification failed');
    }
  } catch (error) {
    console.error('Error verifying 2FA code:', error);
  } finally {
    setLoading(false);
  }
};

export const disconnectTwoFa = async ({ set2FaStatus, setLoading }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_disconnect_2fa');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.data.code === 200) {
      await getTwoFaStatus({ set2FaStatus, setLoading });
      toast.success('2FA disconnected successfully');
    } else {
      console.error('Failed to disconnect 2FA:', response.data.data.message);
      toast.error(response.data.data.message || 'Disconnection failed');
    }
  } catch (error) {
    console.error('Error disconnecting 2FA:', error);
  } finally {
    setLoading(false);
  }
};

export const verifyMethod = async ({ setLoading, setIsLicenseConnect, setIsLicenseReq }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_get_two_fa_status');
    formData.append('nonce', tailwatch_ajax.nonce);
    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    if (response.data.data?.code === 200) {
      return response.data;
    }
    if (response.data.data?.code === 402) {
      if (setIsLicenseReq) setIsLicenseReq(response.data.data);
      return null;
    }
    console.error('Failed to Two Fa Status:', response.data.data?.message);
    return null;
  } catch (error) {
    const code = error?.response?.data?.data?.code;
    if (code === 403) {
      if (setIsLicenseConnect) setIsLicenseConnect(true);
    } else if (code === 402) {
      if (setIsLicenseReq) setIsLicenseReq(error.response.data.data);
    } else {
      console.error('Error fetching Two Fa Stautus :', error);
    }
    return null;
  } finally {
    setLoading(false);
  }
};

export const verifyEmailStatus = async ({ setIsVerifyEmail, setEmailId, setLoading, setUserName }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_two_fa_status_is');
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    if (response.data.data.code === 200) {
      setIsVerifyEmail(response.data.data.is_enabled);
      setEmailId(response.data.data.id);
      setUserName(response.data.data.name);
    } else {
      console.error('Failed to retrieve Email status :', response.data.data.message);
    }
  } catch (error) {
    console.error('Error fetching Email Status :', error);
  } finally {
    setLoading(false);
  }
};

export const updateEmailStatus = async ({ id, state, setIsVerifyEmail, setEmailId, setLoading, setUserName }) => {
  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_update_two_fa_status');
    formData.append('nonce', tailwatch_ajax.nonce);
    formData.append('data', JSON.stringify({ id: id, two_step_verification: state }));

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });

    if (response.data.success) {
      // re-fetch updated status
      await verifyEmailStatus({ setIsVerifyEmail, setEmailId, setLoading, setUserName });
      return true;
    } else {
      throw new Error(response.data.message || 'Failed to update 2FA.');
    }
  } catch (error) {
    console.error('Error updating 2FA:', error);
    return false;
  } finally {
    setLoading(false);
  }
};
