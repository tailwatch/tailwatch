import React, { useState } from "react";
import { useForm } from "react-hook-form";
import { createTempUser, updateTempUser } from '../MangementServices/ManagementServices';
import { toast } from 'react-toastify';
import InputField from '../../../Components/Fields/InputField';
import ActionButton from "../../../Components/Buttons/ActionButton";

const CreatePassLessLoginForm = ({ onClose, setIsSubmitting, fetchUserTempData, roles = {}, languages = {}, redirectSlugs = {}, initialUser = null }) => {
    const { register, handleSubmit, watch, formState: { errors } } = useForm({
        defaultValues: initialUser ? {
            user_email: initialUser.email,
            user_first_name: initialUser.first_name, 
            user_last_name: initialUser.last_name,
            role: initialUser.role,
            redirect_to: initialUser.redirect_to,
            max_login_limit: initialUser.max_login_limit,
            locale: initialUser.locale,
            default_expiry_time: initialUser.expiry_time
                ? ["hour", "3_hours", "day", "3_days", "week", "month"].includes(initialUser.expiry_time)
                    ? initialUser.expiry_time
                    : (new Date(initialUser.expiry_time) > new Date() ? 'custom' : null)
                : null,
            customExpiryDate: initialUser.expiry_time && !initialUser.expiry_time.includes('_days')
                ? initialUser.expiry_time.split(' ')[0]
                : null
        } : {
            locale: "en_US"
        }
    });

    const defaultExpiryTime = watch("default_expiry_time");

    const roleOptions = Object.entries(roles).map(([value, label]) => ({ value, label }));
    const languageOptions = Object.entries(languages).map(([value, label]) => ({ value, label }));
    const redirectOptions = Object.entries(redirectSlugs).map(([value, label]) => ({ value, label }));

    const expiryOptions = [
        { value: "hour", label: "1 Hour" },
        { value: "3_hours", label: "3 Hours" },
        { value: "day", label: "1 Day" },
        { value: "3_days", label: "3 Days" },
        { value: "week", label: "1 Week" },
        { value: "month", label: "1 Month" },
        { value: "custom", label: "Custom Date" },
    ];

    const onFormSubmit = async (data) => {
        const { default_expiry_time, customExpiryDate, ...rest } = data;

        const finalData = {
            ...rest,  // ✅ rest now contains user_first_name & user_last_name directly
            expiry_time: default_expiry_time === "custom" ? customExpiryDate : default_expiry_time
        };

        if (initialUser && initialUser.user_id) {
            finalData.user_id = initialUser.user_id;
        }

        setIsSubmitting(true);
        try {
            const response = initialUser ? await updateTempUser(finalData) : await createTempUser(finalData);

            if (response && response.success) {
                toast.success(initialUser ? "PasswordLess user updated successfully!" : "PasswordLess user created successfully!");
                if (onClose) { onClose(); fetchUserTempData(); }
            } else {
                toast.error(response?.data?.message || "Failed to save PasswordLess user");
            }
        } catch (error) {
            console.error(initialUser ? "Error updating PasswordLess user:" : "Error creating PasswordLess user:", error);
            toast.error(initialUser ? "An error occurred while updating the PasswordLess user" : "An error occurred while creating the PasswordLess user");
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <form id="passwordless-form" onSubmit={handleSubmit(onFormSubmit)} className="bg-white rounded-lg w-full mx-auto space-y-6">
            <div className="px-1 py-2 grid grid-cols-1 md:grid-cols-2 gap-4">

                <div>
                    <InputField label="First Name" id="user_first_name" type="text"
                        inputProps={{ ...register("user_first_name") }}   // ✅ registered as user_first_name
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 ${errors.user_first_name ? "border-red-500" : "border-gray-300"}`} />
                    {errors.user_first_name && <p className="text-red-500 text-sm">{errors.user_first_name.message}</p>}
                </div>

                <div>
                    <InputField label="Last Name" id="user_last_name" type="text"
                        inputProps={{ ...register("user_last_name") }}    // ✅ registered as user_last_name
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 ${errors.user_last_name ? "border-red-500" : "border-gray-300"}`} />
                    {errors.user_last_name && <p className="text-red-500 text-sm">{errors.user_last_name.message}</p>}
                </div>

                <div>
                    <InputField label="Email" id="user_email" type="email"
                        inputProps={{ ...register("user_email", { required: "Email is required", pattern: { value: /^[^@ ]+@[^@ ]+\.[^@ .]{2,}$/, message: "Invalid email address" } }) }}
                        error={errors.user_email?.message} />
                </div>

                <div>
                    <label htmlFor="role" className="block text-sm font-medium text-black">Role</label>
                    <select id="role" {...register("role", { required: "Please select a role" })}
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-[1px] focus:ring-[#007980] ${errors.role ? "border-red-500" : "border-gray-300"}`}>
                        <option value="" disabled>Select Role</option>
                        {roleOptions.map((option) => (<option key={option.value} value={option.value}>{option.label}</option>))}
                    </select>
                    {errors.role && <p className="text-red-500 text-sm">{errors.role.message}</p>}
                </div>

                <div>
                    <label htmlFor="redirect_to" className="block text-sm font-medium text-black">Redirect After Login</label>
                    <select id="redirect_to" {...register("redirect_to", { required: "Please select a redirect option" })}
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-[1px] focus:ring-[#007980] ${errors.redirect_to ? "border-red-500" : "border-gray-300"}`}>
                        <option value="" disabled>Select Redirect</option>
                        {redirectOptions.map((option) => (<option key={option.value} value={option.value}>{option.label}</option>))}
                    </select>
                    {errors.redirect_to && <p className="text-red-500 text-sm">{errors.redirect_to.message}</p>}
                </div>

                <div>
                    <label htmlFor="locale" className="block text-sm font-medium text-black">Language</label>
                    <select id="locale" {...register("locale", { required: "Please select a language" })}
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-[1px] focus:ring-[#007980] ${errors.language ? "border-red-500" : "border-gray-300"}`}>
                        <option value="" disabled>Select Language</option>
                        {languageOptions.map((option) => (<option key={option.value} value={option.value}>{option.label}</option>))}
                    </select>
                    {errors.language && <p className="text-red-500 text-sm">{errors.language.message}</p>}
                </div>

                <div>
                    <label htmlFor="default_expiry_time" className="block text-sm font-medium text-black">Expiry</label>
                    <div className="flex items-center gap-4">
                        <select id="default_expiry_time" {...register("default_expiry_time", { required: "Please select an expiry option" })}
                            className={`!w-full !border !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-[1px] focus:ring-[#007980] ${errors.default_expiry_time ? "border-red-500" : "border-gray-300"}`}>
                            <option value="" disabled>Select Expiry</option>
                            {expiryOptions.map((option) => (<option key={option.value} value={option.value}>{option.label}</option>))}
                        </select>
                        {defaultExpiryTime === "custom" && (
                            <input type="date" id="customExpiryDate" className="border rounded-lg p-2 border-gray-300"
                                {...register("customExpiryDate", {
                                    validate: (value, formValues) => {
                                        if (formValues.default_expiry_time === "custom") {
                                            if (!value) return "Custom expiry date is required";
                                            const today = new Date();
                                            today.setHours(0, 0, 0, 0);
                                            if (new Date(value) < today) return "Expiry date cannot be in the past";
                                        }
                                        return true;
                                    },
                                })} />
                        )}
                    </div>
                    {errors.default_expiry_time && <p className="text-red-500 text-sm mt-1">{errors.default_expiry_time.message}</p>}
                    {errors.customExpiryDate && <p className="text-red-500 text-sm mt-1">{errors.customExpiryDate.message}</p>}
                </div>

                <div>
                    <InputField label="Maximum Login Limit" id="max_login_limit" type="number"
                        inputProps={{ ...register("max_login_limit", { required: "Maximum login limit is required", min: { value: 1, message: "Must be at least 1" }, max: { value: 1000, message: "Cannot exceed 1000" }, valueAsNumber: true }) }}
                        className={`!w-full !border !rounded-lg !p-2 !mt-1 ${errors.max_login_limit ? "border-red-500" : "border-gray-300"}`} />
                    {errors.max_login_limit && <p className="text-red-500 text-sm">{errors.max_login_limit.message}</p>}
                </div>

            </div>
        </form>
    );
};

export default CreatePassLessLoginForm;