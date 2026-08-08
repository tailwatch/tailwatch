import React, { useEffect, useState, useRef } from 'react';
import { getVisitFeatures, updateVisitFeatures, handleSubmitButton, handleFeatureCompletion } from '../VisitServices/VisitServices';
import { FaSpinner, FaCog, FaRocket, FaCheckCircle, FaUserCog } from "react-icons/fa";
import { handleConnect } from '../../Settings/LicenseTab/LicenseTabServices/LicenseTabServices'
import { useVerifyVisitStatus } from '../../../Components/Hooks/VerifyVisitStatus/VerifyVisitStatus';
import { toast } from 'react-toastify';
import VisitSteps from '../VisitSteps/VisitSteps';
import { ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import { useNavigate } from 'react-router-dom';
import VisitCards from './VisitCards/VisitCards';
import ProgressStep from './ProgressStep/ProgressStep';
/* global tailwatch_ajax */

const VisitFeatures = () => {
    const navigate = useNavigate();
    const [features, setFeatures] = useState([]);
    const [currentPage, setCurrentPage] = useState(0);
    const [progressValue, setProgressValue] = useState(0);
    const [loading, setIsLoading] = useState(true);
    const [isloading, setLoading] = useState(false);
    const [showCustomSettings, setShowCustomSettings] = useState(false);
    const [connecting, setConnecting] = useState(false);
    const [featureData,setFeatureData] = useState(null);
    const { visitStep, setVisitStep, visitCompleted } = useVerifyVisitStatus(navigate);    
    const featuresPerPage = 1;
    const isProcessingRef = useRef(false);

    useEffect(() => {
        getVisitFeatures(setFeatures, setIsLoading,setFeatureData);        
    }, []);

    useEffect(() => {
        if (visitStep === "features_implemented") {
            if (!isProcessingRef.current) {
                isProcessingRef.current = true;
                handleFeatureProcess();
            }
        }
    }, [isProcessingRef, visitStep]);

    useEffect(() => {
        if (visitCompleted) {
            navigate('/dashboard');
        }
    }, [visitCompleted, navigate]);

    useEffect(() => {
        if (visitStep === 'recommended_features') {
            setShowCustomSettings(false);
        }
    }, [visitStep]);

    const startIndex = currentPage * featuresPerPage;
    const endIndex = startIndex + featuresPerPage;
    const currentFeatures = features.slice(startIndex, endIndex);
    const totalPages = Math.ceil(features.length / featuresPerPage);

    const handleParentToggle = (feature) => {
        const featureValue = JSON.parse(feature.value);
        const featureId = feature.id
        // Convert string "1"/"0" to boolean for sending to backend
        const newIsActiveBoolean = feature.is_active === "1" ? false : true;
        const newIsActiveString = newIsActiveBoolean ? "1" : "0";
        const updatedFeature = { ...feature, is_active: newIsActiveString };

        const payload = { featureId, is_active: newIsActiveBoolean };

        updateVisitFeatures(setFeatures, payload);

        toast.success(`"${featureValue.title}" has been ${newIsActiveBoolean ? 'activated' : 'deactivated'}`);

        setFeatures((prevFeatures) =>
            prevFeatures.map((f) =>
                f.option === feature.option ? updatedFeature : f
            )
        );
    };

    const handleCustomSubmitVisit = async () => {
        handleSubmitButton({ setLoading, setVisitStep, isProcessingRef, handleFeatureProcess, setting: "custom_settings" });
    }

    const handleFeatureProcess = async () => {
        handleFeatureCompletion({ setProgressValue, navigate });
    };

    const handleConnectClick = async () => {
        setConnecting(true);
        try {
            await handleConnect({
                setLoading: setIsLoading, tailwatch_ajax,
                successCallback: async () => {
                    await getVisitFeatures(setFeatures, setIsLoading);
                },
            });
        } finally {
            setConnecting(false);
        }
    };

    const handleCustomSettings = () => {
        setShowCustomSettings(true);
    };

    const handleDefaultSettings = () => {
        handleSubmitButton({ setLoading, setVisitStep, isProcessingRef, handleFeatureProcess, setting: "default_settings" });
    };

    const SettingsSelection = () => (
        <div className="w-full max-w-5xl mx-auto">
            {/* Settings Cards */}
            <div className="grid lg:grid-cols-2 gap-8 mb-12">
                <div
                    onClick={handleCustomSettings}
                    className="group cursor-pointer transform transition-all duration-500 hover:scale-105 hover:-translate-y-2"
                >
                    <div className="bg-white bg-opacity-95 backdrop-blur-sm border-2 border-orange-200 rounded-3xl p-8 text-center hover:border-orange-500 hover:shadow-2xl transition-all duration-500 h-full relative overflow-hidden">
                        {/* Gradient overlay on hover */}
                        <div className="absolute inset-0 bg-gradient-to-br from-orange-50 to-red-50 opacity-0 group-hover:opacity-100 transition-opacity duration-500 rounded-3xl"></div>

                        <div className="relative z-10">
                            <div className="flex justify-center mb-6">
                                <div className="bg-gradient-to-br from-orange-500 to-red-600 text-white rounded-full p-7 transition-all duration-500 shadow-xl group-hover:shadow-2xl group-hover:scale-110">
                                    <FaUserCog className="text-4xl" />
                                </div>
                            </div>
                            <h3 className="text-2xl font-bold text-gray-800 mb-4 group-hover:text-orange-600 transition-colors duration-300">
                                Custom Settings
                            </h3>
                            <p className="text-gray-600 mb-6 leading-relaxed text-base">
                                Take full control and manually configure each feature according to your specific needs and preferences
                            </p>
                            <div className="flex items-center justify-center text-orange-600 font-semibold text-lg group-hover:gap-3 transition-all duration-300">
                                <span>Customize Features</span>
                                <FaCog className="ml-2 group-hover:rotate-90 transition-transform duration-500" />
                            </div>
                            <div className="mt-6 pt-4 border-t border-gray-200">
                                <div className="inline-flex items-center bg-orange-50 rounded-full px-4 py-2 text-sm text-orange-700 font-medium">
                                    <span>Perfect for advanced users</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    onClick={handleDefaultSettings}
                    className="group cursor-pointer transform transition-all duration-500 hover:scale-105 hover:-translate-y-2"
                >
                    <div className="bg-white bg-opacity-95 backdrop-blur-sm border-2 border-teal-200 rounded-3xl p-8 text-center hover:border-teal-500 hover:shadow-2xl transition-all duration-500 h-full relative overflow-hidden">
                        {/* Gradient overlay on hover */}
                        <div className="absolute inset-0 bg-gradient-to-br from-teal-50 to-emerald-50 opacity-0 group-hover:opacity-100 transition-opacity duration-500 rounded-3xl"></div>

                        <div className="relative z-10">
                            <div className="flex justify-center mb-6">
                                <div className="bg-gradient-to-br from-teal-500 to-emerald-600 text-white rounded-full p-7 transition-all duration-500 shadow-xl group-hover:shadow-2xl group-hover:scale-110">
                                    <FaRocket className="text-4xl" />
                                </div>
                            </div>
                            <h3 className="text-2xl font-bold text-gray-800 mb-4 group-hover:text-teal-600 transition-colors duration-300">
                                Default Settings
                            </h3>
                            <p className="text-gray-600 mb-6 leading-relaxed text-base">
                                Use our carefully crafted recommended configuration for optimal performance and lightning-fast setup
                            </p>
                            <div className="flex items-center justify-center text-teal-600 font-semibold text-lg group-hover:gap-3 transition-all duration-300">
                                <span>Quick Start</span>
                                <FaCheckCircle className="ml-2 group-hover:scale-125 transition-transform duration-300" />
                            </div>
                            <div className="mt-6 pt-4 border-t border-gray-200">
                                <div className="inline-flex items-center bg-teal-50 rounded-full px-4 py-2 text-sm text-teal-700 font-medium">
                                    <FaCheckCircle className="mr-2 text-teal-600" />
                                    <span>Recommended for most users</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="text-center">
                <div className="inline-flex items-center bg-white bg-opacity-95 backdrop-blur-sm rounded-full px-6 py-4 text-gray-600 shadow-lg border border-gray-200">
                    <FaCheckCircle className="mr-3 text-teal-600 text-xl" />
                    <span className="font-medium">You can always change these settings later in the dashboard</span>
                </div>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-teal-50 flex flex-col justify-center items-center p-4 sm:p-8 relative overflow-hidden">
            {/* Animated Background Elements */}
            <div className="absolute inset-0 overflow-hidden pointer-events-none">
                <div className="absolute top-20 left-10 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
                <div className="absolute top-40 right-10 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
                <div className="absolute -bottom-8 left-1/2 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
            </div>

            <div className="relative z-10 w-full">
                {loading || isloading ? (
                    <div className="flex flex-col items-center justify-center py-20">
                        <div className="relative">
                            <FaSpinner className="animate-spin text-teal-600 text-6xl" />
                            <div className="absolute inset-0 blur-xl bg-teal-400 opacity-30 animate-pulse"></div>
                        </div>
                        <p className="mt-6 text-gray-600 text-lg font-medium">Loading...</p>
                    </div>
                ) : (
                    <>
                        <VisitSteps visitStep={visitStep} />
                        {visitStep === 'recommended_features' ? (
                            <>
                                {!showCustomSettings ? (
                                    <SettingsSelection />
                                ) : (
                                    <VisitCards isloading={isloading} setShowCustomSettings={setShowCustomSettings} currentFeatures={currentFeatures} totalPages={totalPages} handleParentToggle={handleParentToggle} setCurrentPage={setCurrentPage} featureData={featureData} features={features} currentPage={currentPage} endIndex={endIndex} handleConnectClick={handleConnectClick} connecting={connecting} handleSubmitVisit={handleCustomSubmitVisit} />
                                )}
                            </>
                        ) : visitStep === 'features_implemented' ? (
                            <ProgressStep progressValue={progressValue} />
                        ) : null}
                    </>
                )}
            </div>

            <ToastContainer position="top-center" autoClose={5000} hideProgressBar={false} newestOnTop={false} closeOnClick rtl={false} pauseOnFocusLoss draggable pauseOnHover style={{ width: "auto", minWidth: "200px", maxWidth: "800px" }} />

            <style>{`
        @keyframes blob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }
        .animate-blob {
            animation: blob 7s infinite;
        }
        .animation-delay-2000 {
            animation-delay: 2s;
        }
        .animation-delay-4000 {
            animation-delay: 4s;
        }
    `}</style>
        </div>
    );
};

export default VisitFeatures;