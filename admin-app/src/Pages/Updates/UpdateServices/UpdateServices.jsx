import { toast } from 'react-toastify';
import axios from 'axios';
import { set } from 'react-hook-form';
/* global wptw_ajax */

export const updatePlugin = async (fetchPluginDetail, setUpdateStatus, setResponseMessage, handleStreamResponse) => {
  const data = new URLSearchParams();
  data.append('action', 'wptw_global_ajax_handler');
  data.append('action_type', 'wptw_update_plugin');
  data.append('nonce', wptw_ajax.nonce);
  data.append('data', JSON.stringify({ plugin: fetchPluginDetail, triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: data,
    });

    const streamedResponse = await handleStreamResponse(response);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false) {
        setResponseMessage('Update failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      setUpdateStatus('completed');
      setResponseMessage('Update completed successfully');
      toast.success("Update Completed Successfully");
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {
  }
};

export const updateTheme = async (slug, setUpdateStatus, setResponseMessage, handleStreamResponse) => {
  const themeSlug = slug.split('&')[0];

  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_update_theme');
  formData.append('nonce', wptw_ajax.nonce);
  formData.append('data', JSON.stringify({ theme: themeSlug, triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      body: formData,
    });

    const streamedResponse = await handleStreamResponse(response);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false) {
        setResponseMessage('Update failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      setUpdateStatus('completed');
      setResponseMessage('Update completed successfully');
      toast.success("Update completed successfully");
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {
  }
};

export const updateCoreWordpress = async (slug, setUpdateStatus, setResponseMessage, handleStreamResponse) => {

  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_update_core');
  formData.append('nonce', wptw_ajax.nonce);
  formData.append('data', JSON.stringify({ triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      body: formData,
    });

    const streamedResponse = await handleStreamResponse(response);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false) {
        setResponseMessage('Update failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      setUpdateStatus('completed');
      setResponseMessage('Update completed successfully');
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {
  }
};

  export const fetchPluginThemesDetails = async ({ pluginSlug, setProgress, setPluginDetails, setError, setLoading, type, setDetailLoading, action_type }) => {
    try {
      setLoading(true);
      setDetailLoading(true);
      const formData = new FormData();
      formData.append('action', 'wptw_global_ajax_handler');
      formData.append('action_type', action_type);    
      const slugParts = pluginSlug.split('&');
      const actualSlug = slugParts[0];
      const slugPlugin = slugParts[1];

      const dataPayload = type === 'plugin'
        ? { plugin: slugPlugin }
        : { theme: actualSlug };

      formData.append('data', JSON.stringify(dataPayload));
      formData.append('nonce', wptw_ajax.nonce);

      const response = await axios.post(wptw_ajax.ajax_url, formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        }      
      });    

     if (response.data?.success === false && response.data?.data?.code === 503) {


  const details = response.data?.data?.theme || response.data?.data?.plugin;
  if (details?.is_custom) {
    setPluginDetails(details); 
  } else {
    setError(response.data?.data?.message || 'Service unavailable'); 
  }
  return;
}
      if (response.data.success && response.data.data) {
        setPluginDetails(response.data.data.theme || response.data.data.plugin);
      } else {
        setError('Failed to retrieve plugin details');
      }
    } catch (error) {
  setError('Error fetching plugin/theme details');

    } finally {
      setLoading(false);
      setDetailLoading(false);
    }
  };

export const fetchUpdates = async (action_type, page = 1, limit = 10) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", action_type);
    formData.append("data", JSON.stringify({ page, limit }));
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    return response.data.data;
  } catch (error) {
  }
};

export const getAllVersion = async (updateInfo, setAllVersions, setError, setLoading) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');

    const action_type = updateInfo.type === 'plugin'
      ? 'wptw_get_plugin_versions'
      : updateInfo.type === 'theme'
        ? 'wptw_get_theme_versions'
        : 'wptw_get_core_versions'; 

    formData.append('action_type', action_type);

    let dataPayload;
    if (updateInfo.type === 'plugin') {
      const slugParts = updateInfo.slug ? updateInfo.slug.split('&') : [];
      const actualSlug = slugParts[0] || '';
      dataPayload = { plugin: actualSlug };
    } else if (updateInfo.type === 'theme') {
      const slugParts = updateInfo.slug ? updateInfo.slug.split('&') : [];
      const actualSlug = slugParts[0] || '';
      dataPayload = { theme: actualSlug };
    } else {
    }

    formData.append('data', JSON.stringify(dataPayload));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    if (response.data.success && response.data.data) {
      setAllVersions(response.data.data.versions);
    } else {
      setError(response.data?.data?.message || 'Failed to retrieve versions');
    }
  } catch (error) {
  }
};

