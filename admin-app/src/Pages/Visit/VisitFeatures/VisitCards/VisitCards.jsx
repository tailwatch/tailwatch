import React, { useState, useRef } from 'react'
import { FiArrowLeft } from "react-icons/fi";
import ToggleSwitch from '../../../../Components/ToggleSwitcher/ToggleSwitch';
import ActionButton from '../../../../Components/Buttons/ActionButton';
import FeatureIconMapper from '../../../Features/FeatureCard/FeatureIconMapper';
import { Crown, Sparkles, ExternalLink } from 'lucide-react';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';

const VisitCards = ({ isloading, setShowCustomSettings, features, handleParentToggle,featureData, handleConnectClick, connecting, handleSubmitVisit }) => {    
    const licenseStatus = featureData?.license_status?.connection;
    const pluginStatus = { status: featureData?.license_status?.pro_plugin_active };

    const [expandedCards, setExpandedCards] = useState({});
    const filteredFeatures = features.filter(feature => {
        const featureValue = JSON.parse(feature.value);
        return featureValue.recommended_feature === true;
    });

    // Create refs and state for each feature icon
    const [iconStates, setIconStates] = useState({});
    const iconRefs = useRef({});

    const handleIconReady = (featureId) => {
        setIconStates(prev => ({ ...prev, [featureId]: false }));
    };
    const toggleExpand = (id) => {
        setExpandedCards(prev => ({
            ...prev,
            [id]: !prev[id]
        }));
    };

    const handleCardMouseEnter = (featureId) => {
        if (iconRefs.current[featureId] && !isloading) {
            iconRefs.current[featureId].play();
        }
    };

    const handleCardMouseLeave = (featureId) => {
        if (iconRefs.current[featureId] && !isloading) {
            iconRefs.current[featureId].stop();
        }
    };

    return (
        <div className="w-full max-w-7xl mx-auto space-y-8">
            {/* Top Bar: Back Button + Save Button */}
            <div className="flex items-center justify-between">
                <button
                    onClick={() => setShowCustomSettings(false)}
                    className="group flex items-center space-x-3 px-6 py-3 bg-white bg-opacity-95 backdrop-blur-sm border-2 border-teal-200 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 hover:bg-teal-50 hover:border-teal-400 hover:-translate-x-1"
                >
                    <div className="p-2 rounded-lg bg-gradient-to-br from-teal-500 to-emerald-600 transition-all duration-300 group-hover:scale-110 shadow-md">
                        <FiArrowLeft className="h-5 w-5 text-white" />
                    </div>
                    <div className="flex flex-col items-start">
                        <span className="text-teal-700 font-semibold text-sm">Back to Settings</span>
                        <span className="text-gray-600 text-xs">Choose Custom or Default options</span>
                    </div>
                </button>

                {filteredFeatures.length > 0 && (
                    <ActionButton
                        defaultText={isloading ? "Installing..." : "Continue to Install"}
                        onClick={handleSubmitVisit}
                        disabled={isloading}
                        className="!px-2 !py-3 !text-sm !font-bold !bg-gradient-to-r !from-teal-500 !to-emerald-600 hover:!from-teal-600 hover:!to-emerald-700 !shadow-lg hover:!shadow-xl !transition-all !duration-300 hover:!scale-105 disabled:!opacity-50 disabled:!cursor-not-allowed"
                    />
                )}
            </div>

            {/* Feature Cards Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                {filteredFeatures.map((feature, index) => {
                    const featureValue = JSON.parse(feature.value);
                    const isActive = feature.is_active === '1';
                    const isPro = featureValue.feature_type === "pro";
                    const isComingSoon = featureValue.comming_soon === true;
                    const isUpgradeFeature = featureValue.is_upgrade_feature === true;
                    const isLockedLike = featureValue.is_locked || isUpgradeFeature;
                    const iconLoading = iconStates[feature.id] !== false;

                    return (
                        <div
                            key={feature.option || index}
                            onMouseEnter={() => handleCardMouseEnter(feature.id)}
                            onMouseLeave={() => handleCardMouseLeave(feature.id)}
                            className={`group relative flex flex-col rounded-2xl transition-all duration-500 hover:scale-105 hover:-translate-y-2 ${
                                isComingSoon
                                    ? 'bg-gradient-to-br from-[#f0fbfb] via-white to-[#e6f7f7] border-2 border-[#85cbcf]/50 hover:border-[#85cbcf] hover:shadow-2xl shadow-md'
                                    : isLockedLike
                                        ? 'bg-gradient-to-br from-gray-50 to-gray-100 border-2 border-gray-300'
                                        : 'bg-white bg-opacity-95 backdrop-blur-sm border-2 border-teal-200 hover:border-teal-400 hover:shadow-2xl shadow-lg'
                                }`}
                        >
                            {/* Decorative blobs for Coming Soon variant — clipped to card shape */}
                            {isComingSoon && (
                                <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                                    <div className="absolute -top-10 -right-10 h-32 w-32 rounded-full bg-[#85cbcf] opacity-25 blur-2xl"></div>
                                    <div className="absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-[#007980] opacity-15 blur-2xl"></div>
                                </div>
                            )}

                            {/* Top-right badge */}
                            {isComingSoon ? (
                                <div className="absolute -top-3 -right-3 z-10">
                                    <div className="bg-gradient-to-r from-[#007980] to-[#0a9aa3] text-white text-xs font-semibold px-3 py-1 rounded-full shadow-lg flex items-center gap-1">
                                        <Sparkles className="w-3 h-3" />
                                        Coming Soon
                                    </div>
                                </div>
                            ) : isUpgradeFeature ? (
                                <div className="absolute -top-3 -right-3 z-10">
                                    <div className="bg-gradient-to-br from-teal-500 to-teal-600 text-white text-xs font-bold px-3 py-1 rounded-full shadow-lg flex items-center gap-1">
                                        <Crown className="w-3 h-3" />
                                        UPGRADE
                                    </div>
                                </div>
                            ) : featureValue.is_locked && (
                                <div className="absolute -top-3 -right-3 z-10">
                                    <div className="bg-gradient-to-br from-teal-500 to-teal-600 text-white text-xs font-bold px-3 py-1 rounded-full shadow-lg flex items-center gap-1">
                                        <Crown className="w-3 h-3" />
                                        PREMIUM
                                    </div>
                                </div>
                            )}

                            <div className="relative flex flex-col flex-grow p-6">
                                {/* Header Section */}
                                <div className="flex items-start space-x-4 mb-6">
                                    <div className="flex-shrink-0">
                                        <span className={`grid h-12 w-12 place-items-center rounded-xl shadow-md transition-all duration-300 group-hover:scale-110 ${
                                            isLockedLike
                                                ? 'bg-gray-200'
                                                : 'bg-gradient-to-br from-teal-100 to-emerald-100 group-hover:from-teal-200 group-hover:to-emerald-200'
                                            }`}>
                                            {isloading || iconLoading ? (
                                                <Skeleton circle width={32} height={32} />
                                            ) : null}
                                            {!isloading && (
                                                <div style={{ display: iconLoading ? 'none' : 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                                    <FeatureIconMapper ref={(el) => (iconRefs.current[feature.id] = el)} title={featureValue.title} isActive={isActive} isPro={isPro} isLocked={featureValue.is_locked} size={25} colorClass={isLockedLike ? 'text-orange-500' : 'text-[#007980]'} onReady={() => handleIconReady(feature.id)} />
                                                </div>
                                            )}
                                        </span>
                                    </div>

                                    <div className="flex-1 min-w-0">
                                        <h3 className={`text-lg font-medium leading-tight mb-2 transition-colors duration-300 ${
                                            isComingSoon
                                                ? 'text-gray-900'
                                                : isLockedLike
                                                    ? 'text-gray-500'
                                                    : 'text-gray-800 group-hover:text-teal-700'
                                            }`}>
                                            {featureValue.title}
                                        </h3>
                                    </div>
                                </div>

                                {/* Description */}
                                <div className="mb-6 flex-grow">
                                    <p className={`text-sm leading-relaxed transition-all duration-300 text-gray-500 ${expandedCards[feature.id] ? '' : 'line-clamp-3'}`}>
                                        {featureValue.description}
                                    </p>

                                    {/* Show More / Less */}
                                    {featureValue.description?.length > 100 && (
                                        <button
                                            onClick={() => toggleExpand(feature.id)}
                                            className={`mt-2 text-xs font-semibold text-teal-600 hover:underline`}
                                        >
                                            {expandedCards[feature.id] ? 'Show Less' : 'Show More'}
                                        </button>
                                    )}
                                </div>

                                {/* Footer Section */}
                                {isComingSoon ? (
                                    <div className="mt-auto pt-4 border-t-2 border-[#85cbcf]/40 flex justify-center">
                                        <a
                                            href="https://wptailwatch.com/roadmap?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=visit_card_roadmap"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-semibold !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline bg-gradient-to-r from-[#007980] to-[#0a9aa3] hover:from-[#006670] hover:to-[#088d97] shadow-md hover:shadow-lg transition-all duration-300"
                                        >
                                            <ExternalLink className="w-4 h-4" />
                                            View Roadmap
                                        </a>
                                    </div>
                                ) : isLockedLike ? (
                                    <div className="mt-auto pt-4 border-t-2 border-gray-300 flex flex-col items-center space-y-3">
                                        {(featureValue.is_locked && pluginStatus?.status === false) ? (
                                            <p className="text-sm font-semibold text-teal-600 text-center">
                                                Install Premium Plugin
                                            </p>
                                        ) : (
                                            <ActionButton defaultText="Upgrade"
                                                isDisabled={connecting} onClick={handleConnectClick} className="!py-2 !px-6 !text-sm !font-semibold !bg-gradient-to-r !from-teal-500 !to-teal-600 hover:!from-teal-600 hover:!to-teal-700 !shadow-lg hover:!shadow-xl !transition-all !duration-300"
                                            />
                                        )}
                                    </div>
                                ) : (
                                    <div className="mt-auto pt-4 border-t-2 border-teal-200 flex items-center justify-between">
                                        <div className="flex items-center space-x-2">
                                            <div className={`w-2 h-2 rounded-full ${feature.is_active === '1' ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'
                                                }`}></div>
                                            <span className={`text-sm font-semibold ${feature.is_active === '1' ? 'text-emerald-600' : 'text-gray-500'
                                                }`}>
                                                {feature.is_active === '1' ? 'Enabled' : 'Disabled'}
                                            </span>
                                        </div>
                                        <ToggleSwitch
                                            checked={feature.is_active === '1'}
                                            onChange={(isActive) => handleParentToggle(feature)}
                                            disabled={feature.is_locked}
                                        />
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

        </div>
    )
}

export default VisitCards