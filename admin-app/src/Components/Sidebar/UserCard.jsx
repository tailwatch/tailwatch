import { User, Settings } from "lucide-react";
import { useNavigate } from "react-router-dom";

const UserCard = ({ loading, userData }) => {
  const navigate = useNavigate();

  const handleSettingsClick = () => {
    navigate("/dashboard/settings/license");
  };

  return (
    <div className="w-full p-4 border-t border-gray-600 space-y-3">
      {!loading && userData ? (
        <div className="flex items-center space-x-2">
          <div className="w-8 h-8 bg-gray-700 rounded-full overflow-hidden flex items-center justify-center flex-shrink-0">
            {userData.profile_picture ? (
              <img
                src={userData.profile_picture}
                alt={userData.name}
                className="w-full h-full object-cover"
              />
            ) : (
              <User size={18} className="text-gray-300" />
            )}
          </div>

          <div className="flex flex-col items-start min-w-0 flex-1">
            <p className="text-sm leading-5 text-white truncate w-full">
              {userData.name ? userData.name : "Admin"}
            </p>
            <p className="text-xs leading-3 text-gray-300 truncate w-full">
              {userData.email ? userData.email : "UserEmail"}
            </p>
          </div>

          <button
            type="button"
            onClick={handleSettingsClick}
            aria-label="Open license settings"
            title="License Settings"
            className="ml-auto flex-shrink-0 p-1.5 rounded-md text-gray-300 hover:text-white hover:bg-gray-700/60 transition-colors duration-200"
          >
            <Settings size={18} />
          </button>
        </div>
      ) : (
        <div className="flex items-center space-x-2">
          <div className="w-8 h-8 bg-gray-700 rounded-full overflow-hidden flex items-center justify-center">
            <User size={18} className="text-gray-300" />
          </div>

          <div className="flex flex-col items-start">
            <p className="text-sm leading-5 text-white">Loading...</p>
          </div>
        </div>
      )}
    </div>
  );
};

export default UserCard;