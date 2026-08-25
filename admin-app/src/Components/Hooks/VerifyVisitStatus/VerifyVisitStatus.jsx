import { useEffect, useState } from "react";
import axios from "axios";

/* global tailwatch_ajax */

export const useVerifyVisitStatus = (navigate) => {
    const [isVerifyLoading, setIsLoading] = useState(true);
    const [visitCompleted, setVisitCompleted] = useState(null);
    const [visitStep, setVisitStep] = useState('');
    const [visitData, setVisitData] = useState({});
    const [setupStarted, setSetupStarted] = useState(false);

    const extractJsonFromResponse = (responseData) => {
        // If responseData is already an object, return it
        if (typeof responseData === 'object' && responseData !== null) {
            return responseData;
        }
        
        // If responseData is a string, try to extract JSON
        if (typeof responseData === 'string') {
            // Look for the specific JSON pattern: {"success":true,"data":{...}}
            const jsonPattern = /\{\\?"success\\?":\s*true,\\?"data\\?":\{[^}]*\}\}/;
            const jsonMatch = responseData.match(jsonPattern);
            
            if (jsonMatch) {
                try {
                    // Clean up escaped quotes and parse
                    const cleanJson = jsonMatch[0].replace(/\\"/g, '"');
                    return JSON.parse(cleanJson);
                } catch (e) {
                    return null;
                }
            }
        }
        
        return null;
    };

    const VerifyVisitStatus = async () => {
        try {
            const formData = new FormData();
            formData.append("action", "tailwatch_global_ajax_handler");
            formData.append("action_type", "tailwatch_verify_visit_progress");
            formData.append("nonce", tailwatch_ajax.nonce);

            const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });

            if (response && response.data) {
                // Extract JSON from potentially malformed response
                const cleanData = extractJsonFromResponse(response.data);
                
                if (cleanData && cleanData.success && cleanData.data) {
                    const { visit_step, is_completed, visit_data } = cleanData.data;

                    setVisitStep(visit_step);
                    setVisitData(visit_data || {});
                    setVisitCompleted(is_completed);
                    setSetupStarted(!!(visit_data && visit_data.setup_started));

                    // Don't redirect if we're on /visit page during db_initialize - let Visit component handle it
                    const currentPath = window.location.pathname;
                    if (currentPath === '/visit' && visit_step === 'db_initialize') {
                        return;
                    }

                    if (visit_step === 'recommended_features' || visit_step === 'features_implemented') {
                        navigate('/visitfeatures');
                        return;
                    }

                    if (is_completed === false) {
                        navigate('/visit');
                    } else {
                        // navigate('/dashboard');
                    }
                }
            }
        } catch (e) {
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        VerifyVisitStatus();
    }, []);

    return {
        visitCompleted,
        isVerifyLoading,
        visitStep,
        setVisitStep,
        visitData,
        setupStarted,
    };
};