import axios from 'axios';

/* global tailwatch_ajax */

/**
 * Generic preview fetcher for any "design"-typed feature.
 *
 * Backend contract per design feature:
 *   POST  tailwatch_ajax.ajax_url
 *     action       : tailwatch_global_ajax_handler
 *     action_type  : <schema.preview.action>
 *     nonce        : tailwatch_ajax.nonce
 *     data         : JSON.stringify({ draft, variant, device })
 *
 * Success response envelope:
 *   { success: true,  data: { code: 200, html: "<!DOCTYPE html>…" } }
 * Failure response envelope:
 *   { success: false, data: { code, message } }
 *
 * The HTML may also arrive at body.data.html, body.data, body.preview, or body.html
 * to match minor backend variations; we accept any of these locations.
 *
 * @param {Object}  args
 * @param {string}  args.action   action_type to dispatch to (required)
 * @param {Object}  args.draft    map of { register: value } for every sub_option
 * @param {string=} args.variant  optional variant key (e.g. "temporary")
 * @param {string=} args.device   optional device key (e.g. "desktop")
 * @param {AbortSignal=} args.signal  AbortController.signal for race-cancellation
 * @returns {Promise<{success: boolean, html?: string, message?: string, aborted?: boolean}>}
 */
export const fetchDesignPreview = async ({ action, draft = {}, variant = null, device = 'desktop', signal } = {}) => {
    if (!action) {
        return { success: false, message: 'No preview action configured.' };
    }

    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', action);
        formData.append('nonce', tailwatch_ajax.nonce);
        formData.append('data', JSON.stringify({ draft, variant, device }));

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            signal,
        });

        const body = response?.data?.data;
        if (response?.data?.success && body?.code === 200) {
            const inner = body.data;
            const html =
                (typeof inner === 'string' ? inner : inner?.html) ||
                body.html ||
                body.preview ||
                '';
            if (!html) {
                return { success: false, message: 'Preview returned no HTML.' };
            }
            return { success: true, html };
        }

        return {
            success: false,
            message: body?.message || 'Failed to load preview.',
            code: body?.code,
        };
    } catch (error) {
        if (axios.isCancel?.(error) || error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED') {
            return { success: false, aborted: true };
        }
        const msg =
            error?.response?.data?.data?.message ||
            error?.response?.data?.message ||
            'Network error. Please try again.';
        return { success: false, message: msg, code: error?.response?.data?.data?.code };
    }
};
