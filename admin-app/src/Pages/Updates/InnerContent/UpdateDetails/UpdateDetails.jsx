import React from 'react'
import { InfoIcon, Clock, Package, Loader2, XCircle } from 'lucide-react';
import DOMPurify from 'dompurify';

const UpdateDetails = ({
    pluginDetails,
    activeTab,
    handleTabClick,
    historyData = [],
    historyLoading = false,
    hasMoreHistory = false,
    isLoadingMore = false,
    formatActionType = (s) => s,
    onHistoryScroll,
}) => {
    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-300">
            <div className="border-b border-gray-200 overflow-x-auto">
                <div className="flex items-center justify-between min-w-max">
                    <nav className="flex">
                        <div
                            className={`py-2 sm:py-3 px-3 sm:px-6 text-gray-700 font-medium cursor-pointer transition-all hover:text-gray-900 text-sm sm:text-base ${activeTab === 'details'
                                ? 'border-b-2 border-teal-600 text-gray-900'
                                : 'border-b-2 border-transparent'
                                }`}
                            onClick={() => handleTabClick('details')}
                        >
                            <div className="flex items-center">
                                <InfoIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2" />
                                Details
                            </div>
                        </div>
                        <div
                            className={`py-2 sm:py-3 px-3 sm:px-6 text-gray-700 font-medium cursor-pointer transition-all hover:text-gray-900 text-sm sm:text-base ${activeTab === 'history'
                                ? 'border-b-2 border-teal-600 text-gray-900'
                                : 'border-b-2 border-transparent'
                                }`}
                            onClick={() => handleTabClick('history')}
                        >
                            <div className="flex items-center">
                                <Clock className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2" />
                                History
                            </div>
                        </div>
                    </nav>
                </div>
            </div>

            <div className="p-3 sm:p-4 lg:p-6">
                {activeTab === 'details' && (
                    <div>
                        <h2 className="text-base sm:text-lg lg:text-xl font-semibold mb-3 sm:mb-4 text-gray-800 flex items-center">
                            <Package className="w-4 h-4 sm:w-5 sm:h-5 mr-2 text-[#007980]" />
                            Description
                        </h2>
                        <div
                            className="mb-4 text-sm sm:text-base text-gray-700 leading-relaxed prose max-w-none prose-sm sm:prose-base"
                            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(pluginDetails?.description || 'No descriptions available') }}
                        />
                    </div>
                )}

                {activeTab === 'history' && (
                    <div>
                        {historyLoading && historyData.length === 0 ? (
                            <div className="flex justify-center items-center h-[60vh]">
                                <Loader2 className="w-8 h-8 text-[#007980] animate-spin" />
                            </div>
                        ) : (
                            <div
                                onScroll={onHistoryScroll}
                                className="h-[60vh] overflow-y-auto custom-scroll-bar pr-1"
                            >
                                {historyData.length === 0 ? (
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
                                                    <div className="text-sm text-gray-600 mb-1">
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
                                                                        <div className="text-xs text-red-600 mt-1">
                                                                            {item.metadata.error} <span className='text-green-600'>{oldVer}</span>
                                                                        </div>
                                                                    )}
                                                                </>
                                                            );
                                                        })()}
                                                    </div>
                                                )}

                                                <div className="flex flex-wrap gap-2">
                                                    <span className="text-xs text-gray-500">
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
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default UpdateDetails;
