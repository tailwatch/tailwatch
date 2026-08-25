import axios from 'axios';
import { toast } from 'react-toastify';
/* global tailwatch_ajax */
export const handleConfirmDisconnect = async (email, setEmail, setLicenseKey, setConnectionDateTime, setPlanName, setDevices, setLoading, refreshLicense, fetchFeaturesData) => {



    const website = encodeURIComponent(tailwatch_ajax.base_url);
    const ac = encodeURIComponent('unlink');
    const encodedEmail = encodeURIComponent(email || '');
    const popupUrl = `https://dashboard.wptailwatch.com/verifyandauthenticate?ip=true&website=${website}&ac=${ac}&email=${encodedEmail}`;

    // If the popup is already open from a previous click, refresh it with the latest URL
    // so a re-click always reflects the most recent credentials/params instead of stale ones.
    if (window.disconnectPopupOpen && window.disconnectPopup && !window.disconnectPopup.closed) {
        try {
            window.disconnectPopup.location.href = popupUrl;
        } catch (e) {
            // Cross-origin writes to .location are normally allowed, but if anything blocks it
            // fall back to closing + reopening — the named target dedupes to a single window.
            try { window.disconnectPopup.close(); } catch (_) {}
            window.disconnectPopup = window.open(popupUrl, 'LoginPopup', 'width=1200,height=740');
        }
        window.disconnectPopup.focus();
        return;
    }

    // Store the popup reference globally
    window.disconnectPopup = window.open(
        popupUrl,
        'LoginPopup',
        'width=1200,height=740'
    );

    window.disconnectPopupOpen = true;

    // Create a named handler function so we can remove it later
    const messageHandler = async (event) => {
        if (event.origin !== 'https://dashboard.wptailwatch.com') return;

        if (event.data.type === 'LOGOUT_SUCCESS') {
            try {
                // Remove the event listener first to prevent multiple executions
                window.removeEventListener('message', messageHandler);
                window.disconnectPopupOpen = false;

                if (window.disconnectPopup && !window.disconnectPopup.closed) {
                    window.disconnectPopup.close();
                }

                setLoading(true);

                const formData = new FormData();
                formData.append('action', 'tailwatch_global_ajax_handler');
                formData.append('action_type', 'tailwatch_delete_plugin_activation_data');
                formData.append('nonce', tailwatch_ajax.nonce);
                formData.append('data', 'extended_connected');

                const deleteResponse = await axios.post(tailwatch_ajax.ajax_url, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });

                if (deleteResponse.status === 200 && deleteResponse.data.success) {
                    setEmail('');
                    setLicenseKey('');
                    setConnectionDateTime('');
                    setPlanName('');
                    setDevices([]);
                    await refreshLicense();
                    await fetchFeaturesData();
                    toast.success("License disconnected successfully");
                } else {
                    toast.error("Unable to disconnect license");
                }
            } catch (error) {
            } finally {
                setLoading(false);
            }
        }
    };
    window.addEventListener('message', messageHandler);
    const checkPopupClosed = setInterval(() => {
        if (window.disconnectPopup && window.disconnectPopup.closed) {
            clearInterval(checkPopupClosed);
            window.removeEventListener('message', messageHandler);
            window.disconnectPopupOpen = false;
        }
    }, 1000);
};

export const fetchData = async ({
    setEmail,
    setLicenseKey,
    setConnectionDateTime,
    setPlanName,
    setDevices,
    setStartData,
    setEndData,
    setLoading,
    setIsExpiry,
    setRole,
}) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_plugin_activation');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        if (response.data.success && response.data.data.data) {
            const name = response.data.data.data.plan_name;
            setPlanName(name || null);
        } else {
            setPlanName(null);
            setDevices([]);

        }

        if (response.data.success && response.data.data.data) {
            const licenseData = {
                email: response.data.data.data.email,
                connectionDateTime: response.data.data.data.connection_date_time,
                licenseKey: response.data.data.data.license_key,
                planName: response.data.data.data.plan_name,
                devices: response.data.data.data.devices || [],
                startData: response.data.data.data.start_date,
                endData: response.data.data.data.end_date,
                isExpiry: response.data.data.data.expires_in,
                role: response.data.data.data.role,
            };

            setEmail(licenseData?.email);
            setLicenseKey(licenseData?.licenseKey);
            setConnectionDateTime(licenseData?.connectionDateTime);
            setPlanName(licenseData.planName);
            setDevices(licenseData?.devices);
            setStartData(licenseData?.startData);
            setEndData(licenseData?.endData);
            setIsExpiry(licenseData?.isExpiry);
            if (setRole) setRole(licenseData?.role || null);

        }
    } catch (error) {
        return false;
    } finally {
        setLoading(false);
    }
};

