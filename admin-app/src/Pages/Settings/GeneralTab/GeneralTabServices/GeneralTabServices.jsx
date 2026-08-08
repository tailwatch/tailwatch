import axios from "axios";
import { toast } from 'react-toastify';
import { alertService } from "../../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

// Reset All Settings

let cronScheduled = false;
let isCronExecuting = false;
let cronTimeoutId = null;

const executeCronIfFailed = async (reset_id) => {
    if (isCronExecuting) {
        return;
    }
    try {
        isCronExecuting = true;

        const formData = new FormData();
        formData.append("action", "tailwatch_global_ajax_handler");
        formData.append("action_type", "tailwatch_reset_cron_if_failed");
        formData.append('data', JSON.stringify({ reset_id: reset_id }));
        formData.append("nonce", tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        if (response.data.success) {
            
        } else {
            console.error("Failed to execute cron.");
        }
    } catch (error) {
        console.error("Error executing cron", error);
    } finally {
        cronScheduled = false;
        isCronExecuting = false;
    }
};

const checkCronExecution = async (cron_running, reset_id) => {
    if (cron_running === false && !cronScheduled && !isCronExecuting) {
        cronScheduled = true;
        cronTimeoutId = setTimeout(async () => {
            try {
                await executeCronIfFailed(reset_id);
            } catch (error) {
                console.error("Error in scheduled cron execution", error);
            }
        }, 30000);

    } else if (cron_running === true && cronScheduled) {
        clearTimeout(cronTimeoutId);
        cronScheduled = false;
        cronTimeoutId = null;
    }
};

export const resetAllSettings = async ({ setIsReset, setLoading, setResetId, resetSettings, resetData }) => {
    // setIsReset controls the in-button spinner + disabled state while the API request is in flight.
    // It's reset to false on every return path so the button returns to normal once the response
    // arrives, regardless of outcome (409 / 200 / error).
    setIsReset(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_start_reset_all_settings');
        // Always send both keys as real booleans — omitting reset_settings makes the backend default it to true.
        formData.append('data', JSON.stringify({
            reset_settings: Boolean(resetSettings),
            reset_data: Boolean(resetData),
        }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data?.data?.code === 409 && response.data?.data?.data?.blocked_by_process === true) {
            const message = response.data?.data?.message || 'A background process is currently running. Please wait for it to finish before changing this setting.';
            alertService.warning(message, 'Process in Progress');
            setIsReset(false);
            return;
        }

        if (response.data.data.code === 200) {
            setResetId(response.data.data.reset_id);
            toast.success('Reset process started successfully.');
            setIsReset(false);
            return response.data.data.reset_id;
        } else {
            toast.error('Failed to reset settings. Please try again.');
            setLoading(false);
            setIsReset(false);
            throw new Error('Failed to reset settings');
        }
    } catch (error) {
        setLoading(false);
        setIsReset(false);
        throw error;
    }
}

export const resetSettingStatus = async (newResetId) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_reset_all_settings_status');
        if (newResetId) {
            formData.append('data', JSON.stringify({ reset_id: newResetId }));
        }

        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const { cron_running, reset_id } = response.data.data;
        checkCronExecution(cron_running, reset_id);
        if (response.data.data.code === 200) {
            const result = {
                isCompleted: response.data.data.completed === true,
                resetId: response.data.data.reset_id || newResetId,
                current_index: response.data.data.current_index || 0,
                total: response.data.data.total || 0
            };
            if (response.data.data.completed) {
                toast.success('Settings reset completed successfully.');
            }

            return result;
        } else {
            toast.error('Failed to reset settings status. Please try again.');
            return { isCompleted: true, resetId: null }; // Stop polling on error
        }
    } catch (error) {
        return { isCompleted: true, resetId: null }; // Stop polling on error
    }
}

// Verify Any Running process

export const verifyRunningProcess = async ({ setIsRunning }) => {
    try {
        setIsRunning(true);
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_verify_import_and_reset_status');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        
        if (response.status === 200) {
            return response.data.data;
        } else {
            setIsRunning(false);
        }
    } catch {
    } finally {
        setIsRunning(false);
    }
}

