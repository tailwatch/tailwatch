import { useEffect, useState } from "react";
import axios from "axios";
import { setMalwareStarted } from "../../Redux/ScanSlice";
import { useDispatch } from "react-redux";
/* global tailwatch_ajax */

export const useVerifyStatus = (navigate) => {
    const [isLoading, setIsLoading] = useState(true);
    const dispatch = useDispatch();

    const verifyStatus = async () => {
        try {
            setIsLoading(true);
            const formData = new FormData();
            formData.append("action", "tailwatch_global_ajax_handler");
            formData.append("action_type", "tailwatch_malware_scanner_verify_status");
            formData.append("nonce", tailwatch_ajax.nonce);

            const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });

            const scanState = response?.data?.data?.scan_state ?? null;

            if (scanState === "in-progress") {
                dispatch(setMalwareStarted(true));
                navigate("/dashboard/malwarescanner");
            } else {
                dispatch(setMalwareStarted(false));
            }
        } catch (e) {
            console.error("Error verifying scanner status:", e);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        verifyStatus();
    }, []);

    return { verifyStatus, isLoading };
};
