import axios from "axios";
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

export const getSystemSettings = async ({ section }) => {
    try {        
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_formatted_website_stats');
        if(section){
            formData.append('data', JSON.stringify({ sections: [section] }));
        }
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return response;
    } catch (error) {
        toast.error('Network error occurred');
    }
}

export const getPhpSettings = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_php_settings');
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return response;
    } catch (error) {
        toast.error('Network error occurred');
    }
}

export const updatePhpSettings = async ({ editablePhpSettings }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_save_php_settings');
        formData.append('data', JSON.stringify(editablePhpSettings));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return response;
    } catch (error) {
        toast.error('Network error occurred');
    }
}

export const removePhpSettings = async () => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_remove_php_settings');
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        return response;
    } catch (error) {
        toast.error('Network error occurred');
    }
}
