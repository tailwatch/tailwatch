import axios from "axios";
/* global tailwatch_ajax */

export const getIntegrationData = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_integration_data');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data.data;
    } catch (error) {
        return null;
    }
};

export const updateIntegrationData = async (payload) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_update_integration_data');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data;
    } catch (error) {
        return null;
    }
};

export const deleteIntegrationData = async (section = 'maxmind') => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_delete_integration_data');
        formData.append('data', JSON.stringify({ section }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response?.data?.success) {
            const refreshed = await getIntegrationData();
            return { success: true, data: refreshed };
        }
        return { success: false, data: null };
    } catch (error) {
        return { success: false, data: null };
    }
};

// User-initiated update check: re-download the database and replace the installed
// copy only if MaxMind's version is newer. Triggered by the "Check for updates"
// button — there is no background/scheduled update. Returns { code, message, updated }.
export const updateGeoLiteDatabase = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_update_geo_lite_database');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return response.data?.data ?? null;
    } catch (error) {
        return null;
    }
};
