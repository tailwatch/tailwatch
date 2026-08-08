import axios from "axios";
import { toast } from "react-toastify";
/* global tailwatch_ajax */

export const getIpDetails = async ({ setLoading, setIpDetailsData, page = 1, limit = 10, setFeatureEnable, setParentEnable, setPagination, setIsLicenseConnect }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_all_ip_activities');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setIpDetailsData(response.data.data.activities);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_items: response.data.data.pagination.total });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        } else {
            console.error('Failed to get IP Details', response.data.data.message);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            console.error('Failed to get IP Details :', error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleUnblockIp = async ({ setLoading, ip, getIpDetailsData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_unblock_ip');
        formData.append('data', JSON.stringify(ip));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Unblock successfully");
            await getIpDetailsData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to unblock rule', response.data.data.message);
            setLoading(false);
        }
    } catch (error) {
        console.error('Network Error');
        setLoading(false);
    }
}

export const handleDeleteIp = async ({ setLoading, payload, getIpDetailsData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_delete_ip_activity');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Deleted successfully");
            await getIpDetailsData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to Deleted rule', response.data.data.message);
            setLoading(false);
        }
    } catch (error) {
        console.error('Network Error');
        setLoading(false);
    }
}

export const getIpDataDetails = async ({ setLoading, setIpData, setCurrentActivity, ip, page = 1, limit = 10, setPagination }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_ip_activity_history');
        formData.append('data', JSON.stringify({ ip, page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setIpData(response.data.data.history);
            setCurrentActivity(response.data.data.current_activity);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, });
            }
        } else {
            console.error('Failed to get IP Details', response.data.data.message);
        }
    } catch (error) {
        console.error('Failed to get IP Details :', error);
    } finally {
        setLoading(false);
    }
}
