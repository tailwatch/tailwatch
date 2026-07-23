import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchDesignPreview } from '../../../Pages/Features/GlobalInput/FormElements/designPreviewService';

const DEBOUNCE_MS = 400;

/**
 * Live-preview hook for a "design"-typed feature.
 *
 * Responsibilities:
 *  - Debounce rapid edits (default 400ms) so we don't fire on every keystroke.
 *  - Force-flush immediately when variant or device changes (toggle feels instant).
 *  - Race-cancel in-flight requests with AbortController; ignore stale responses
 *    using a monotonic request id (belt-and-braces in case the browser doesn't
 *    actually abort fast enough).
 *  - Skip setState after unmount.
 *  - Keep the last good `html` while a new fetch is in flight so the preview
 *    never blanks — only the `loading` flag flips.
 *
 * Inputs:
 *  - action  : preview action_type from schema (string | null/undefined → disabled)
 *  - draft   : { register: value, ... } snapshot of current values
 *  - variant : "temporary" | "permanent" | null
 *  - device  : "desktop" | "mobile"
 *
 * Returns: { html, loading, error, code, refetch, enabled }
 */
export const useDesignPreview = ({ action, draft, variant, device }) => {
    const enabled = !!action;

    const [html, setHtml] = useState('');
    const [loading, setLoading] = useState(enabled);
    const [error, setError] = useState(null);
    const [code, setCode] = useState(null);

    const mountedRef = useRef(true);
    const requestIdRef = useRef(0);
    const abortRef = useRef(null);
    const timerRef = useRef(null);
    const firstFetchDoneRef = useRef(false);

    // Stable serialised key so React-friendly comparisons work cheaply.
    const draftKey = JSON.stringify(draft || {});

    const runFetch = useCallback(async () => {
        if (!enabled) return;

        // Cancel any in-flight request before starting a new one.
        if (abortRef.current) {
            try { abortRef.current.abort(); } catch { /* ignore */ }
        }
        const controller = new AbortController();
        abortRef.current = controller;
        const myId = ++requestIdRef.current;

        setLoading(true);

        const result = await fetchDesignPreview({
            action,
            draft: draft || {},
            variant,
            device,
            signal: controller.signal,
        });

        // Component unmounted while we were waiting — drop the result silently.
        if (!mountedRef.current) return;

        // A newer request started after us; its response is the authoritative one.
        if (myId !== requestIdRef.current) return;

        // Aborted by a newer request that started + finished while we were in
        // transit (rare but possible). Don't clobber state.
        if (result.aborted) return;

        if (result.success) {
            setHtml(result.html);
            setError(null);
            setCode(null);
        } else {
            // Keep previous good html mounted; just surface the error banner.
            setError(result.message || 'Failed to load preview.');
            setCode(result.code ?? null);
        }
        setLoading(false);
        firstFetchDoneRef.current = true;
    }, [enabled, action, draftKey, variant, device]); // eslint-disable-line react-hooks/exhaustive-deps

    // Debounced effect for draft edits.
    useEffect(() => {
        if (!enabled) {
            setLoading(false);
            return;
        }

        // First fetch ever (mount, or action just became defined): fire immediately
        // so the preview is never blank on open.
        if (!firstFetchDoneRef.current) {
            runFetch();
            return;
        }

        // Subsequent draft edits: debounce.
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(runFetch, DEBOUNCE_MS);

        return () => {
            if (timerRef.current) clearTimeout(timerRef.current);
        };
    }, [enabled, draftKey, runFetch]);

    // Variant / device changes refetch immediately (no debounce).
    useEffect(() => {
        if (!enabled || !firstFetchDoneRef.current) return;
        if (timerRef.current) clearTimeout(timerRef.current);
        runFetch();
    }, [enabled, variant, device]); // eslint-disable-line react-hooks/exhaustive-deps

    // Mount/unmount lifecycle.
    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            if (timerRef.current) clearTimeout(timerRef.current);
            if (abortRef.current) {
                try { abortRef.current.abort(); } catch { /* ignore */ }
            }
        };
    }, []);

    const refetch = useCallback(() => {
        if (timerRef.current) clearTimeout(timerRef.current);
        runFetch();
    }, [runFetch]);

    return { html, loading, error, code, refetch, enabled };
};
