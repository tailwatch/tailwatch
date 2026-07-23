import React, { useRef } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal.jsx';
import { Loader2, XCircle } from 'lucide-react';

const ViewHistoryModal = ({
    isOpen,
    onClose,
    activeTab,
    historyData,
    historyLoading,
    hasMoreHistory,
    isLoadingMore,
    formatActionType,
    onScroll
}) => {
    const historyScrollRef = useRef(null);


    if (!isOpen) return null;

    const getModalTitle = () => {
        switch (activeTab) {
            case 'theme':
                return 'Theme History';
            case 'plugin':
                return 'Plugin History';
            default:
                return 'Core History';
        }
    };

    return (
        <PopUpModal
            title={getModalTitle()}
            onClose={onClose}
            loading={historyLoading}
            cancelButtonText="Close"
            width="w-full max-w-2xl"
            showExpandIcon={true}
        >
            <div
                ref={historyScrollRef}
                onScroll={onScroll}
                className="max-h-[60vh] overflow-y-auto custom-scroll-bar"
            >
                {historyData.length === 0 && !historyLoading ? (
                    <div className="text-center text-gray-500 py-8">No history found</div>
                ) : (
                    <div className="space-y-3">
                        {historyData.map((item) => (



                            <div key={item.id} className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                <div className="flex items-start justify-between mb-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold text-gray-800">{item.item_name}</span>
                                        {item.action_status !== 'success' && (
                                            <XCircle className="w-4 h-4 text-red-500" />
                                        )}
                                    </div>
                                    <span className="text-xs text-gray-500">{item.date_created}</span>
                                </div>


                                {(item.before_state?.version || item.after_state?.version || item.old_version || item.new_version) && (
                                    <div className="text-sm text-gray-600 mb-1 ">
                                        <span className="text-gray-500">Version: </span>

                                        {(() => {
                                            const action = item.action_type?.toLowerCase() || '';
                                            const isActivate = action.includes('activate');
                                            const isInstall = action.includes('install');
                                            const isFailedRollback = item.before_state?.version === item.after_state?.version && item.action_status === "failed";

                                            if (!isFailedRollback) {
                                                return (
                                                    <>
                                                        {item.before_state?.version && !isInstall && !isActivate && (
                                                            <span className={item.after_state?.version ? 'line-through text-red-500' : 'text-red-500'}>
                                                                {item.before_state.version}
                                                            </span>
                                                        )}

                                                        {item.before_state?.version && item.after_state?.version && !isInstall && !isActivate && (
                                                            <span className="mx-1">→</span>
                                                        )}

                                                        {item.after_state?.version && (
                                                            <span className="text-green-600">{item.after_state.version}</span>
                                                        )}
                                                    </>
                                                );
                                            }

                                            const oldVer = item.metadata?.old_version;
                                            const newVer = item.metadata?.new_version;

                                            if (oldVer === newVer) {
                                                return <span className="text-red-600">{oldVer}</span>;
                                            }
                                            return (
                                                <>
                                                    <span className="text-green-600">{oldVer}</span>
                                                    <span className="mx-1">→</span>
                                                    <span className="text-red-500">{newVer}</span>
                                                    {item.metadata?.error && (
                                                        <div className="text-xs text-red-600 mt-1">{item.metadata.error} <span className='text-green-600'>{oldVer}</span> </div>
                                                    )}
                                                </>
                                            );
                                        })()}
                                    </div>
                                )}



                                <div className="flex flex-wrap gap-2 ">
                                    <span className=" text-xs text-gray-500">
                                        {(() => {
                                            const actionPhrase = `This ${formatActionType(item.action_type, true)}`;
                                            const userIdentity = item.user?.user_login || item.user?.user_email || '';
                                            // No real user attached → fall back to metadata.triggered_by from the response.
                                            if (!userIdentity) {
                                                const rawTriggeredBy = item.metadata?.triggered_by || 'system';
                                                const triggeredBy = rawTriggeredBy
                                                    .split('_')
                                                    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
                                                    .join(' ');
                                                return <>{actionPhrase} by {triggeredBy}</>;
                                            }
                                            return (
                                                <>
                                                    {actionPhrase} by user {userIdentity}{" "}
                                                    {item.user?.user_roles?.length > 0 && (
                                                        <span className="text-xs">({formatActionType(item.user.user_roles[0])})</span>
                                                    )}
                                                </>
                                            );
                                        })()}
                                    </span>
                                </div>




                            </div>
                        ))}
                        {isLoadingMore && (
                            <div className="flex justify-center py-4">
                                <Loader2 className="w-6 h-6 text-[#007980] animate-spin" />
                            </div>
                        )}
                        {!hasMoreHistory && historyData.length > 0 && (
                            <div className="text-center text-gray-400 text-sm py-2">No more history</div>
                        )}
                    </div>
                )}
            </div>
        </PopUpModal>
    );
};

export default ViewHistoryModal;
