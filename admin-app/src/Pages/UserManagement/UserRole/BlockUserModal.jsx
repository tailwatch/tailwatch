import React, { useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { blockUser } from '../MangementServices/ManagementServices';

// Mirrors the duration conversion used by the IP black-list modal so the
// payload semantics line up across modules: backend always receives seconds.
const convertDurationToSeconds = (duration, unit) => {
    const value = parseInt(duration, 10) || 0;
    switch (unit) {
        case 'Minutes': return value * 60;
        case 'Hours':   return value * 60 * 60;
        case 'Days':    return value * 60 * 60 * 24;
        default:        return value * 60 * 60;
    }
};

const BlockUserModal = ({ show, handleClose, user, getData }) => {
    const [blockType, setBlockType] = useState('temporary');
    const [duration, setDuration] = useState('24');
    const [durationUnit, setDurationUnit] = useState('Hours');
    const [reason, setReason] = useState('');
    const [isLoading, setIsLoading] = useState(false);

    if (!show || !user) return null;

    const handleSubmit = async () => {
        const payload = {
            user_id: user.id,
            block_type: blockType,
            duration: blockType === 'temporary' ? convertDurationToSeconds(duration, durationUnit) : 0,
            reason: reason || '',
        };

        const success = await blockUser({
            payload,
            setLoading: setIsLoading,
            onClose: () => {
                handleClose?.();
                // reset for next open
                setBlockType('temporary');
                setDuration('24');
                setDurationUnit('Hours');
                setReason('');
            },
            getData,
        });

        return success;
    };

    const isValid =
        (blockType === 'permanent') ||
        (blockType === 'temporary' && parseInt(duration, 10) > 0);

    return (
        <PopUpModal
            title={`Block ${user.username || 'User'}`}
            onClose={handleClose}
            onSave={handleSubmit}
            isLoading={isLoading}
            disabled={!isValid}
            saveButtonText="Block User"
            cancelButtonText="Cancel"
            width="w-full max-w-lg sm:max-w-xl md:max-w-2xl"
        >
            <div className="space-y-4 px-2">
                <div className="p-3 mb-2 bg-yellow-50 border border-yellow-200 rounded-md flex items-start gap-2">
                    <div className="text-yellow-600 mt-0.5">
                        <AlertTriangle size={16} />
                    </div>
                    <div className="text-sm text-yellow-800">
                        <p className="font-medium">Warning</p>
                        <p>
                            This will immediately block <strong>{user.username}</strong> from logging in. They will be locked out for the duration you choose below.
                        </p>
                    </div>
                </div>

                <div>
                    <label className="block text-sm font-medium text-black mb-2">Block Type & Duration</label>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <select
                            required
                            value={blockType}
                            onChange={(e) => setBlockType(e.target.value)}
                            className="!px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980] focus:!border-transparent"
                        >
                            <option value="temporary">Temporary</option>
                            <option value="permanent">Permanent</option>
                        </select>

                        <div className="!flex !border !border-gray-300 !rounded-md !overflow-hidden focus-within:!ring-1 focus-within:!ring-[#007980] focus-within:!border-transparent">
                            <input
                                type="number"
                                min="1"
                                value={duration}
                                onChange={(e) => {
                                    const v = parseInt(e.target.value, 10);
                                    if (v >= 1 || e.target.value === '') setDuration(e.target.value);
                                }}
                                disabled={blockType === 'permanent'}
                                className="!flex-1 !min-w-0 !px-3 !py-2 !border-0 focus:!outline-none"
                            />
                            <select
                                value={durationUnit}
                                onChange={(e) => setDurationUnit(e.target.value)}
                                disabled={blockType === 'permanent'}
                                className="!flex-shrink-0 !min-w-[110px] !px-3 !py-2 !border-0 !border-l !border-gray-300 !bg-gray-50 focus:!outline-none !cursor-pointer"
                            >
                                <option value="Minutes">Minutes</option>
                                <option value="Hours">Hours</option>
                                <option value="Days">Days</option>
                            </select>
                        </div>
                    </div>
                    {blockType === 'permanent' && (
                        <p className="text-xs text-gray-500 mt-1">
                            Permanent blocks have no expiry — the user stays blocked until you unblock them manually.
                        </p>
                    )}
                </div>

                <div>
                    <label className="block text-sm font-medium text-black mb-2">Reason (Optional)</label>
                    <textarea
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Administrative notes about this block"
                        rows="3"
                        className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-transparent resize-none"
                    />
                </div>
            </div>
        </PopUpModal>
    );
};

export default BlockUserModal;
