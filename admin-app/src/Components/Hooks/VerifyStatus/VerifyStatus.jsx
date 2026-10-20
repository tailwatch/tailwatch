import { useEffect, useState } from "react";
import axios from "axios";
import { setMalwareStarted, setRestoreRunning } from "../../Redux/ScanSlice";
import { useDispatch } from "react-redux";
import { fetchRestoreStatus } from "../../../Pages/Backup/RestoreServices/RestoreServices.jsx";
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
                return;
            }

            dispatch(setMalwareStarted(false));

            // A restore outlives the tab it was started from — it runs on cron, and the
            // site is unreachable for part of it, so the operator very often comes back to
            // a fresh page while one is still going. Asked here, on the same entry check
            // the scanner uses, so arriving anywhere in the dashboard during a restore
            // lands on the screen that reports it rather than on a Home page that cannot
            // mention the most destructive thing the plugin does.
            const restore = await fetchRestoreStatus();
            const restorePhase = restore?.phase ?? "idle";

            if (restore && !restore.signedOut && !["idle", "done", "failed"].includes(restorePhase)) {
                const folder = restore.folderDate || "";
                dispatch(setRestoreRunning(folder || " "));
                navigate(folder ? `/dashboard/restore/${encodeURIComponent(folder)}` : "/dashboard/restore");
            } else {
                dispatch(setRestoreRunning(""));
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
