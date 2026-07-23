import React, { useState, useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "react-toastify";
import PopUpModal from "../../../Components/Modal/PopUpModal";
import { createUser, updateUser } from "../MangementServices/ManagementServices";

const CreateEditUserModal = ({ show, handleClose, user, roles, fetchUserDataSet }) => {
    const [loading, setLoading] = useState(false);
    const isEditMode = !!user;

    const { register, handleSubmit, reset, formState: { errors } } = useForm({
        defaultValues: {
            username: "",
            email: "",
            password: "",
            role: "",
            first_name: "",
            last_name: "",
            display_name: "",
            nickname: "",
            website: "",
            description: "",
            send_notification: false,
        },
    });

    useEffect(() => {
        if (show) {
            reset({
                username: user?.username || "",
                email: user?.email || "",
                password: "",
                role: user?.role || "",
                first_name: user?.first_name || "",
                last_name: user?.last_name || "",
                display_name: user?.display_name || "",
                nickname: user?.nickname || "",
                website: user?.website || "",
                description: user?.description || "",
                send_notification: user?.send_notification || false,
            });
        }
    }, [show, user, reset]);

    const onSubmit = async (data) => {
        setLoading(true);
        try {
            const payload = { ...data };
            if (isEditMode) {
                payload.user_id = user.id;
                if (!payload.password) delete payload.password;
            }
            const response = isEditMode ? await updateUser(payload) : await createUser(payload);
            const isSuccess = response?.data?.success === true || response?.data?.code === 200 || response?.data?.code === 201;
            if (isSuccess) {
                toast.success(response?.data?.message || (isEditMode ? "User updated successfully!" : "User created successfully!"));
                handleClose();
                fetchUserDataSet();
            } else {
                toast.error(response?.data?.message || `Failed to ${isEditMode ? "update" : "create"} user.`);
            }
        } catch (error) {
            toast.error(`An error occurred while ${isEditMode ? "updating" : "creating"} user.`);
        } finally {
            setLoading(false);
        }
    };

    if (!show) return null;

    const inputClass = "!w-full !border !border-gray-300 !rounded-lg !p-2 !mt-1 !bg-white focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] text-sm";
    const labelClass = "block text-sm font-medium text-gray-700 mb-1";
    const errorClass = "mt-1 text-xs text-red-500";

    return (
        <PopUpModal
            title={isEditMode ? "Edit User" : "Create User"}
            isLoading={loading}
            cancelButtonText="Cancel"
            onSave={handleSubmit(onSubmit)}
            onClose={handleClose}
            saveButtonText={isEditMode ? "Update" : "Create"}
            width="w-[55rem]"
        >
            <div className="bg-white p-1">                
                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label className={labelClass}>Username {!isEditMode && <span className="text-red-500">*</span>}</label>
                            <input
                                className={inputClass}
                                disabled={isEditMode}
                                placeholder="Enter username"
                                {...register("username", { required: !isEditMode && "Username is required" })}
                            />
                            {errors.username && <p className={errorClass}>{errors.username.message}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Email <span className="text-red-500">*</span></label>
                            <input
                                className={inputClass}
                                type="email"
                                placeholder="Enter email"
                                {...register("email", {
                                    required: "Email is required",
                                    pattern: { value: /^\S+@\S+\.\S+$/, message: "Invalid email address" },
                                })}
                            />
                            {errors.email && <p className={errorClass}>{errors.email.message}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Password {!isEditMode && <span className="text-red-500">*</span>}</label>
                            <input
                                className={inputClass}
                                type="password"
                                placeholder={isEditMode ? "Leave blank to keep current" : "Enter password"}
                                {...register("password", { required: !isEditMode && "Password is required" })}
                            />
                            {errors.password && <p className={errorClass}>{errors.password.message}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Role <span className="text-red-500">*</span></label>
                            <select
                                className={inputClass}
                                {...register("role", { required: "Role is required" })}
                            >
                                <option value="">Select Role</option>
                                {roles && (Array.isArray(roles)
                                    ? roles.map((r) => (
                                        <option key={r.role_key} value={r.role_key}>{r.role_name}</option>
                                    ))
                                    : Object.entries(roles).map(([key, label]) => (
                                        <option key={key} value={key}>{typeof label === 'string' ? label : key}</option>
                                    ))
                                )}
                            </select>
                            {errors.role && <p className={errorClass}>{errors.role.message}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>First Name</label>
                            <input className={inputClass} placeholder="Enter first name" {...register("first_name")} />
                        </div>
                        <div>
                            <label className={labelClass}>Last Name</label>
                            <input className={inputClass} placeholder="Enter last name" {...register("last_name")} />
                        </div>
                        <div>
                            <label className={labelClass}>Display Name</label>
                            <input className={inputClass} placeholder="Enter display name" {...register("display_name")} />
                        </div>
                        <div>
                            <label className={labelClass}>Nickname</label>
                            <input className={inputClass} placeholder="Enter nickname" {...register("nickname")} />
                        </div>
                        <div>
                            <label className={labelClass}>Website</label>
                            <input className={inputClass} type="url" placeholder="https://example.com" {...register("website")} />
                        </div>
                        <div>
                            <label className={labelClass}>Description</label>
                            <input className={inputClass} placeholder="Short bio or description" {...register("description")} />
                        </div>
                    </div>
                    <div className="flex items-center gap-2 pt-1">
                        <input
                            type="checkbox"
                            id="send_notification"
                            className="w-4 h-4 text-[#007980] border-gray-300 rounded"
                            {...register("send_notification")}
                        />
                        <label htmlFor="send_notification" className="text-sm text-gray-700">
                            Send notification email to new user
                        </label>
                    </div>
                </form>
            </div>
        </PopUpModal>
    );
};

export default CreateEditUserModal;
