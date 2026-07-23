import React, { useState } from "react";

const IconButton = ({ 
  icon: Icon, 
  text, 
  onClick, 
  disabled = false,
  bgColor = "bg-gray-300", // Default background color
  hoverBgColor = "hover:bg-gray-400", // Default hover background color
  textColor = "text-gray-800", // Default text color
  className = "", // Additional custom classes
  roundedFull = false, // Option for rounded-full style
  tooltip = "" // Optional tooltip
}) => {
  const [showTooltip, setShowTooltip] = useState(false);

  return (
    <div className="relative inline-block">
      <button
        className={`
          ${bgColor} 
          ${hoverBgColor} 
          ${textColor} 
          font-[500] 
          !py-2 
          !px-3
          ${roundedFull ? "rounded-full" : "rounded-[5px]"} 
          inline-flex 
          items-center 
          ${disabled ? "bg-gray-400 cursor-not-allowed text-white" : ""}
          ${className}
        `}
        onClick={disabled ? undefined : onClick}
        disabled={disabled}
        onMouseEnter={() => setShowTooltip(true)}
        onMouseLeave={() => setShowTooltip(false)}
      >
        {Icon && (
          <Icon className={`${roundedFull ? "w-4 h-4" : "fill-current w-4 h-4 mr-2"}`} />
        )}
        {(!roundedFull || text) && <span>{text}</span>}
      </button>

      {/* Tooltip positioned to the left */}
      {tooltip && showTooltip && (
        <div className="absolute right-full top-1/2 transform -translate-y-1/2 -mr-2 px-3 py-1 bg-black text-white text-xs rounded shadow-lg whitespace-nowrap">
          {tooltip}
        </div>
      )}
    </div>
  );
};

export default IconButton;