export const rollbackPlugin = async (fetchPluginDetail, version, setResponseMessage, setrollbackStatus, setProgress) => {
  const data = new URLSearchParams();
  data.append('action', 'wptw_global_ajax_handler');
  data.append('action_type', 'wptw_plugin_rollback');
  data.append('nonce', wptw_ajax.nonce);
  data.append('data', JSON.stringify({ plugin: fetchPluginDetail, version, triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: data,
    });

    const streamedResponse = await handleStreamResponse(response, setProgress, setResponseMessage);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false || finalResponse.data.code === 500) {        
        toast.error('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        setResponseMessage('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        setrollbackStatus('rollback failed');

        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      setrollbackStatus('rollback completed');
      toast.success(finalResponse.data.message || 'Rollback completed successfully');
      setResponseMessage('Rollback completed successfully');
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {
  }
};

export const rollbackTheme = async (slug, version, setResponseMessage, setProgress, setrollbackStatus) => {
  const themeSlug = slug.split('&')[0];

  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_theme_rollback');
  formData.append('nonce', wptw_ajax.nonce);
  formData.append('data', JSON.stringify({ theme: themeSlug, version, triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      body: formData,
    });

    const streamedResponse = await handleStreamResponse(response, setProgress, setResponseMessage);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false ||  finalResponse.data.code === 500) {
        setResponseMessage('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
                toast.error('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        setrollbackStatus('rollback failed');
        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      toast.success(finalResponse.data.message || 'Rollback completed successfully');
      setrollbackStatus('rollback completed');
      setResponseMessage('Rollback completed successfully');
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {
  }
};

export const rollbackWordpress = async (slug, version, setResponseMessage, setProgress, setrollbackStatus) => {
  
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_rollback_update_core');
  formData.append('nonce', wptw_ajax.nonce);
  formData.append('data', JSON.stringify({ version, triggered_by: 'wp_admin' }));

  try {
    const response = await fetch(wptw_ajax.ajax_url, {
      method: 'POST',
      body: formData,
    });

    const streamedResponse = await handleStreamResponse(response, setProgress, setResponseMessage);
    const jsonStartIndex = streamedResponse.indexOf('{');
    const jsonEndIndex = streamedResponse.lastIndexOf('}') + 1;

    if (jsonStartIndex !== -1 && jsonEndIndex !== -1) {
      const jsonString = streamedResponse.substring(jsonStartIndex, jsonEndIndex);
      const finalResponse = JSON.parse(jsonString);

      if (response.status !== 200 || finalResponse.success === false ||  finalResponse.data.code === 500) {
        setResponseMessage('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        setrollbackStatus('rollback failed');
        toast.error('Rollback failed: ' + (finalResponse.data.message || 'Network response was not ok'));
        throw new Error(finalResponse.data.message || 'Network response was not ok');
      }

      toast.success(finalResponse.data.message || 'Rollback completed successfully');

      setrollbackStatus('rollback completed');
      setResponseMessage('Rollback completed successfully');
    } else {
      setResponseMessage('Unexpected response format');
      throw new Error('Unexpected response format');
    }
  } catch (error) {

  }
};

const handleStreamResponse = async (response, setProgress, setResponseMessage) => {
  const reader = response.body.getReader();
  const decoder = new TextDecoder('utf-8');
  let text = '';
  let lineCount = 0;

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    text += decoder.decode(value, { stream: true });
    lineCount++;

    if (lineCount === 1) setProgress(20);
    else if (lineCount === 2) setProgress(40);
    else if (lineCount === 3) setProgress(60);
    else if (lineCount === 4) setProgress(80);
    else if (lineCount >= 5) setProgress(100);

    setResponseMessage(text);
  }

  return text;
};

export const getWordpressDetails = async ({ setLoading, setProgress, setPluginDetails, setError, pluginSlug, setDetailLoading }) => {
  try {
    setLoading(true);
    setDetailLoading(true);
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_wordpress_details');
    formData.append('plugin_slug', pluginSlug);
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      onDownloadProgress: (progressEvent) => {
        const progress = Math.round((progressEvent.loaded / progressEvent.total) * 100);
        setProgress(progress);
      },
    });    


if (response.data?.success === false && response.data?.data?.code === 503) {

  const details = response.data?.data?.core;
  if (details?.is_custom) {
    setPluginDetails(details);
  } else {
    setError(response.data?.data?.message || 'Service unavailable');
  }
  return;
}

    if (response.data.success && response.data) {
      setPluginDetails(response.data.data.core);
    } else {
      setError(response?.data?.message || 'Failed to retrieve WordPress details');
    }
  } catch (error) {
    setError('Error fetching WordPress details');
  } finally {
    setLoading(false);
    setDetailLoading(false);
  }
};

export const getTotalUpdates = async (setThemeUpdateCount, setPluginUpdateCount, setCoreUpdateCount, setError) => {
  try {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", 'wptw_get_total_updates_available');
    formData.append("nonce", wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    
    if (response.status !== 200 || response.data.success === false ) {
      throw new Error(response.data.data.message || "Network response was not ok");
    }

    const { themes, plugins, core } = response.data.data;
    setThemeUpdateCount(themes?.updates);
    setPluginUpdateCount(plugins?.updates);
    setCoreUpdateCount(core?.updates);
  } catch (error) {
  }
};

export const activatePluginTheme = async (action_type, type, slug) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', action_type);
  formData.append('data', JSON.stringify({ [type]: slug, triggered_by: 'wp_admin' }));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await fetch(wptw_ajax.ajax_url, { method: 'POST', body: formData });
    const result = await response.json();

    if (response.status !== 200 || result.success === false) {
      throw new Error(result.data?.message || 'Failed to activate');
    }

    toast.success(result.data?.message || 'Activated successfully');
    return result;
  } catch (error) {
  }
};

export const deactivatePluginTheme = async (action_type, type, slug) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', action_type);
  formData.append('data', JSON.stringify({ [type]: slug, triggered_by: 'wp_admin' }));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await fetch(wptw_ajax.ajax_url, { method: 'POST', body: formData });
    const result = await response.json();

    if (response.status !== 200 || result.success === false) {
      throw new Error(result.data?.message || 'Failed to deactivate');
    }

    toast.success(result.data?.message || 'Deactivated successfully');
    return result;
  } catch (error) {
  }
};