export const getSecretKeys = async ({ setConnecting }) => {
    setConnecting(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_generated_cta_keys');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        if (response.data.success && response.data.data) {
            const { cta_id, cta_secret, auth_header_key } = response.data.data;
            return { cta_id, cta_secret, auth_header_key };
        }
        return null;
    } catch (error) {
        return null;
    } finally {
        setConnecting(false);
    }
};

export const getCookies = async ({ setConnecting }) => {
    setConnecting(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_generate_recovery_cookie');
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        if (response.data.success && response.data.data) {
            const { name, value } = response.data.data;
            return { name, value };
        }
        return null;
    } catch (error) {
        toast.error("Network error");
        return null;
    } finally {
        setConnecting(false);
    }
};

export const handleConnect = async ({ setLoading, tailwatch_ajax, successCallback, ctaId, ctaSecret, cookieName, cookieValue, authHeaderKey }) => {
    const website = encodeURIComponent(tailwatch_ajax.base_url);
    const ac = encodeURIComponent('link');
    const env = encodeURIComponent('production');
    const popupUrl = `https://dashboard.wptailwatch.com/signin?ip=true&website=${website}&ac=${ac}&env=${env}&client_id=${ctaId}&client_secret=${ctaSecret}&cookie_name=${cookieName}&cookie_value=${cookieValue}&auth_header_key=${authHeaderKey}`;

    // If the popup is already open from a previous click, refresh it so a re-click always
    // reflects the latest sign-in URL instead of a stale one.
    if (window.connectPopupOpen && window.connectPopup && !window.connectPopup.closed) {
        try {
            window.connectPopup.location.href = popupUrl;
        } catch (e) {
            // Same-origin reads are blocked but parent-initiated navigations are allowed.
            // Fall back to close + reopen with the named target so we never end up with two popups.
            try { window.connectPopup.close(); } catch (_) {}
            window.connectPopup = window.open(popupUrl, 'LoginPopup', 'width=1200,height=740');
        }
        window.connectPopup.focus();
        return;
    }

    window.connectPopup = window.open(
        popupUrl,
        'LoginPopup',
        'width=1200,height=740'
    );

    window.connectPopupOpen = true;



    const messageHandler = async (event) => {
        if (event.origin !== 'https://dashboard.wptailwatch.com') return;
        if (event.data.type === 'LOGIN_SUCCESS') {
            try {
                // Remove the event listener first to prevent multiple executions
                window.removeEventListener('message', messageHandler);
                window.connectPopupOpen = false;
                if (window.connectPopup && !window.connectPopup.closed) {
                    window.connectPopup.close();
                }
                setLoading(true);                

                const loginData = {
                    devices: event.data.data.devices,
                    email: event.data.data.email,
                    licenseKey: event.data.data.licenseKey,
                    planName: event.data.data.planName,
                    connectionDateTime: event.data.data.connectionDateTime,
                    userId: event.data.data.userId,
                    role: event.data.data.role,
                    startDate: event.data.data.startDate,
                    endDate: event.data.data.endDate,
                    headerKey: event.data.data.headerKey,
                    route_tokens: event.data.data.route_tokens,
                };                

                const formData = new FormData();
                formData.append('action', 'tailwatch_global_ajax_handler');
                formData.append('action_type', 'tailwatch_update_plugin_activation');
                formData.append('nonce', tailwatch_ajax.nonce);
                formData.append('data', JSON.stringify(loginData));
                const response = await axios.post(tailwatch_ajax.ajax_url, formData);
                if (response.data.success) {
                    await successCallback();
                    toast.success("License connected successfully");
                } else {
                    toast.error(response.data?.data?.message || "Unable to Connect License");
                }
            } catch (error) {
            } finally {
                setLoading(false);
            }
        }
    };

    // Add the event listener for this popup session
    window.addEventListener('message', messageHandler);

    // Make sure we clean up if the popup is closed without completing the process
    const checkPopupClosed = setInterval(() => {
        if (window.connectPopup && window.connectPopup.closed) {
            clearInterval(checkPopupClosed);
            window.removeEventListener('message', messageHandler);
            window.connectPopupOpen = false;
        }
    }, 1000);
};


