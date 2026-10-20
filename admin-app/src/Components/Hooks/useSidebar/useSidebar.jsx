import { useState, useEffect } from "react";
import { useNavigate, Link } from "react-router-dom";
import { useLocation } from "react-router-dom";
import { useVerifyStatus } from "../../Hooks/VerifyStatus/VerifyStatus";
import { useSelector } from 'react-redux';
import { toast } from "react-toastify";
import { GoAlert } from "react-icons/go";
import { sidebarActiveColor, sidebarTextActiveColor, sidebarTextColor } from '../../Utils/Colors/Colors';
import { ChevronDown } from "lucide-react";

export const useSidebar = () => {
  const navigate = useNavigate();
  const location = useLocation();  
  useVerifyStatus(navigate);
  const isMalwareStarted = useSelector((state) => state.scan.malwareStarted);
  const isResetDefault = useSelector((state) => state.scan.resetDefault);
  const restoreRunning = useSelector((state) => state.scan.restoreRunning);
  // Only one menu can be expanded at a time
  const [expandedMenu, setExpandedMenu] = useState(null);

  // A restore is held to its own screen for the same reason a scan is, and more so: it
  // closes the site to visitors, replaces every file and swaps the database underneath the
  // session running this page. The one screen that says how far it has got and whether the
  // site came back is the only useful place to be while that happens.
  //
  // Matched by prefix, not equality: the folder being restored is in the URL, so a plain
  // comparison against one path would bounce the operator in a loop between the screen
  // they are already on and the one without the folder in it.
  const onRestoreScreen = location.pathname.startsWith("/dashboard/restore");
  const isRestoring = !!restoreRunning;

  useEffect(() => {
    if (isMalwareStarted && location.pathname !== "/dashboard/malwarescanner") {
      navigate("/dashboard/malwarescanner");
      return;
    }

    if (isRestoring && !onRestoreScreen) {
      const folder = restoreRunning.trim();
      navigate(folder ? `/dashboard/restore/${encodeURIComponent(folder)}` : "/dashboard/restore");
    }
  }, [isMalwareStarted, isRestoring, onRestoreScreen, restoreRunning, location.pathname, navigate]);

  const renderNavButton = (to, icon, label, isActiveOverride = false, badge = null) => {
    const isActive = isActiveOverride || location.pathname === to;
    const isDisabled =
      (isResetDefault && to !== "/dashboard/settings/general") ||
      (isMalwareStarted && to !== "/dashboard/malwarescanner") ||
      (isRestoring && !to.startsWith("/dashboard/restore"));

    const handleNavigation = (e) => {
      if (isDisabled) {
        e.preventDefault();
        const process = isMalwareStarted
          ? "Malware Scan"
          : isRestoring
          ? "Restore"
          : isResetDefault
          ? "Reset to Default"
          : "This";
        toast(`${process} is currently in progress. Please wait until the process is completed.`, {
          icon: <GoAlert className="text-yellow-500 text-[40px]" />,
          progressStyle: { background: '#ec5023' },
        });
      }
    };

    return (
      <Link to={to} onClick={handleNavigation} className={`!focus:outline-none !focus:ring-0 !focus:border-0 !focus:shadow-none !focus:text-white !focus:text-current !flex !items-center !space-x-6 ${isDisabled ? "!cursor-not-allowed !opacity-50" : `hover:${sidebarTextActiveColor} hover:bg-gray-700`} ${isActive ? `${sidebarActiveColor} ${sidebarTextActiveColor}` : sidebarTextColor} !rounded !px-3 !py-2 !w-full !border-0 !shadow-none !ring-0 !outline-none !no-underline`} >        
          <span className="text-lg">{icon}</span>  
          {badge ? (
            // The label is never cut: the pill sits beside it, or below it on a font too wide for both.
            <span className="flex flex-1 min-w-0 flex-wrap items-center justify-between gap-x-1.5 gap-y-0.5">
              <span className="text-base leading-4 whitespace-nowrap">{label}</span>
              {badge}
            </span>
          ) : (
            <span className="text-base leading-4">{label}</span>
          )}
      </Link>
    );
  };

  // Toggle menu with auto-collapse of other sections
  const toggleMenu = (menu) => {
    setExpandedMenu((prev) => (prev === menu ? null : menu));
  };

  const renderSectionHeader = (title, isActive, menu) => {
    return (
      <button onClick={() => toggleMenu(menu)} className={`!focus:outline-none !focus:text-indigo-400 !text-left ${isActive ? '!text-white !font-medium' : sidebarTextActiveColor} !flex !justify-between !items-center !w-full !py-3 !space-x-14 !transition-colors !duration-200 hover:bg-gray-800 !rounded px-2`}>
        <p className="!text-sm !leading-5 !uppercase">{title}</p>
        <div className="!flex !items-center">
          {isActive && <div className="!w-2 !h-2 !rounded-full !bg-teal-400 !mr-2"></div>}
          <ChevronDown size={20} className={`transform ${expandedMenu === menu ? "" : "!rotate-180"} !transition-transform`} />
        </div>
      </button>
    );
  };

  return {
    location,
    isMalwareStarted,
    isRestoring,
    renderNavButton,
    renderSectionHeader,
    expandedMenu,
  };
};