export const deletePluginTheme = async (action_type, type, slug) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', action_type);
  formData.append('data', JSON.stringify({ [type]: slug, triggered_by: 'wp_admin' }));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await fetch(wptw_ajax.ajax_url, { method: 'POST', body: formData });
    const result = await response.json();

    if (response.status !== 200 || result.success === false) {
      throw new Error(result.data?.message || 'Failed to delete');
    }

    toast.success(result.data?.message || 'Deleted successfully');
    return result;
  } catch (error) {
  }
};

export const bulkPluginAction = async (action, plugins) => {  
  const cleanPlugins = plugins.map(plugin => plugin.replace(/\\/g, ''));

  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_bulk_plugin_action');
  formData.append('data', JSON.stringify({ action, plugins: cleanPlugins, triggered_by: 'wp_admin' }));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await fetch(wptw_ajax.ajax_url, { method: 'POST', body: formData });
    const result = await response.json();

    if (response.status !== 200 || result.success === false) {
      throw new Error(result.data?.message || `Failed to ${action} plugins`);
    }

    const actionPastTense = action === 'delete' ? 'deleted' : action === 'activate' ? 'activated' : 'deactivated';
    toast.success(result.data?.message || `Plugins ${actionPastTense} successfully`);
    return result;
  } catch (error) {
  }
};

export const getViewDetails = async (page = 1, limit = 10, type) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_get_history');

  const dataPayload = { page, limit, item_type: type };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {
  }
};

export const getPluginHistory = async (page = 1, limit = 10, type) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_get_plugin_history');

  const dataPayload = { page, limit, plugin: type };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {
  }
};

export const getThemeHistory = async (page = 1, limit = 10, type) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_get_theme_history');

  const dataPayload = { page, limit, theme: type };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {
  }
};

export const getCoreHistory = async (page = 1, limit = 10) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_get_core_history');

  const dataPayload = { page, limit };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {
  }
};

export const checkPluginCompatibility = async (version, plugin) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_check_plugin_compatibility');

  const dataPayload = { version, plugin };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {

  }
};

export const checkThemeCompatibility = async (version, theme) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_check_theme_compatibility');

  const dataPayload = { version, theme };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {
  }
};

export const checkCoreCompatibility = async (version) => {
  const formData = new FormData();
  formData.append('action', 'wptw_global_ajax_handler');
  formData.append('action_type', 'wptw_check_core_compatibility');

  const dataPayload = { version };

  formData.append('data', JSON.stringify(dataPayload));
  formData.append('nonce', wptw_ajax.nonce);

  try {
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return response;
  } catch (error) {   
  }
};