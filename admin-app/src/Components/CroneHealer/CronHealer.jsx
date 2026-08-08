import axios from 'axios';

/* global tailwatch_ajax */

export const CronHealer = async () => {
    try {
        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_cron_healer");
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

    } catch (error) {
    }
};