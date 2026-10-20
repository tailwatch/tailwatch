import axios from 'axios';
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

// Only for a screen that localizes no service URLs; the plugin localizes this same address.
const DASHBOARD_URL_FALLBACK = 'https://dashboard.wptailwatch.com';

// The dashboard's address is also the only origin its messages are accepted from.
const getDashboardUrl = (ajax) => {
    const url = ajax?.urls?.dashboard;
    return typeof url === 'string' && url !== '' ? url : DASHBOARD_URL_FALLBACK;
};

export const handleConfirmDisconnect = async (email, setEmail, setLicenseKey, setConnectionDateTime, setPlanName, setDevices, setLoading, refreshLicense, fetchFeaturesData) => {



    const website = encodeURIComponent(tailwatch_ajax.base_url);
    const ac = encodeURIComponent('unlink');
    const encodedEmail = encodeURIComponent(email || '');
    const dashboardUrl = getDashboardUrl(tailwatch_ajax);
    const popupUrl = `${dashboardUrl}/verifyandauthenticate?ip=true&website=${website}&ac=${ac}&email=${encodedEmail}`;

    // If the popup is already open from a previous click, refresh it with the latest URL
    // so a re-click always reflects the most recent credentials/params instead of stale ones.
    if (window.tailwatch_disconnect_popup_open && window.tailwatch_disconnect_popup && !window.tailwatch_disconnect_popup.closed) {
        try {
            window.tailwatch_disconnect_popup.location.href = popupUrl;
        } catch (e) {
            // Cross-origin writes to .location are normally allowed, but if anything blocks it
            // fall back to closing + reopening — the named target dedupes to a single window.
            try { window.tailwatch_disconnect_popup.close(); } catch (_) {}
            window.tailwatch_disconnect_popup = window.open(popupUrl, 'LoginPopup', 'width=1200,height=740');
        }
        window.tailwatch_disconnect_popup.focus();
        return;
    }

    // Store the popup reference globally
    window.tailwatch_disconnect_popup = window.open(
        popupUrl,
        'LoginPopup',
        'width=1200,height=740'
    );

    window.tailwatch_disconnect_popup_open = true;

    // Create a named handler function so we can remove it later
    const messageHandler = async (event) => {
        if (event.origin !== dashboardUrl) return;

        if (event.data.type === 'LOGOUT_SUCCESS') {
            try {
                // Remove the event listener first to prevent multiple executions
                window.removeEventListener('message', messageHandler);
                window.tailwatch_disconnect_popup_open = false;

                if (window.tailwatch_disconnect_popup && !window.tailwatch_disconnect_popup.closed) {
                    window.tailwatch_disconnect_popup.close();
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
        if (window.tailwatch_disconnect_popup && window.tailwatch_disconnect_popup.closed) {
            clearInterval(checkPopupClosed);
            window.removeEventListener('message', messageHandler);
            window.tailwatch_disconnect_popup_open = false;
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
    // The popup hands these to the dashboard; a pairing cannot be made without every one.
    const credentials = [ctaId, ctaSecret, cookieName, cookieValue, authHeaderKey];
    if (!credentials.every((value) => typeof value === 'string' && value !== '')) {
        return;
    }

    const dashboardUrl = getDashboardUrl(tailwatch_ajax);
    const website = encodeURIComponent(tailwatch_ajax.base_url);
    const ac = encodeURIComponent('link');
    const env = encodeURIComponent('production');
    const popupUrl = `${dashboardUrl}/signin?ip=true&website=${website}&ac=${ac}&env=${env}&client_id=${ctaId}&client_secret=${ctaSecret}&cookie_name=${cookieName}&cookie_value=${cookieValue}&auth_header_key=${authHeaderKey}`;

    // If the popup is already open from a previous click, refresh it so a re-click always
    // reflects the latest sign-in URL instead of a stale one.
    if (window.tailwatch_connect_popup_open && window.tailwatch_connect_popup && !window.tailwatch_connect_popup.closed) {
        try {
            window.tailwatch_connect_popup.location.href = popupUrl;
        } catch (e) {
            // Same-origin reads are blocked but parent-initiated navigations are allowed.
            // Fall back to close + reopen with the named target so we never end up with two popups.
            try { window.tailwatch_connect_popup.close(); } catch (_) {}
            window.tailwatch_connect_popup = window.open(popupUrl, 'LoginPopup', 'width=1200,height=740');
        }
        window.tailwatch_connect_popup.focus();
        return;
    }

    window.tailwatch_connect_popup = window.open(
        popupUrl,
        'LoginPopup',
        'width=1200,height=740'
    );

    window.tailwatch_connect_popup_open = true;



    const messageHandler = async (event) => {
        if (event.origin !== dashboardUrl) return;
        if (event.data.type === 'LOGIN_SUCCESS') {
            try {
                // Remove the event listener first to prevent multiple executions
                window.removeEventListener('message', messageHandler);
                window.tailwatch_connect_popup_open = false;
                if (window.tailwatch_connect_popup && !window.tailwatch_connect_popup.closed) {
                    window.tailwatch_connect_popup.close();
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
        if (window.tailwatch_connect_popup && window.tailwatch_connect_popup.closed) {
            clearInterval(checkPopupClosed);
            window.removeEventListener('message', messageHandler);
            window.tailwatch_connect_popup_open = false;
        }
    }, 1000);
};

/**
 * Connect this site from any "Connect" button.
 *
 * Mints the pairing credentials first: the client ID, client secret and header key that
 * authenticate the Connect API, and a recovery cookie the dashboard keeps so it can reach
 * the site if it later becomes unreachable. The sign-in popup opens only when both were
 * minted, since it is what hands them to the dashboard.
 */
export const connectWithNewPairing = async ({ setConnecting, setLoading, tailwatch_ajax, successCallback }) => {
    // The two mint calls report their own progress; this keeps one steady state across both.
    const keepConnecting = () => {};
    setConnecting(true);
    try {
        const secretKeys = await getSecretKeys({ setConnecting: keepConnecting });
        if (!secretKeys) {
            return;
        }
        const cookies = await getCookies({ setConnecting: keepConnecting });
        if (!cookies) {
            return;
        }

        await handleConnect({
            setLoading,
            tailwatch_ajax,
            successCallback,
            ctaId: secretKeys.cta_id,
            ctaSecret: secretKeys.cta_secret,
            authHeaderKey: secretKeys.auth_header_key,
            cookieName: cookies.name,
            cookieValue: cookies.value,
        });
    } finally {
        setConnecting(false);
    }
};


