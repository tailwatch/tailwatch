import ActionButton from "../../../Components/Buttons/ActionButton";
export const DisconnectModal = ({ isOpen, onClose, onDirectDisconnect }) => {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                <div className="p-6">
                    <h3 className="text-xl font-semibold text-gray-900 mb-4">
                        Disconnect License
                    </h3>
                    <p className="text-gray-600 mb-6">
                        Choose how you'd like to proceed with disconnecting your license.
                    </p>

                    <div className="space-y-3">                        
                        <ActionButton
                            onClick={onDirectDisconnect}
                            defaultText="Disconnect Directly"
                            className="!w-full !bg-red-600 hover:!bg-red-700"
                        />

                        <ActionButton
                            onClick={onClose}
                            defaultText="Cancel"
                            className="!w-full !bg-gray-400 !text-gray-700 hover:!bg-gray-500"
                        />
                    </div>
                </div>
            </div>
        </div>
    );
};
