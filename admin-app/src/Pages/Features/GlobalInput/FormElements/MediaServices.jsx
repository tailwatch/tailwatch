import axios from 'axios';
/* global wptw_ajax */

export const getWpMedia = async ({ page = 1, limit = 20, query = {} } = {}) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_wp_media');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({ page, limit, query }));

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    const payload = response?.data?.data;
    if (payload?.code === 200) {
      return {
        success: true,
        items: payload.data || [],
        pagination: payload.pagination || {},
      };
    }
    return { success: false, items: [], pagination: {}, error: payload?.message || 'Failed to load media' };
  } catch (error) {
    return { success: false, items: [], pagination: {}, error: error?.message || 'Network error' };
  }
};

export const uploadWpMedia = async ({ file, post_id, post_data, onProgress } = {}) => {
  try {
    if (!file) return { success: false, error: 'No file provided' };

    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_upload_wp_media');
    formData.append('nonce', wptw_ajax.nonce);

    const dataPayload = {};
    if (post_id != null) dataPayload.post_id = post_id;
    if (post_data) dataPayload.post_data = post_data;
    if (Object.keys(dataPayload).length > 0) {
      formData.append('data', JSON.stringify(dataPayload));
    }

    // Field name MUST be `async_upload` per the backend contract.
    formData.append('async_upload', file);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      onUploadProgress: (evt) => {
        if (onProgress && evt.total) {
          onProgress(Math.round((evt.loaded * 100) / evt.total));
        }
      },
    });

    const payload = response?.data?.data;
    if (payload?.code === 200) {
      return { success: true, attachment: payload.data };
    }
    return {
      success: false,
      error: payload?.message || 'Upload failed',
      code: payload?.code,
    };
  } catch (error) {
    const payload = error?.response?.data?.data;
    return {
      success: false,
      error: payload?.message || error?.message || 'Upload failed',
      code: payload?.code,
    };
  }
};

export const deleteWpMedia = async ({ attachment_id, force_delete = false } = {}) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_delete_wp_media');
    formData.append('nonce', wptw_ajax.nonce);
    formData.append('data', JSON.stringify({
      attachment_id,
      // Must be literal boolean per the backend (MediaController.php:553)
      force_delete: force_delete === true,
    }));

    const response = await axios.post(wptw_ajax.ajax_url, formData);
    const payload = response?.data?.data;
    if (payload?.code === 200) {
      return { success: true, data: payload.data, message: payload.message };
    }
    return { success: false, error: payload?.message || 'Delete failed' };
  } catch (error) {
    const payload = error?.response?.data?.data;
    return { success: false, error: payload?.message || error?.message || 'Delete failed' };
  }
};
