import axios from "axios";
import { toast } from 'react-toastify';
/* global wptw_ajax */

export const getIntegrationData = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_get_integration_data');
        formData.append('nonce', wptw_ajax.nonce);

        const response = await axios.post(wptw_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
            return response.data.data;
    } catch (error) {
    }
}

export const updateIntegrationData = async (payload) => {
    try {
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_update_integration_data');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', wptw_ajax.nonce);

        const response = await axios.post(wptw_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
       return response.data;
    } catch (error) {
    }
}

export const deleteIntegrationData = async (section = 'maxmind') => {
    try {
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_delete_integration_data');
        formData.append('data', JSON.stringify({ section }));
        formData.append('nonce', wptw_ajax.nonce);

        const response = await axios.post(wptw_ajax.ajax_url, formData, {
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
}
