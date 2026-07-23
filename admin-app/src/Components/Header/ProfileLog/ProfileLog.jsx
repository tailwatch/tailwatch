import React, { useState, useRef, useEffect } from 'react';
import { useUser } from '../../Context/UserContext';
import { FaUserCircle, FaCrown } from "react-icons/fa";
import { Star, Zap, Lightbulb } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { useLicenseProvider } from "../../Context/LicenseProvider";
import ActionButton from '../../Buttons/ActionButton';

const capitalizeFirstLetter = (str) => {
  return str.replace(/\b\w/g, char => char.toUpperCase());
};

const getPlanStyle = (planName) => {
  if (!planName) {
    return {
      bg: 'bg-gray-50',
      border: 'border-gray-200',
      label: 'Connect your Account',
      sub: 'Connect a license to access all features',
      pill: 'bg-teal-500 text-white',
      pillText: 'Connect',
      iconBg: 'bg-gray-100',
      iconColor: 'text-gray-500',
      Icon: Lightbulb,
      action: 'connect',
    };
  }
  if (planName === 'Agency') {
    return {
      bg: 'bg-amber-50',
      border: 'border-amber-200',
      label: `Current Plan: ${planName}`,
      sub: 'Premium plan active',
      pill: 'bg-amber-400 text-amber-950',
      pillText: 'Active',
      iconBg: 'bg-amber-100',
      iconColor: 'text-amber-500',
      Icon: Star,
      action: 'none',
    };
  }
  if (planName.startsWith('Business')) {
    return {
      bg: 'bg-teal-50',
      border: 'border-teal-200',
      label: `Current Plan: ${planName}`,
      sub: 'Business plan active',
      pill: 'bg-teal-500 text-white',
      pillText: 'Active',
      iconBg: 'bg-teal-100',
      iconColor: 'text-teal-600',
      Icon: Zap,
      action: 'none',
    };
  }
  if (planName === 'Basic') {
    return {
      bg: 'bg-orange-50',
      border: 'border-orange-200',
      label: `Current Plan: ${planName}`,
      sub: 'Upgrade for more features',
      pill: 'bg-orange-500 text-white',
      pillText: 'Upgrade',
      iconBg: 'bg-orange-100',
      iconColor: 'text-orange-500',
      Icon: Lightbulb,
      action: 'upgrade',
    };
  }
  return {
    bg: 'bg-gray-50',
    border: 'border-gray-200',
    label: `Current Plan: ${planName}`,
    sub: 'Upgrade your plan for more features',
    pill: 'bg-teal-500 text-white',
    pillText: 'Upgrade',
    iconBg: 'bg-gray-100',
    iconColor: 'text-gray-500',
    Icon: Lightbulb,
    action: 'upgrade',
  };
};

const ProfileLog = () => {
  const [loggingOut, setLoggingOut] = useState(false);
  const [open, setOpen] = useState(false);
  const [imageError, setImageError] = useState(false);
  const popoverRef = useRef(null);
  const navigate = useNavigate();
  const { userData, loading, error } = useUser();
  const { planName, loading: licenseLoading } = useLicenseProvider();

  const togglePopover = () => {
    setOpen(!open);
  };

  const handleClickOutside = (event) => {
    if (popoverRef.current && !popoverRef.current.contains(event.target)) {
      setOpen(false);
    }
  };

  useEffect(() => {
    if (open) {
      document.addEventListener("mousedown", handleClickOutside);
    } else {
      document.removeEventListener("mousedown", handleClickOutside);
    }

    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [open]);

  const handleLogout = () => {
    setLoggingOut(true);
    window.location.href = userData.logout_url;
  };

  const handleImageError = () => {
    setImageError(true);
  };

  const handleLicenseClick = () => {
    setOpen(false);
    navigate("/dashboard/settings/license");
  };

  const renderProfileTrigger = () => {
    if (loading || !userData || !userData.profile_picture || imageError) {
      return <FaUserCircle size={40} className="text-gray-500 cursor-pointer" onClick={togglePopover} />;
    }

    return (
      <img
        src={userData?.profile_picture}
        alt="Profile"
        className="w-10 h-10 rounded-full cursor-pointer object-cover border-2 border-gray-300 hover:border-gray-400 transition-colors"
        onClick={togglePopover}
        onError={handleImageError}
      />
    );
  };

  const ps = getPlanStyle(planName);
  const PlanIcon = ps.Icon;

  return (
    <div className="relative" ref={popoverRef}>
      {renderProfileTrigger()}
      {open && (
        <div className="absolute right-0 mt-2 w-64 z-50 bg-white rounded-lg shadow-lg overflow-hidden" onClick={(e) => e.stopPropagation()}>
          <div className="p-4">
            {loading || loggingOut ? (
              <div>Loading...</div>
            ) : error ? (
              <div className="text-red-500">Error: {error}</div>
            ) : userData ? (
              <div className="flex flex-col items-center text-center">
                <img
                  src={userData.profile_picture}
                  alt="Avatar"
                  className="w-20 h-20 rounded-full mb-3 object-cover"
                />
                <p className="font-semibold text-gray-800">{capitalizeFirstLetter(userData?.name)}</p>
                <p className="text-sm text-gray-500 mb-1">{userData?.email}</p>
                <p className="text-sm text-gray-500 mb-4">
                  Role: {userData?.role.map(capitalizeFirstLetter).join(', ')}
                </p>
                <ActionButton
                  onClick={handleLogout}
                  defaultText="Log Out"
                  className="!w-full"
                />
              </div>
            ) : (
              <div>No user data available</div>
            )}
          </div>

          {/* Plan / License label — full-width strip at the bottom */}
          {!licenseLoading && (
            <div
              onClick={ps.action !== 'none' ? handleLicenseClick : undefined}
              className={`w-full flex items-center justify-between gap-2.5 px-4 py-3 border-t ${ps.bg} ${ps.border} ${ps.action !== 'none' ? 'cursor-pointer hover:brightness-95 transition' : ''}`}
            >
              <div className="flex items-center gap-2.5 min-w-0">
                <div className={`flex items-center justify-center h-8 w-8 rounded-lg flex-shrink-0 ${ps.iconBg}`}>
                  <PlanIcon size={14} className={ps.iconColor} fill="currentColor" />
                </div>
                <div className="flex flex-col min-w-0">
                  <span className="text-xs font-semibold text-gray-800 leading-tight truncate">
                    {ps.label}
                  </span>
                  <span className="text-[10px] leading-tight mt-0.5 text-gray-500 truncate">
                    {ps.sub}
                  </span>
                </div>
              </div>

              {ps.action === 'upgrade' ? (
                <button
                  type="button"
                  onClick={handleLicenseClick}
                  className={`flex items-center gap-1 text-[10px] font-bold px-2.5 py-1 rounded-full transition-all duration-200 ${ps.pill}`}
                >
                  <FaCrown size={9} />
                  {ps.pillText}
                </button>
              ) : ps.action === 'connect' ? (
                <button
                  type="button"
                  onClick={handleLicenseClick}
                  className={`text-[10px] font-bold px-2.5 py-1 rounded-full transition-all duration-200 ${ps.pill}`}
                >
                  {ps.pillText}
                </button>
              ) : (
                <span className={`text-[10px] font-bold px-2.5 py-1 rounded-full ${ps.pill}`} style={{ letterSpacing: "0.04em" }}>
                  {ps.pillText}
                </span>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default ProfileLog;
