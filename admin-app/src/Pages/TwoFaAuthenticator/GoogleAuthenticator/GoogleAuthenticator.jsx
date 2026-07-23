import React, { useState, useEffect } from 'react';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { googleAuthData, getTwoFaStatus, verifyTwoFaStatus, disconnectTwoFa } from '../TwoFaAuthServices/TwoFaAuthServices';
import { alertService } from '../../../Components/AlertService/AlertService';
import { toast } from 'react-toastify';

const GoogleAuthenticator = () => {
    const [loading, setLoading] = useState(true);
    const [authData, setAuthData] = useState({});
    const [get2FaStatus, set2FaStatus] = useState({});
    const [isCopied, setIsCopied] = useState(false);
    const [code, setCode] = useState('');
    const [copiedIndex, setCopiedIndex] = useState(null);

    const fetchGoogleAuthData = async () => {
        const status = await getTwoFaStatus({ set2FaStatus, setLoading }); 
        if (!status?.is_connected) {
            await googleAuthData({ setAuthData, setLoading });
        }
    };
    const handleDisconnect = async () => {
        const isConfirmed = await alertService.verify(
            "Are you sure you want to disconnect ?",
            "Disconnect 2FA?",
            "Disconnect",
            "Cancel"
        );
        if (!isConfirmed) return;
        await disconnectTwoFa({ set2FaStatus, setLoading });
        setCode('');
    };
    useEffect(() => {
        fetchGoogleAuthData();
    }, []);

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text);
        setIsCopied(true);
        setTimeout(() => setIsCopied(false), 2000);
    };

    const copyBackupCode = (code, index) => {
        navigator.clipboard.writeText(String(code));
        setCopiedIndex(index);
        setTimeout(() => setCopiedIndex(null), 2000);
    };

    const copyAllBackupCodes = () => {
        const allCodes = get2FaStatus?.backup_codes?.join('\n');
        navigator.clipboard.writeText(allCodes);
        toast.success('All backup codes copied!');
    };

    const handleVerify = async () => {
        if (code.length !== 6) {
            toast.error('Please enter a 6-digit code');
            return;
        }
        await verifyTwoFaStatus({ code, set2FaStatus, setLoading });
    };

    const renderSecretKey = () => {
        if (!authData || !authData.secret) return null;
        const formattedSecret = authData.secret.match(/.{1,4}/g).join(' ');
        return (
            <div className="mt-8 p-5 bg-gray-50 rounded-lg border-l-4 border-teal-700">
                <h3 className="text-lg font-semibold text-gray-800 mb-3">Secret Key</h3>
                <p className="text-gray-600 text-sm mb-4">
                    If you can't scan the QR code, enter this secret key manually in your authenticator app:
                </p>
                <div className="flex items-center bg-white border border-gray-200 rounded-md p-3 overflow-x-auto">
                    <span className="font-mono tracking-wide text-gray-800 font-medium mr-3 whitespace-nowrap flex-1">
                        {formattedSecret}
                    </span>
                    <ActionButton defaultText={isCopied ? 'Copied!' : 'Copy'} onClick={() => copyToClipboard(authData.secret)} />
                </div>
            </div>
        );
    };

    const renderBackupCodes = () => {
        const backupCodes = get2FaStatus?.backup_codes;
        if (!backupCodes || backupCodes.length === 0) return null;

        return (
            <div className="mt-6 p-5 bg-yellow-50 rounded-lg border-l-4 border-yellow-400">
                <div className="flex items-center justify-between mb-3">
                    <h3 className="text-lg font-semibold text-gray-800">Backup Codes</h3>
                    <button
                        onClick={copyAllBackupCodes}
                        className="text-sm text-teal-700 hover:text-teal-900 font-medium underline"
                    >
                        Copy All
                    </button>
                </div>
                <p className="text-gray-600 text-sm mb-4">
                    Save these backup codes in a secure place. Each code can only be used once if you lose access to your authenticator app.
                </p>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {backupCodes.map((backupCode, index) => (
                        <div
                            key={index}
                            onClick={() => copyBackupCode(backupCode, index)}
                            className="flex items-center justify-between bg-white border border-gray-200 rounded-md px-3 py-2 cursor-pointer hover:border-teal-400 hover:bg-teal-50 transition-colors group"
                            title="Click to copy"
                        >
                            <span className="font-mono text-sm text-gray-800 font-medium tracking-wide">
                                {backupCode}
                            </span>
                            <span className="text-xs text-gray-400 group-hover:text-teal-600 ml-2">
                                {copiedIndex === index ? '✓' : '⎘'}
                            </span>
                        </div>
                    ))}
                </div>
                <p className="text-xs text-yellow-700 mt-3 font-medium">
                    ⚠ Store these codes safely. They won't be shown again after you leave this page.
                </p>
            </div>
        );
    };

    return (
        <div>
            <div className="mx-auto font-sans">
                {loading ? (
                    <div className="flex flex-col items-center justify-center my-64">
                        <Spinner />
                        <p className="text-gray-600">Loading authentication data...</p>
                    </div>
                ) : (
                    <div className="mt-8">
                        <div className="bg-white rounded-lg shadow-sm border overflow-hidden">
                            <div className="p-6 bg-gray-50 border-b border-gray-200">
                                <h2 className="text-xl font-semibold text-gray-800 mb-2">Google Authenticator Setup</h2>
                                <p className="text-gray-600">Enhance your account security by setting up two-factor authentication</p>
                            </div>
                            <div className="p-6">
                                {get2FaStatus.is_connected ? (
                                    <div>
                                        <div className="text-center mb-6">
                                            <h3 className="text-lg font-medium text-green-600 mb-4">2FA is Enabled</h3>
                                            <p className="text-gray-600 mb-6">Your account is protected with two-factor authentication.</p>
                                            <ActionButton
                                                defaultText={loading ? 'Disconnecting...' : 'Disconnect 2FA'}
                                                onClick={handleDisconnect}
                                                isDisabled={loading}
                                            />
                                        </div>
                                        {renderBackupCodes()}
                                    </div>
                                ) : (
                                    <>
                                        <div className="flex items-start mb-6">
                                            <div className="flex-shrink-0 w-8 h-8 bg-[#007980] text-white rounded-full flex items-center justify-center font-bold mr-4"> 1 </div>
                                            <div>
                                                <h3 className="text-lg font-medium text-gray-800 mb-2">Download an authenticator app</h3>
                                                <p className="text-gray-600">Get Google Authenticator, Authy, or any other TOTP authenticator app on your mobile device</p>
                                            </div>
                                        </div>
                                        <div className="flex items-start mb-6">
                                            <div className="flex-shrink-0 w-8 h-8 bg-[#007980] text-white rounded-full flex items-center justify-center font-bold mr-4"> 2 </div>
                                            <div>
                                                <h3 className="text-lg font-medium text-gray-800 mb-2">Scan the QR code</h3>
                                                <p className="text-gray-600">Open your authenticator app and scan the QR code below</p>
                                            </div>
                                        </div>
                                        {authData && authData.qr_code && (
                                            <div className="flex justify-center my-8 p-5 bg-white rounded-lg shadow-sm">
                                                <img src={authData.qr_code} alt="Google Authenticator QR Code" className="max-w-full h-auto w-48" />
                                            </div>
                                        )}
                                        {renderSecretKey()}
                                        <div className="flex items-start mt-8">
                                            <div className="flex-shrink-0 w-8 h-8 bg-[#007980] text-white rounded-full flex items-center justify-center font-bold mr-4"> 3 </div>
                                            <div className="flex-1">
                                                <h3 className="text-lg font-medium text-gray-800 mb-2">Enter the verification code</h3>
                                                <p className="text-gray-600 mb-4">Enter the 6-digit code from your authenticator app below to complete setup</p>
                                                <div className="sm:flex items-center gap-3">
                                                    <input
                                                        type="text"
                                                        className="w-full sm:w-48 px-4 py-2 border border-gray-300 rounded-md text-center tracking-widest font-medium mb-3 sm:mb-0"
                                                        placeholder="Enter 6-digit code"
                                                        maxLength="6"
                                                        value={code}
                                                        onChange={(e) => setCode(e.target.value)}
                                                        onKeyDown={(e) => e.key === 'Enter' && handleVerify()}
                                                    />
                                                    <ActionButton
                                                        defaultText={loading ? 'Verifying...' : 'Verify & Activate'}
                                                        onClick={handleVerify}
                                                        isDisabled={loading}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
export default GoogleAuthenticator;