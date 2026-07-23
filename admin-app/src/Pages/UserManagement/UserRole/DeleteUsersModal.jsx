import React, { useState } from "react";
import { toast } from "react-toastify";
import PopUpModal from "../../../Components/Modal/PopUpModal";
import { bulkDeleteUsers } from "../MangementServices/ManagementServices";
import { alertService } from "../../../Components/AlertService/AlertService";

const DeleteUsersModal = ({ show, handleClose, selectedUsers, allUsers, fetchUserDataSet }) => {
    const [reassignId, setReassignId] = useState("");
    const [loading, setLoading] = useState(false);

    if (!show) return null;

    const handleDelete = async () => {
        const user_ids = selectedUsers.map((u) => u.id);
        const payload = { user_ids, ...(reassignId ? { reassign_id: reassignId } : {}) };

        const selectedUsernames = selectedUsers.map((u) => u.username).join(", ");
        const reassignUser = allUsers.find((u) => String(u.id) === String(reassignId));

        const confirmMessage = reassignId
            ? `<div style="line-height:1.8"><p>You are about to <strong>delete</strong>: <strong>${selectedUsernames}</strong></p><p>Their data will be reassigned to <strong>${reassignUser?.username || reassignId}</strong>.</p><p>Are you sure?</p></div>`
            : `<div style="line-height:1.8"><p>You are about to <strong>permanently delete</strong>: <strong>${selectedUsernames}</strong></p><p>Their data will <strong>not</strong> be reassigned.</p><p>Are you sure?</p></div>`;

        const confirmed = await alertService.optimizationPopup(confirmMessage, "Confirm Delete", "Yes, Delete", "Cancel");
        if (!confirmed) return;

        setLoading(true);
        try {
            const response = await bulkDeleteUsers(payload);
            const isSuccess = response?.data?.success === true || response?.data?.code === 200;
            if (isSuccess) {
                toast.success(response?.data?.message || "Users deleted successfully!");
                handleClose();
                fetchUserDataSet();
            } else {
                toast.error(response?.data?.message || "Failed to delete users.");
            }
        } catch (error) {
            toast.error("An error occurred while deleting users.");
        } finally {
            setLoading(false);
        }
    };

    const availableForReassign = allUsers.filter(
        (u) => !selectedUsers.some((s) => s.id === u.id)
    );

    return (
        <PopUpModal
            title="Delete Users"
            isLoading={loading}
            cancelButtonText="Cancel"
            onSave={handleDelete}
            onClose={handleClose}
            saveButtonText="Delete"
            width="w-[50rem]"
        >
            <div className="bg-white p-1 space-y-5">
                <div>
                    <p className="text-sm text-gray-700 font-medium mb-2">
                        The following {selectedUsers.length} user(s) will be deleted:
                    </p>
                    <ul className="bg-red-50 border border-red-200 rounded-lg divide-y divide-red-100 max-h-40 overflow-y-auto">
                        {selectedUsers.map((u) => (
                            <li key={u.id} className="px-4 py-2 text-sm text-gray-800 flex items-center gap-2">
                                <span className="font-medium text-red-600">{u.username}</span>
                                <span className="text-gray-400">—</span>
                                <span className="text-gray-500">{u.email}</span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div>
                    <p className="text-sm font-medium text-gray-700 mb-1">
                        Reassign their content to (optional):
                    </p>
                    <p className="text-xs text-gray-500 mb-2">
                        If selected, all posts and data from the deleted users will be transferred to this user.
                    </p>
                    <select
                        value={reassignId}
                        onChange={(e) => setReassignId(e.target.value)}
                        className="!w-full !border !border-gray-300 !rounded-lg !p-2 !bg-white focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] text-sm"
                    >
                        <option value="">— Do not reassign —</option>
                        {availableForReassign.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.username} ({u.email})
                            </option>
                        ))}
                    </select>
                </div>

                {!reassignId && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-800">
                        <strong>Warning:</strong> No reassignment selected. All content belonging to the deleted users will be permanently removed.
                    </div>
                )}
            </div>
        </PopUpModal>
    );
};

export default DeleteUsersModal;
