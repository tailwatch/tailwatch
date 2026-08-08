import axios from "axios";
import { toast } from "react-toastify";
import { alertService } from "../../../Components/AlertService/AlertService";

/* global tailwatch_ajax */

export const addToBlackList = async ({ setLoading, onClose, payload, getData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_add_ip_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Added to BlackList successfully");
            onClose?.();
            getData?.();
            return true;
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
            return false;
        }
    } catch (error) {
        console.error('Network Error');
        return false;
    } finally {
        setLoading(false);
    }
}

export const updateBlackList = async ({ setLoading, onClose, payload, getData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_update_ip_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Updated successfully");
            onClose();
            getData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const getBlackList = async ({ setLoading, setBlackListData, page = 1, limit = 10, setPagination, setFeatureEnable, setParentEnable, setIsLicenseConnect }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_blocked_ip_ranges');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setBlackListData(response.data.data.data);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_items: response.data.data.pagination.total });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        } else {
            console.error('Failed to get block ip ranges', response.data.data.message);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            console.error('Failed to get block ip ranges :', error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleDeleteIp = async ({ setLoading, payload, getBlackListIpRanges }) => {
    //  const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    //   if (!confirmed) {
    //     return;
    //   }  
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_delete_ip_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Deleted successfully");
            await getBlackListIpRanges();
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

export const handleUnblockIp = async ({ setLoading, payload, getBlackListIpRanges }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_unblock_ip_range');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Unblock successfully");
            await getBlackListIpRanges();
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

export const blockCountries = async ({ setLoading, onClose, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_add_country_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Country Blocked successfully");
            onClose();
            getBlockCountriesData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const getBlockCountries = async ({ setLoading, setBlockCountriesData, page = 1, limit = 10, setFeatureEnable, setParentEnable, setIsLicenseConnect, setPagination }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_blocked_countries');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data?.code === 402) {
            setIsLicenseConnect(response.data.data);

        } else if (response.data.success === true) {
            setBlockCountriesData(response.data.data.data);

            if (setPagination && response.data.data.pagination) {
                setPagination({
                    page: response.data.data.pagination.page,
                    limit: response.data.data.pagination.limit,
                    total_pages: response.data.data.pagination.total_pages,
                    total_items: response.data.data.pagination.total
                });
            }

        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);

        } else {
            console.error('Failed to get block countries', response.data.data.message);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else if (error.response.data.data.code === 402) {
            setIsLicenseConnect(error.response.data.data);
        }
        else {
            console.error('Failed to get block countires :', error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleUnblockCountry = async ({ setLoading, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_unblock_country_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Country Unblock successfully");
            await getBlockCountriesData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to unblock Country', response.data.data.message);
            setLoading(false);
        }
    } catch (error) {
        console.error('Network Error');
        setLoading(false);
    }
}

export const handleDeleteCountry = async ({ setLoading, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_delete_country_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Country Deleted successfully");
            await getBlockCountriesData();
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

export const updateCountries = async ({ setLoading, onClose, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_update_country_rule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Updated successfully");
            onClose();
            getBlockCountriesData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to update', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const getIpWhiteList = async ({ setLoading, setIpWhiteListData, page = 1, limit = 10, setFeatureEnable, setParentEnable, setIsLicenseConnect, setPagination }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_ip_whitelists');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setIpWhiteListData(response.data.data.data);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_items: response.data.data.pagination.total });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        } else {
            console.error('Failed to get Whitelist ip ranges', response.data.data.message);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            console.error('Failed to get Whitelist ip ranges :', error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleDeleteWhitelistIp = async ({ setLoading, payload, getWhiteListIpRanges }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_delete_ip_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Deleted successfully");
            await getWhiteListIpRanges();
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

export const addIpToWhiteList = async ({ setLoading, onClose, payload, getData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_add_ip_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Added to WhiteList successfully");
            onClose?.();
            getData?.();
            return true;
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
            return false;
        }
    } catch (error) {
        console.error('Network Error');
        return false;
    } finally {
        setLoading(false);
    }
}

export const updateIpWhiteList = async ({ setLoading, onClose, payload, getData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_update_ip_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Ip address Updated successfully");
            onClose();
            getData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const getWhitelistCountries = async ({ setLoading, setWhitlistCountries, page = 1, limit = 10, setFeatureEnable, setParentEnable, setIsLicenseConnect, setPagination }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_get_country_whitelists');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setWhitlistCountries(response.data.data.data);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_items: response.data.data.pagination.total });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        } else {
            console.error('Failed to get Whitelist countries', response.data.data.message);
        }
    } catch (error) {
        if (error.response.data.data.code === 403) {
            setIsLicenseConnect(true);
        } else {
            console.error('Failed to get Whitelist countires :', error);
        }
    } finally {
        setLoading(false);
    }
}

export const handleDeleteWhitelistCountry = async ({ setLoading, payload, getWhitelistCountryData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_delete_country_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Country Deleted successfully");
            await getWhitelistCountryData();
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

export const addwhitelistCountries = async ({ setLoading, onClose, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_add_country_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Country whitelisted successfully");
            onClose();
            getBlockCountriesData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to add rule', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const updatewhitelistCountries = async ({ setLoading, onClose, payload, getBlockCountriesData }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_handle_update_country_whitelist');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            toast.success("Updated successfully");
            onClose();
            getBlockCountriesData();
        } else if (response.data.success === false) {
            toast.error(response.data.data.message);
            console.error('Failed to update', response.data.data.message);
        }
    } catch (error) {
        console.error('Network Error');
    } finally {
        setLoading(false);
    }
}

export const previewBlockPage = async (payload = {}) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_preview_block_page');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        const body = response?.data?.data;
        if (response?.data?.success && body?.code === 200) {
            const inner = body.data;
            const html = (typeof inner === 'string' ? inner : inner?.html) || body.html || '';
            return { success: true, html };
        }
        return { success: false, message: body?.message || 'Failed to load preview' };
    } catch (error) {
        const msg = error?.response?.data?.data?.message || 'Network error. Please try again.';
        return { success: false, message: msg };
    }
};
