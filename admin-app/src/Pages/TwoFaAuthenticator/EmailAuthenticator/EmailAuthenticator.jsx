import React, { useState, useEffect } from 'react';
import { verifyEmailStatus, updateEmailStatus } from '../TwoFaAuthServices/TwoFaAuthServices';
import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { toast } from 'react-toastify' ;
import { alertService } from '../../../Components/AlertService/AlertService';

const EmailAuthenticator = () => {
    const [loading, setLoading] = useState(true);
    const [isVerifyEmail, setIsVerifyEmail] = useState(false);
    const [emailId, setEmailId] = useState(null);
    const [userName, setUserName] = useState(null);
    const [isToggling, setIsToggling] = useState(false);
    const [isRequired, setIsRequired] = useState(false);
    // Whether this account may take itself out of the rule its role sets: only someone who
    // could switch that rule off for everyone may.
    const [canExempt, setCanExempt] = useState(false);
    const [isExempt, setIsExempt] = useState(false);

    useEffect(() => {
        verifyEmailStatus({ setIsVerifyEmail, setEmailId, setLoading, setUserName, setIsRequired, setCanExempt, setIsExempt });
    }, []);

    const locked = isRequired && !canExempt;

    const handleToggle = async () => {
        const updatedState = !isVerifyEmail;
        if (!updatedState && isRequired) {
            const confirmed = await alertService.verify(
                "Your role requires email verification. Switching it off takes only your account out of that rule: you will sign in without the emailed code, while everyone else with your role still gets it. This is recorded in the logs.",
                "Switch off email verification for your account?",
                "Switch off",
                "Cancel"
            );
            if (!confirmed) {
                return;
            }
        }
        setIsToggling(true);
        const result = await updateEmailStatus({ id: emailId, state: updatedState, setIsVerifyEmail, setEmailId, setLoading, setUserName, setIsRequired, setCanExempt, setIsExempt });
        if (result?.success) {
            toast.success('Two-step email authenticator updated successfully');
        } else {
            toast.error(result?.message || 'Failed to update 2FA.');
        }
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
                                <ToggleSwitch checked={isVerifyEmail} onChange={locked ? () => { } : handleToggle} disabled={isToggling || locked} />
                            )}
                        </div>
                    </div>
                    {locked && (
                        <p className="text-sm text-gray-600">
                            Your role requires email verification, so it cannot be turned off here.
                        </p>
                    )}
                    {isRequired && canExempt && (
                        <p className="text-sm text-gray-600">
                            Your role requires email verification. Because you can change the 2FA rules, you can switch it off for your own account; everyone else with your role still gets the code.
                        </p>
                    )}
                    {isExempt && (
                        <p className="text-sm text-gray-600">
                            Email verification is switched off for your account although your role requires it. Switch it on to use it again.
                        </p>
                    )}
                </>
            )}
        </div>
    );
};
export default EmailAuthenticator;
