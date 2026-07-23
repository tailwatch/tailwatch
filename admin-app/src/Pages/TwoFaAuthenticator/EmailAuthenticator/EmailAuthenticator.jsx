import React, { useState, useEffect } from 'react';
import { verifyEmailStatus, updateEmailStatus } from '../TwoFaAuthServices/TwoFaAuthServices';
import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { toast } from 'react-toastify' ;

const EmailAuthenticator = () => {
    const [loading, setLoading] = useState(true);
    const [isVerifyEmail, setIsVerifyEmail] = useState(false);
    const [emailId, setEmailId] = useState(null);
    const [userName, setUserName] = useState(null);
    const [isToggling, setIsToggling] = useState(false);
    

    useEffect(() => {
        verifyEmailStatus({ setIsVerifyEmail, setEmailId, setLoading, setUserName });
    }, []);

    const handleToggle = async () => {
        setIsToggling(true);
        const updatedState = !isVerifyEmail;
        await updateEmailStatus({ id: emailId, state: updatedState, setIsVerifyEmail, setEmailId, setLoading, });
        toast.success('Two-step email authenticator updated successfully');
        setIsToggling(false);
    };

    return (
        <div className="bg-white p-6 rounded-lg shadow-md border border-gray-300 mx-auto">
            {loading ? (
                <div className="flex flex-col items-center justify-center my-8">
                    <Spinner />
                    <p className="text-gray-600">Loading authentication data...</p>
                </div>
            ) : (
                <>
                    <div className="mb-4">
                        <h2 className="text-xl font-semibold text-gray-800">Email Authentication</h2>
                        <p className="text-sm text-black mt-1">
                            Enable Two-Factor Authentication via email for an extra layer of security. When enabled, a verification code will be sent to your registered email address during login attempts.
                        </p>
                    </div>

                    <div className="flex items-centers gap-4 py-4 border-t border-gray-200">
                        <div>
                            <p className="text-base font-medium text-black">
                                Enable Email Authentication for {userName}
                            </p>
                        </div>

                        <div className='mt-1'>
                            {isToggling ? (
                                <Skeleton width={40} height={25} borderRadius={999} />
                            ) : (
                                <ToggleSwitch checked={isVerifyEmail} onChange={handleToggle} disabled={isToggling} />
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};
export default EmailAuthenticator;
