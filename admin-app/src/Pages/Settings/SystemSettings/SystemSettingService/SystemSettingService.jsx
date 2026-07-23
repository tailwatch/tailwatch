import axios from "axios";
import { toast } from 'react-toastify';
/* global wptw_ajax */

export const getSystemSettings = async ({ section }) => {
    try {        
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_get_formatted_website_stats');
        if(section){
            formData.append('data', JSON.stringify({ sections: [section] }));
        }
        formData.append('nonce', wptw_ajax.nonce);
        const response = await axios.post(wptw_ajax.ajax_url, formData, {
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
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_get_php_settings');        
        formData.append('nonce', wptw_ajax.nonce);
        const response = await axios.post(wptw_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        
        return response;
    } catch (error) {
        toast.error('Network error occurred');                
    }
}

export const updatePhpSettings = async ({editablePhpSettings}) => {
    try {        
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_save_php_settings');        
        formData.append('data', JSON.stringify(editablePhpSettings));
        formData.append('nonce', wptw_ajax.nonce);
        const response = await axios.post(wptw_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        
        return response;
    } catch (error) {
        toast.error('Network error occurred');                
    }
}