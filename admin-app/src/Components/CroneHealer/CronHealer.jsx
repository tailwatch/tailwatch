import axios from 'axios';

/* global wptw_ajax */

export const CronHealer = async () => {
    try {
        const formData = new FormData();
        formData.append("action", "wptw_global_ajax_handler");
        formData.append("action_type", "wptw_cron_healer");
        formData.append("nonce", wptw_ajax.nonce);

        const response = await axios.post(wptw_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

    } catch (error) {
    }
};