import React, { useState } from "react";
import { IoMdClose } from "react-icons/io";
import { IoExpand, IoContract } from "react-icons/io5";
import { FaLock, FaBell } from "react-icons/fa";
import { PiWarningCircleBold } from "react-icons/pi";
import PopUpModal from "../../../Components/Modal/PopUpModal";
import ToggleSwitch from "../../../Components/ToggleSwitcher/ToggleSwitch";
const IOSToggle = ({ checked, onChange, disabled = false, loading = false }) => (
    <div
        className={`relative w-12 h-7 rounded-full p-1 transition-colors duration-200 ease-in-out ${
            loading ? 'cursor-not-allowed opacity-70' : disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
        } ${checked ? 'bg-[#0891b2]' : 'bg-gray-300'}`}
        onClick={() => !disabled && !loading && onChange({ target: { checked: !checked } })}
    >
        {loading ? (
            <div className={`w-5 h-5 rounded-full flex items-center justify-center transform transition-transform duration-200 ease-in-out ${checked ? 'translate-x-5' : 'translate-x-0'}`}>
                <svg className="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
            </div>
        ) : (
            <div
                className={`w-5 h-5 bg-white rounded-full shadow-md transform transition-transform duration-200 ease-in-out ${
                    checked ? 'translate-x-5' : 'translate-x-0'
                }`}
            />
        )}
    </div>
);
const NotificationModal = ({ selectedFeature, featureIcon, toggleStates, setToggleStates, pushNotificationUpdate, onClose }) => {
    const [isExpanded, setIsExpanded] = useState(false);
    // Track loading state per-toggle so multiple toggles can be in flight at the same time
    // and each spinner shows independently while its own API call is pending.
    const [loadingIds, setLoadingIds] = useState({});
    const isLoadingId = (id) => !!loadingIds[id];
    const parsedValue = JSON.parse(selectedFeature?.value || "{}");
    const options = parsedValue.options || {};
    const activeOptions = Object.keys(options).filter(
        (key) => options[key].values?.option?.selected && options[key].push_notification !== undefined
    );

    const totalOptions = activeOptions.length;
    const enabledCount = activeOptions.filter((key) => {
        const field = options[key];
        return toggleStates[field.id] !== undefined ? toggleStates[field.id] : field.push_notification;
    }).length;

    const handleToggleExpand = () => {
        setIsExpanded(!isExpanded);
    };

    const renderNotificationContent = () => {
        // Feature not active
        if (selectedFeature.is_active === "0") {
            return (
                <div className="text-center py-12 px-6">
                    <div className="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <FaLock className="text-red-500 text-2xl" />
                    </div>
                    <h3 className="text-lg font-semibold text-gray-800 mb-2">
                        Feature Not Active
                    </h3>
                    <p className="text-gray-600 mb-6">
                        This feature is currently deactivated. Please activate the feature first to configure notifications.
                    </p>
                </div>
            );
        }

        // No notification options available
        if (selectedFeature.is_active === "1" && activeOptions.length === 0) {
            return (
                <div className="text-center py-12 px-6">
                    <div className="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <PiWarningCircleBold className="text-yellow-600 text-2xl" />
                    </div>
                    <h3 className="text-lg font-semibold text-gray-800 mb-2">
                        No Notification Options Available
                    </h3>
                    <p className="text-gray-600 mb-6">
                        You need to configure this feature first before you can set up notifications.
                    </p>
                </div>
            );
        }

        // Render notification options
        return (
            <div className="divide-y divide-gray-200">
                {activeOptions.map((key) => {
                    const field = options[key];
                    const isToggled = toggleStates[field.id] !== undefined ? toggleStates[field.id] : field.push_notification;

                    return (
                        <div key={field.id} className={`p-6 transition-all duration-200 hover:bg-gray-50 ${isToggled ? 'bg-blue-50 border-l-4 border-[#0891b2]' : ''}`}>
                            <div className="flex items-center justify-between">
                                <div className="flex-1 pr-4">
                                    <div className="flex items-center space-x-2 mb-1">
                                        <FaBell className={`text-sm ${isToggled ? 'text-[#0891b2]' : 'text-gray-400'}`} />
                                        <label className="text-base font-medium text-gray-800">
                                            {field?.push_notification_title || field?.label}
                                        </label>
                                    </div>
                                    <p className="text-sm text-gray-600 leading-relaxed">
                                        {field?.push_notification_description || field?.description}
                                    </p>
                                </div>

                                {/* Replace ToggleSwitch with any of the options above */}
                                <IOSToggle
                                    checked={isToggled}
                                    disabled={field.disabled}
                                    loading={isLoadingId(field.id)}
                                    onChange={async (e) => {
                                        // Block re-clicking the same toggle while it's already executing,
                                        // but let the user trigger other toggles in parallel.
                                        if (field.disabled || isLoadingId(field.id)) return;

                                        const nextChecked = e.target.checked;
                                        setToggleStates((prev) => ({ ...prev, [field.id]: nextChecked }));
                                        setLoadingIds((prev) => ({ ...prev, [field.id]: true }));
                                        // Hold the spinner on screen for a beat so the user always sees the
                                        // loading state, even when the backend responds almost instantly.
                                        const minDelay = new Promise((resolve) => setTimeout(resolve, 400));
                                        try {
                                            await Promise.all([
                                                pushNotificationUpdate(selectedFeature.id, field.id, nextChecked),
                                                minDelay,
                                            ]);
                                        } finally {
                                            setLoadingIds((prev) => {
                                                const next = { ...prev };
                                                delete next[field.id];
                                                return next;
                                            });
                                        }
                                    }}
                                />
                            </div>
                        </div>
                    );
                })}
            </div>
        );
    };

    // Custom gradient header for notification modal
    const customHeader = (
        <div className="bg-gradient-to-r from-[#0891b2] to-[#0e7490] text-white relative px-10 pt-8 py-6">

            <div className="absolute top-4 right-4 m-4 flex items-center space-x-2">
                <button onClick={handleToggleExpand} className="bg-white bg-opacity-20 hover:bg-opacity-30 p-2 rounded-lg transition-all duration-200">
                    {isExpanded ? (
                        <IoContract className="text-xl" title="Collapse" />
                    ) : (
                        <IoExpand className="text-xl" title="Expand" />
                    )}
                </button>
                <button onClick={onClose} className="bg-white bg-opacity-20 hover:bg-opacity-30 p-2 rounded-lg transition-all duration-200">
                    <IoMdClose className="text-xl" />
                </button>
            </div>

            {/* Main content area with proper spacing from buttons */}
            <div className="pr-20">
                <div className="flex items-center space-x-3 mb-2">
                    <span className="text-2xl">{featureIcon}</span>
                    <div>
                        <h2 className="text-xl !text-white font-semibold">
                            {selectedFeature?.name || selectedFeature?.title} Real-time Notifications
                        </h2>
                        {totalOptions > 0 && (
                            <div className="flex items-center mt-1">
                                <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${enabledCount > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                                    }`}>
                                    {enabledCount} of {totalOptions} enabled
                                </span>
                            </div>
                        )}
                    </div>
                </div>
                <p className="text-blue-100 text-sm">
                    Enable and customize your mobile push notifications
                </p>
            </div>
        </div>
    );

    return (
        <PopUpModal onClose={onClose} width={`w-full ${isExpanded ? 'max-w-6xl' : 'max-w-2xl'}`} notificationModal={true} showCloseIcon={false} showExpandIcon={true} customHeader={customHeader}>
            {renderNotificationContent()}
        </PopUpModal>
    );
};

export default NotificationModal;