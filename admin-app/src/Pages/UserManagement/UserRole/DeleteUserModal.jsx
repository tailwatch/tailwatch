import React, { useState, useEffect } from "react";
import { toast } from "react-toastify";
import PopUpModal from "../../../Components/Modal/PopUpModal";
import { getUserContentSummary, deleteUser } from "../MangementServices/ManagementServices";
import { alertService } from "../../../Components/AlertService/AlertService";
import { Spinner } from "../../../Components/Spinner/Spinner";
import UserSearchDropdown from "./UserSearchDropdown";

const DeleteUserModal = ({ show, handleClose, user, fetchUserDataSet }) => {
    const [loading, setLoading] = useState(false);
    const [fetching, setFetching] = useState(false);
    const [summary, setSummary] = useState(null);
    const [reassignOption, setReassignOption] = useState("delete");
    const [reassignId, setReassignId] = useState("");

    useEffect(() => {
        if (!show || !user) return;
        setSummary(null);
        setReassignOption("delete");
        setReassignId("");
        setFetching(true);
        getUserContentSummary(user.id)
            .then((res) => {
                const data = res?.data?.data ?? res?.data ?? null;
                setSummary(data);
            })
            .catch(() => toast.error("Failed to fetch user content summary."))
            .finally(() => setFetching(false));
    }, [show, user]);

    if (!show || !user) return null;

    const hasContent = summary?.has_content === true;
    const contentSummary = Array.isArray(summary?.content_summary) ? summary.content_summary : [];

    const handleDelete = async () => {
        if (hasContent) {
            if (reassignOption === "reassign" && !reassignId) {
                toast.error("Please select a user to reassign content to.");
                return;
            }
        } else {
            const confirmMsg = `<div style="line-height:1.8"><p>Delete user <strong>${user.username}</strong>?</p><p>This action cannot be undone.</p></div>`;
            const confirmed = await alertService.optimizationPopup(confirmMsg, "Confirm Delete", "Delete", "Cancel");
            if (!confirmed) return;
        }

        setLoading(true);
        try {
            const payload = { user_id: user.id };
            if (hasContent && reassignOption === "reassign" && reassignId) {
                payload.reassign_id = Number(reassignId);
            }
            const response = await deleteUser(payload);
            const isSuccess = response?.data?.success === true || response?.data?.code === 200;
            if (isSuccess) {
                toast.success(response?.data?.message || "User deleted successfully!");
                handleClose();
                fetchUserDataSet();
            } else {
                toast.error(response?.data?.message || "Failed to delete user.");
            }
        } catch (error) {
            toast.error("An error occurred while deleting the user.");
        } finally {
            setLoading(false);
        }
    };

    return (
        <PopUpModal
            title="Delete User"
            isLoading={loading}
            cancelButtonText="Cancel"
            onSave={handleDelete}
            onClose={handleClose}
            saveButtonText="Delete User"
            width="w-[50rem]"
            disabled={fetching}
        >
            <div className="bg-white p-1 space-y-5">
                {fetching ? (
                    <div className="flex flex-col items-center justify-center py-10 gap-3">
                        <Spinner />
                        <p className="text-sm text-gray-500">Checking user content...</p>
                    </div>
                ) : !hasContent ? (
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-gray-700">
                        <p>You are about to delete <strong className="text-red-600">{user.username}</strong>.</p>
                        <p className="mt-1 text-gray-500">This user has no content. This action cannot be undone.</p>
                    </div>
                ) : (
                    <>
                        <div>
                            <p className="text-sm font-medium text-gray-700 mb-3">
                                This user owns content that will be affected:
                            </p>
                            <table className="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                                <thead>
                                    <tr className="bg-gray-50 text-gray-600 text-left">
                                        <th className="px-4 py-2 font-medium">Content Type</th>
                                        <th className="px-4 py-2 font-medium text-center">Total</th>
                                        <th className="px-4 py-2 font-medium">By Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {contentSummary.map((item) => (
                                        <tr key={item.post_type} className="text-gray-700">
                                            <td className="px-4 py-2 font-medium">{item.label}</td>
                                            <td className="px-4 py-2 text-center font-semibold text-gray-900">{item.total}</td>
                                            <td className="px-4 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    {Object.entries(item.by_status).map(([status, count]) => (
                                                        <span key={status} className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100">
                                                            {status}: {count}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="bg-gray-50 font-semibold text-gray-800 border-t-2 border-gray-300">
                                        <td className="px-4 py-2">Total</td>
                                        <td className="px-4 py-2 text-center text-[#007980]">
                                            {contentSummary.reduce((sum, item) => sum + item.total, 0)}
                                        </td>
                                        <td className="px-4 py-2 text-xs text-gray-400">items</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <p className="text-sm font-medium text-gray-700 mb-3">What should happen to this content?</p>
                            <div className="space-y-3">
                                {/* Custom radio — Reassign */}
                                <div
                                    className="flex items-start gap-3 cursor-pointer"
                                    onClick={() => setReassignOption("reassign")}
                                >
                                    <div className="mt-0.5 shrink-0 !w-[18px] !h-[18px] !min-w-[18px] !rounded-full !border-2 !border-solid flex items-center justify-center"
                                        style={{
                                            borderColor: reassignOption === "reassign" ? "#007980" : "#9ca3af",
                                            backgroundColor: "#fff",
                                            boxSizing: "border-box",
                                        }}
                                    >
                                        {reassignOption === "reassign" && (
                                            <div style={{ width: 8, height: 8, borderRadius: "50%", backgroundColor: "#007980", flexShrink: 0 }} />
                                        )}
                                    </div>
                                    <div className="flex-1">
                                        <span className="text-sm text-gray-700">Reassign to:</span>
                                        {reassignOption === "reassign" && (
                                            <div className="mt-2" onClick={(e) => e.stopPropagation()}>
                                                <UserSearchDropdown
                                                    value={reassignId}
                                                    onChange={setReassignId}
                                                    excludeIds={[user.id]}
                                                    placeholder="— Search and select a user —"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Custom radio — Delete all */}
                                <div
                                    className="flex items-center gap-3 cursor-pointer"
                                    onClick={() => { setReassignOption("delete"); setReassignId(""); }}
                                >
                                    <div className="shrink-0 !w-[18px] !h-[18px] !min-w-[18px] !rounded-full !border-2 !border-solid flex items-center justify-center"
                                        style={{
                                            borderColor: reassignOption === "delete" ? "#007980" : "#9ca3af",
                                            backgroundColor: "#fff",
                                            boxSizing: "border-box",
                                        }}
                                    >
                                        {reassignOption === "delete" && (
                                            <div style={{ width: 8, height: 8, borderRadius: "50%", backgroundColor: "#007980", flexShrink: 0 }} />
                                        )}
                                    </div>
                                    <span className="text-sm text-gray-700">Delete all content along with the user</span>
                                </div>
                            </div>
                        </div>

                        {reassignOption === "delete" && (
                            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-800">
                                <strong>Warning:</strong> All content owned by <strong>{user.username}</strong> will be permanently deleted.
                            </div>
                        )}
                    </>
                )}
            </div>
        </PopUpModal>
    );
};

export default DeleteUserModal;
