import React from "react";

const WRAPPER_WIDTH = 48;
const WRAPPER_HEIGHT = 24;
const TRACK_WIDTH = 40;
const TRACK_HEIGHT = 16;
const TRACK_TOP = (WRAPPER_HEIGHT - TRACK_HEIGHT) / 2;
const TRACK_LEFT = (WRAPPER_WIDTH - TRACK_WIDTH) / 2;
const THUMB_SIZE = 24;
const THUMB_TOP = 0;
const THUMB_TRAVEL = WRAPPER_WIDTH - THUMB_SIZE;

const ToggleSwitchComponent = ({ id, label, description, checked, disabled, display, handleCheckboxChange }) => {
  const handleToggleChange = (e) => {
    e.stopPropagation();
    if (!disabled && handleCheckboxChange) {
      handleCheckboxChange(e);
    }
  };

  const trackBg = disabled ? "#d1d5db" : checked ? "#007980" : "#e5e7eb";
  const thumbBg = disabled ? "#9ca3af" : "#ffffff";

  return (
    <label
      className={`inline-flex ${display === "flex" ? "flex-col items-start gap-2" : "items-center"} ${disabled ? "cursor-not-allowed" : "cursor-pointer"}`}
      onClick={(e) => e.stopPropagation()}
      data-switch-id={id}
    >
      {display === "flex" && (
        <span className="text-black font-semibold text-sm">{label}</span>
      )}

      <span
        className="relative inline-block"
        style={{
          width: WRAPPER_WIDTH,
          height: WRAPPER_HEIGHT,
          flexShrink: 0,
        }}
      >
        <input
          type="checkbox"
          id={id}
          className="sr-only"
          checked={!!checked}
          onChange={handleToggleChange}
          disabled={disabled}
        />
        <span
          aria-hidden="true"
          style={{
            position: "absolute",
            top: TRACK_TOP,
            left: TRACK_LEFT,
            width: TRACK_WIDTH,
            height: TRACK_HEIGHT,
            borderRadius: 999,
            backgroundColor: trackBg,
            border: "1px solid #d1d5db",
            boxShadow: "inset 0 1px 2px rgba(0,0,0,0.1)",
            transition: "background-color 0.2s ease",
            boxSizing: "border-box",
          }}
        />
        <span
          aria-hidden="true"
          style={{
            position: "absolute",
            top: THUMB_TOP,
            left: 0,
            width: THUMB_SIZE,
            height: THUMB_SIZE,
            borderRadius: "50%",
            backgroundColor: thumbBg,
            border: "1px solid #9ca3af",
            boxShadow: "0 1px 2px rgba(0,0,0,0.2)",
            transform: `translateX(${checked ? THUMB_TRAVEL : 0}px)`,
            transition: "transform 0.2s ease",
            boxSizing: "border-box",
          }}
        />
      </span>

      {display !== "flex" && (
        <span className="ml-2">{label}</span>
      )}

      {description && (
        <p className="text-xs text-gray-500 mt-1">{description}</p>
      )}
    </label>
  );
};

export default ToggleSwitchComponent;
