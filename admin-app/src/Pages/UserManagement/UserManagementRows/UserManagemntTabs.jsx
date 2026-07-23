import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import { useRef, useState } from 'react';
import { UserManagementButton } from '../../../Components/Skeleton/LoaderSkeleton';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { updateTwoFa, generateLoginLink } from '../MangementServices/ManagementServices';
import DropdownMenu from '../../../Components/DropdownMenu/DropdownMenu';
import { RiUserReceivedFill, RiUserForbidFill, RiUserSettingsFill, RiUserUnfollowFill, RiShieldUserFill, RiUserVoiceFill } from 'react-icons/ri';
import { Shield, Clock, ShieldOff, Lock } from 'lucide-react';
import { alertService } from '../../../Components/AlertService/AlertService';
import { toast } from 'react-toastify';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';

export const UserRow = ({ user, setUserData, setLoading, handleUserRestrictionClick, setTwoFA, setTwoFaLoading, handleRoleChangeClick, handleEditUserClick, handleDeleteUserClick, handleBlockUserClick, handleUnblockUserClick, handleChangeToPermanentClick, twoFaLoading }) => {
  const [isGenerating, setIsGenerating] = useState(false);

  const handleToggleChange = async (checked) => {
    // The real toggle state lives under two_step_verification.data.enabled,
    // so optimistic updates must write into that nested object — writing to a
    // top-level `enabled` field would create a phantom property the UI never reads.
    setTwoFA((prev) =>
      prev.map((u) =>
        u.id === user.id
          ? { ...u, two_step_verification: { ...u.two_step_verification, data: { ...(u.two_step_verification?.data || {}), enabled: checked } } }
          : u
      )
    );
    setTwoFaLoading((prev) => ({ ...prev, [user.id]: true }));

    const { success, message } = await updateTwoFa({ userId: user.id, username: user.username, state: checked, setUserData, setLoading, });

    if (success) {
      toast.success(message);
    } else {
      setTwoFA((prev) =>
        prev.map((u) =>
          u.id === user.id
            ? { ...u, two_step_verification: { ...u.two_step_verification, data: { ...(u.two_step_verification?.data || {}), enabled: !checked } } }
            : u
        )
      );
      toast.error(message);
    }
    setTwoFaLoading((prev) => ({ ...prev, [user.id]: false }));
  };

  const showLoginLinkPopup = (link) => {
    const htmlMessage = `
    <div style="line-height: 1.8; ">
      <h3 style="margin-bottom: 10px; font-size: 18px;">🔐 You're about to login as:</h3>      
      <div>
        <p style="margin: 8px 0;"><strong>👤 Username:</strong> ${user.username}</p>
        <p style="margin: 8px 0;"><strong>📧 Email:</strong> ${user.email}</p>
        <p style="margin: 8px 0;"><strong>🛡️ Role:</strong> ${user.role}</p>
      </div>  
      <p style="margin-top: 10px;">Are you sure you want to continue?</p>
    </div>
  `;

    alertService.optimizationPopup(htmlMessage, 'Login as User', 'Login Now', 'Cancel').then((confirmed) => {
      if (confirmed) {
        window.open(link || user.login_as_another?.data?.url, '_blank', 'noopener,noreferrer');
      }
    });
  };

  const handleLoginAsUserClick = async () => {
    if (user.login_as_another?.data?.slug_generated) {
      showLoginLinkPopup(user.login_as_another?.data?.url);
    } else {
      setIsGenerating(true);
      try {
        const response = await generateLoginLink({ user_id: user.id });
        if (response.success) {
          const newLink = response.data.url; // Using 'url' as per your snippet
          setUserData((prev) => prev.map((u) => u.id === user.id ? { ...u, login_as_another: { slug_generated: true, link: newLink } } : u));
          showLoginLinkPopup(newLink);
        } else {
          toast.error('Failed to generate login link.');
        }
      } catch (error) {
        toast.error('Error generating login link.');
      } finally {
        setIsGenerating(false);
      }
    }
  };
  const dropdownButtonRef = useRef(null);

  // Caller passes a specific featureName so the parent-disabled tooltip can
  // identify which sub-feature is off (e.g. "user blocking", "login as user").
  const getFeatureState = (featureData, featureName = 'this feature') => {
    if (featureData?.is_upgrade_feature === true) {
      return { disabled: true, tooltipMessage: "Upgrade now" };
    }
    if (!featureData || featureData.license_connected === false) {
      return { disabled: true, tooltipMessage: "Connect to Tailwatch" };
    }
    if (featureData.pro_plugin_active === false) {
      return { disabled: true, tooltipMessage: "This feature requires a Pro Plugin." };
    }
    if (featureData.data && (featureData.data.feature_status === false || featureData.data.parent_enable === false)) {
      return { disabled: true, tooltipMessage: `Enable ${featureName} from User Management settings first` };
    }
    return { disabled: false, tooltipMessage: "" };
  };

  const loginAsState = getFeatureState(user.login_as_another, 'the Login as User feature');
  if (!loginAsState.disabled && user.login_as_another?.data?.is_enabled === false) {
    loginAsState.disabled = true;
    loginAsState.tooltipMessage = "Login as User is not enabled";
  }

  const userRestrictionState = getFeatureState(user.user_expiry_status, 'the User Restriction feature');

  // user_status follows the same 4-case shape as the other feature gates:
  //   1. is_upgrade_feature → upgrade required
  //   2. license_connected === false → license required
  //   3. pro_plugin_active === false → pro plugin required
  //   4. data.feature_status / parent_enable === false → feature disabled
  // Otherwise the feature is enabled and the user can be blocked / unblocked.
  const blockUserState = getFeatureState(user.user_status, 'the user blocking feature');

  // When the backend reports an active block, data is an object with
  // { block_type, time_remaining, reason }. When idle, data is [] or {}.
  const blockData = user.user_status?.data;
  const blockType = (blockData && !Array.isArray(blockData)) ? blockData.block_type : null;
  const timeRemaining = (blockData && !Array.isArray(blockData)) ? blockData.time_remaining : null;
  const isBlocked = blockType === 'temporary' || blockType === 'permanent';
  const isTemporary = blockType === 'temporary';
  const isPermanent = blockType === 'permanent';

  // "Change to Permanent" is no longer a menu item — it's surfaced as an inline
  // link under the "Temporarily Blocked" badge so it's discoverable without
  // opening the dropdown.
  const blockMenuItems = isBlocked
    ? [{
        label: 'Unblock User',
        icon: <RiUserVoiceFill />,
        onClick: () => handleUnblockUserClick?.(user),
        disabled: blockUserState.disabled,
        tooltipMessage: blockUserState.tooltipMessage,
      }]
    : [{
        label: 'Block User',
        icon: <RiShieldUserFill />,
        onClick: () => handleBlockUserClick?.(user),
        disabled: blockUserState.disabled,
        tooltipMessage: blockUserState.tooltipMessage,
      }];

  const menuItems = [
    { label: "Login as User", icon: <RiUserReceivedFill />, onClick: handleLoginAsUserClick, disabled: loginAsState.disabled, tooltipMessage: loginAsState.tooltipMessage },
    { label: 'User Restriction', icon: <RiUserForbidFill />, onClick: () => handleUserRestrictionClick(user), disabled: userRestrictionState.disabled, tooltipMessage: userRestrictionState.tooltipMessage },
    ...blockMenuItems,
    { label: 'Edit User', icon: <RiUserSettingsFill />, onClick: () => handleEditUserClick(user), disabled: false, tooltipMessage: '' },
    { label: 'Delete User', icon: <RiUserUnfollowFill />, onClick: () => handleDeleteUserClick(user), disabled: false, tooltipMessage: '' },
  ];

  // Status badge — matches the visual language of the IP black-list table.
  const renderStatusBadge = () => {
    if (isPermanent) {
      return (
        <div className="inline-flex items-center space-x-2 px-3 py-2 rounded-lg border bg-red-50 border-red-200">
          <span className="text-red-600"><Shield size={14} /></span>
          <span className="text-xs font-medium text-red-600">Permanently Blocked</span>
        </div>
      );
    }
    if (isTemporary) {
      // Change-to-Permanent is a compact lock icon next to the label —
      // hover reveals the tooltip ("Change to Permanent"), click fires the
      // alert. Same gating as before via blockUserState.
      const canChange = !blockUserState.disabled;
      const tipMessage = canChange
        ? 'Change to Permanent'
        : (blockUserState.tooltipMessage || 'Change to Permanent');
      return (
        <div className="inline-flex items-start space-x-2 px-3 py-2 rounded-lg border bg-amber-50 border-amber-200 whitespace-nowrap">
          <span className="text-amber-600 mt-0.5"><Clock size={14} /></span>
          <div className="flex flex-col">
            <div className="flex items-center gap-1.5">
              <span className="text-xs font-medium text-amber-600">Temporarily Blocked</span>
              <Tooltip message={tipMessage}>
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); if (canChange) handleChangeToPermanentClick?.(user); }}
                  disabled={!canChange}
                  aria-label="Change to Permanent"
                  className={`inline-flex items-center justify-center w-4 h-4 rounded-sm bg-transparent border-0 p-0 ${canChange ? 'text-amber-700 hover:text-[#007980] cursor-pointer' : 'text-gray-400 cursor-not-allowed'}`}
                >
                  <Lock size={12} />
                </button>
              </Tooltip>
            </div>
            {timeRemaining && (
              <span className="text-xs text-gray-500">Expires in {timeRemaining}</span>
            )}
          </div>
        </div>
      );
    }
    return (
      <div className="inline-flex items-center space-x-2 px-3 py-2 rounded-lg border bg-green-50 border-green-200">
        <span className="text-green-600"><ShieldOff size={14} /></span>
        <span className="text-xs font-medium text-green-600">Active</span>
      </div>
    );
  };

  const getTwoFaState = () => {
    const twoFA = user?.two_step_verification || {};

    if (twoFA.is_upgrade_feature === true) {
      return { disabled: true, showTooltip: true, message: "Upgrade now" };
    }

    // License / Pro plugin gating — same guards used by login_as_another / user_expiry_status.
    if (twoFA.license_connected === false) {
      return { disabled: true, showTooltip: true, message: "Connect to Tailwatch" };
    }
    if (twoFA.pro_plugin_active === false) {
      return { disabled: true, showTooltip: true, message: "This feature requires a Pro Plugin." };
    }

    if (twoFA.required_plan && twoFA.current_plan === "Basic") {
      return { disabled: true, showTooltip: true, message: `Upgrade to ${twoFA.required_plan} plan` };
    }

    // When the parent feature is off, the toggle is unusable regardless of the
    // email/GA configuration — surface that as the primary reason.
    const data = twoFA.data || {};
    if (data.parent_feature_disable === true) {
      return { disabled: true, showTooltip: true, message: "Enable Role-based 2FA feature first" };
    }

    const emailEnabled = data.email_enabled;
    const googleAuthEnabled = data.google_authenticator_enabled;
    const twoFaEnabled = data.enabled;

    // Case 1: email_enabled is false AND google_authenticator_enabled is false
    if (emailEnabled === false && googleAuthEnabled === false) {
      return { disabled: true, showTooltip: true, message: data.status_message || "Email verification and Google Authenticator are not enabled" };
    }

    // Case 2: enabled is false AND email_enabled is false AND google_authenticator_enabled is true
    if (twoFaEnabled === false && emailEnabled === false && googleAuthEnabled === true) {
      return { disabled: true, showTooltip: true, message: data.status_message || "Email verification is not enabled and Two-factor authentication is disabled" };
    }

    // Case 3: enabled is true AND email_enabled is false AND google_authenticator_enabled is true
    if (twoFaEnabled === true && emailEnabled === false && googleAuthEnabled === true) {
      return { disabled: false, showTooltip: true, message: "By disable GA will be deactivate" };
    }
    return { disabled: false, showTooltip: false, message: "" };
  };

  const twoFaState = getTwoFaState();

  const renderToggleSwitch = () => {
    if (twoFaLoading[user.id]) {
      return <UserManagementButton />;
    }

    const toggleSwitch = (
      <ToggleSwitch checked={user?.two_step_verification?.data?.enabled === true} onChange={twoFaState.disabled ? () => { } : (e) => handleToggleChange(e.target.checked)} disabled={twoFaState.disabled || twoFaLoading[user.id]} />
    );

    if (twoFaState.showTooltip) {
      return (
        <Tooltip message={twoFaState.message} className='bg-[#ec5023]'>
          <div className={twoFaState.disabled ? "cursor-not-allowed" : ""}>
            {toggleSwitch}
          </div>
        </Tooltip>
      );
    }

    return toggleSwitch;
  };

  return (
    <>
      <td className="p-3 whitespace-nowrap text-sm text-black w-1/5">
        {user.username}
      </td>
      <td className="p-3 whitespace-nowrap text-sm text-black w-1/5">
        {user.email}
      </td>
      <td className="p-3 whitespace-nowrap text-sm text-black w-1/5">
        {user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : ''}
      </td>
      <td className="p-3 whitespace-nowrap text-sm text-black">
        {renderToggleSwitch()}
      </td>
      <td className="p-3 whitespace-nowrap text-sm text-black">
        {renderStatusBadge()}
      </td>
      <td className="p-3 whitespace-nowrap text-sm text-black flex items-center space-x-2">
        <ActionButton onClick={() => handleRoleChangeClick(user)} defaultText="Change Role" />
        <div ref={dropdownButtonRef}>
          <DropdownMenu id={`dropdown-${user.id}`} menuItems={menuItems} parentRef={dropdownButtonRef} />
        </div>
      </td>
      {isGenerating && (
        <div className="fixed inset-0 flex items-center justify-center z-50 bg-opacity-10 bg-gray-100">
          <Spinner />
        </div>
      )}
    </>
  );
};