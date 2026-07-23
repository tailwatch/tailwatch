import React from 'react'
import axios from "axios";
import {toast} from 'react-toastify';

/* global wptw_ajax */

export const fetchNotificationStatus = async ({ onStatusChange }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_get_push_notification');
        formData.append('nonce', wptw_ajax.nonce);
        const response = await axios.post(wptw_ajax.ajax_url, formData);
        
        if (response.data.data.code === 200) {            
            const notificationData = {
                push_notification: response.data.data.push_notification,
                mobile_notification: response.data.data.mobile_notification,
                mobile_notification_disabled: response.data.data.license_connected,
                severity_settings: response.data.data.severity_settings || {
                    critical: true,
                    warning: true,
                    info: true
                }
            };
            if (onStatusChange) {
                onStatusChange(notificationData.push_notification || notificationData.mobile_notification );
            }
            return notificationData;
        } else {
            throw new Error("Failed to fetch notification status");
        }
    } catch (error) {
        // Let axios interceptor handle error modal display
        throw error;
    }
};
export const handleToggleNotification = async ({ isEnabled, severitySettings, loadNotificationStatus, mobileNotification }) => {
    try {
        const formData = new FormData();
        formData.append("action", "wptw_global_ajax_handler");
        formData.append("action_type", "wptw_enable_disable_push_notification");

        const payload = { 
            push_notification: isEnabled,
            mobile_notification: mobileNotification !== undefined ? mobileNotification : false
        };
        if (severitySettings) {
            payload.notification_severity = severitySettings;
        }

        formData.append("data", JSON.stringify(payload));
        formData.append('nonce', wptw_ajax.nonce);

        const response = await axios.post(wptw_ajax.ajax_url, formData);

        if (response.data.data.code === 200) {
            if (response.data.data.message === "Please connect the license to update push notification.") {
                toast.error("Please connect the license to enable email notifications.");
                return false;
            }
            if (loadNotificationStatus) {
                await loadNotificationStatus();
            }
            // toasts handled in component after save
            return true;
        } else {
            throw new Error("Failed to update notification status");
        }
    } catch (error) {
        // Let axios interceptor handle error modal display
        throw error;
    }
};

export const processMoniteringStatus = async () => {
    try {
        const formData = new FormData();
        formData.append("action", "wptw_global_ajax_handler");
        formData.append("action_type", "wptw_get_process_monitoring_status");
        formData.append('nonce', wptw_ajax.nonce);
        const response = await axios.post(wptw_ajax.ajax_url, formData);
        const payload = response?.data?.data;
        if (payload?.code === 200) {
            return payload;
        }
        throw new Error("Failed to fetch process monitoring status");
    } catch (error) {
        // Let axios interceptor handle error modal display
        throw error;
    }
};
