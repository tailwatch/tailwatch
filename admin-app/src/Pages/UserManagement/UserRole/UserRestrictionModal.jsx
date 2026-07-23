import React, { useState, useEffect } from "react";
import { useForm, Controller } from "react-hook-form";
import { toast } from 'react-toastify';
import PopUpModal from "../../../Components/Modal/PopUpModal";
import ToggleSwitch from "../../../Components/ToggleSwitcher/ToggleSwitch";
import { updateRestrictLogin } from '../MangementServices/ManagementServices';
import { FiClock, FiCalendar, FiLock, FiLink } from "react-icons/fi";
import InputField from "../../../Components/Fields/InputField";
import { UserX } from "lucide-react";
const expiryOptions = [
    { value: "hour", label: "1 Hour" },
    { value: "3_hours", label: "3 Hours" },
    { value: "day", label: "Day" },
    { value: "3_days", label: "3 Days" },
    { value: "week", label: "Week" },
    { value: "month", label: "Month" },
    { value: "custom", label: "Custom Date" },
];

const redirectOptions = [
    { value: "wp_dashboard", label: "Admin Dashboard" },
    { value: "home", label: "Home Page" },
];

const UserRestrictionModal = ({ show, handleClose, user, onSave, fetchUserDataSet }) => {
    const [loading, setLoading] = useState(false);
    const isDateTime = (str) => {
        return /\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(str);
    };
    const userExpiryStatus = user?.user_expiry_status?.data || {
        login_enabled: false,
        expiry_time: "",
        max_login_limit: 50,
        redirect_to: "wp_dashboard"
    };

    const { register, handleSubmit, control, watch, formState: { errors }, setValue, clearErrors } = useForm({
        defaultValues: {
            login_enable: userExpiryStatus.login_enabled,
            expiry_time: isDateTime(userExpiryStatus.expiry_time) ? "custom" : userExpiryStatus.expiry_time || "",
            custom_expiry_date: isDateTime(userExpiryStatus.expiry_time) ? userExpiryStatus.expiry_time.split(" ")[0] : "",
            max_login_limit: userExpiryStatus.max_login_limit,
            redirect_to: userExpiryStatus.redirect_to || "",
        },
    });

    const loginEnable = watch("login_enable");
    const expiryTime = watch("expiry_time");

    useEffect(() => {
        if (expiryTime !== "custom") {
            clearErrors("custom_expiry_date");
            setValue("custom_expiry_date", "");
        }
    }, [expiryTime, clearErrors, setValue]);

    const onSubmit = async (data) => {
        setLoading(true);
        try {
            let success = false;

            const payload = { user_id: user.id, ...data, expiry_time: data.expiry_time === "custom" ? data.custom_expiry_date : data.expiry_time };
            delete payload.custom_expiry_date;
            const response = await updateRestrictLogin(payload);
            if (response.success) {
                toast.success("User restrictions updated successfully!");
                handleClose();
                fetchUserDataSet();
                success = true;
            } else {
                toast.error(response.message || "Failed to update user restrictions.");
            }
            setLoading(false);
        } catch (error) {
            setLoading(false);
            toast.error("An error occurred while updating user details.");
        }
    };

    if (!show) return null;

    const getRestrictionBlockMessage = () => {
        const status = user?.user_expiry_status;
        if (!status || status.license_connected === false) {
            return "Connect to Tailwatch to use this feature.";
        }
        if (status.pro_plugin_active === false) {
            return "Pro plugin is required to use this feature.";
        }
        if (status.data && (status.data.feature_status === false || status.data.parent_enable === false)) {
            return "Enable the User Management feature first.";
        }
        return null;
    };

    const blockMessage = getRestrictionBlockMessage();

    if (blockMessage) {
        return (
            <PopUpModal title="User Restriction" onClose={handleClose} cancelButtonText="Close" width="w-[50rem]">
                <div className="flex flex-col items-center justify-center py-10 gap-3 text-center">
                    <FiLock className="w-10 h-10 text-gray-400" />
                    <p className="text-gray-600 text-sm">{blockMessage}</p>
                </div>
            </PopUpModal>
        );
    }

    return (
        <PopUpModal title="User Restriction" isLoading={loading} disabled={loading} cancelButtonText='Cancel' onSave={handleSubmit(onSubmit)} onClose={handleClose} saveButtonText="Save" width="w-[50rem]">
            <div className="bg-white p-1">
                <h2 className="mb-6 text-xl font-semibold text-gray-900">
                    Restrict <span className="text-[#007980]">{user.username}</span>
                </h2>
                <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
                    <div className="flex items-center justify-between py-3 border-b border-gray-200">
                    <label className="flex items-center mb-1 text-sm font-medium text-gray-700">
                    <FiLock className="mr-1.5 w-4 h-4 text-gray-500" />Enable Login Restriction</label>
                        <Controller name="login_enable" control={control} render={({ field }) => (
                            <ToggleSwitch {...field} checked={field.value} className="w-10 h-5 bg-gray-300 rounded-full transition-colors duration-200 checked:bg-indigo-600" />
                        )}
                        />
                    </div>

                    <div>
                        <label className="flex items-center mb-1 text-sm font-medium text-gray-700">
                            <FiClock className="mr-1.5 w-4 h-4 text-gray-500" /> Expiry Time
                        </label>
                        <select {...register("expiry_time", { required: loginEnable && "Expiry time is required" })}
                            className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none !focus:ring-[1px] !focus:ring-[#007980] ${!loginEnable ? "!bg-gray-50 !text-gray-400 !cursor-not-allowed" : "!bg-white"}`}
                            disabled={!loginEnable}
                        >
                            <option value="">Select Expiry Time</option>
                            {expiryOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {errors.expiry_time && (
                            <p className="mt-1 text-xs text-red-500">{errors.expiry_time.message}</p>
                        )}
                    </div>

                    {expiryTime === "custom" && (
                        <div>
                            <label className="flex items-center mb-1 text-sm font-medium text-gray-700">
                            <FiCalendar className="!mr-1.5 !w-4 !h-4 !text-gray-500" />Custom Expiry Date</label>
                            <input type="date" className={`!block !w-full !p-2 !border !border-gray-300 !rounded-md !shadow-sm focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] ${!loginEnable ? "!bg-gray-50 !text-gray-400 !cursor-not-allowed" : "!bg-white"}`}
                                disabled={!loginEnable}
                                min={new Date().toISOString().split("T")[0]}
                                {...register("custom_expiry_date", {
                                    required: expiryTime === "custom" && "Custom date is required",
                                    validate: (value) => {
                                        if (expiryTime === "custom") {
                                            const selectedDate = new Date(value);
                                            const today = new Date();
                                            today.setHours(0, 0, 0, 0);
                                            return selectedDate >= today || "Date must be today or in the future";
                                        }
                                        return true;
                                    },
                                })}
                            />
                            {errors.custom_expiry_date && (
                                <p className="mt-1 text-xs text-red-500">{errors.custom_expiry_date.message}</p>
                            )}
                        </div>
                    )}

                    <div>
                    <label className="flex items-center mb-1 text-sm font-medium text-gray-700">
                    <UserX className="mr-1.5 w-4 h-4 text-gray-500" />Max Login Limit</label>
                    <InputField label="Enter Max Login Limit" id="max_login_limit" type="number" disabled={!loginEnable} inputProps={{...register("max_login_limit", { required: "Maximum login limit is required", min: { value: 1, message: "Must be at least 1" }, max: { value: 1000, message: "Cannot exceed 100" }, valueAsNumber: true, })}} className={`!w-full !border !rounded-lg !p-2 !mt-1 ${errors.user_last_name ? "border-red-500" : "border-gray-300"}`}  />                                                            
                    {errors.max_login_limit && (
                        <p className="text-red-500 text-sm">{errors.max_login_limit.message}</p>
                    )}
                        {/* <input type="number" className={`!w-full !py-2 !px-3 !text-sm !border !border-gray-300 !rounded-md focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] !transition-colors !duration-150 ${!loginEnable ? "!bg-gray-50 !text-gray-400 !cursor-not-allowed" : "!bg-white"}`}
                            placeholder="Enter Max Login Limit"
                            disabled={!loginEnable}
                            {...register("max_login_limit", { required: loginEnable && "Maximum login limit is required", min: { value: 1, message: "Must be at least 1" }, max: { value: 1000, message: "Cannot exceed 1000" }, valueAsNumber: true, })}
                            onInput={(e) => { if (e.target.value < 1) e.target.value = ""; }}
                        />
                        {errors.max_login_limit && (
                            <p className="mt-1 text-xs text-red-500">{errors.max_login_limit.message}</p>
                        )} */}
                    </div>

                    <div>
                    <label className="flex items-center mb-1 text-sm font-medium text-gray-700">
                    <FiLink className="mr-1.5 w-4 h-4 text-gray-500" />Redirect To</label>
                        <select {...register("redirect_to", { required: loginEnable && "Redirect destination is required" })} className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-[1px] focus:ring-[#007980] ${!loginEnable ? "!bg-gray-50 !text-gray-400 !cursor-not-allowed" : "!bg-white"}`} disabled={!loginEnable} >
                            <option value="">Select Redirect Page</option>
                            {redirectOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {errors.redirect_to && (
                            <p className="mt-1 text-xs text-red-500">{errors.redirect_to.message}</p>
                        )}
                    </div>
                </form>
            </div>
        </PopUpModal>
    );
};

export default UserRestrictionModal;