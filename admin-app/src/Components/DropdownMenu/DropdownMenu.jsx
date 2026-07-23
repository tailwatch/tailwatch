import React, { useState, useRef, useEffect, useLayoutEffect } from "react";
import ReactDOM from "react-dom";
import { Tooltip } from "../ToolTip/Tooltip";

const DropdownMenu = ({ id, buttonLabel, menuItems, parentRef }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const dropdownRef = useRef(null);
  const buttonRef = useRef(null);

  const toggleDropdown = () => {
    if (parentRef.current) {
      const buttonRect = parentRef.current.getBoundingClientRect();
      // Initial position: open below. The useLayoutEffect below flips to "above" if the
      // dropdown would otherwise overflow the bottom of the viewport.
      setPosition({
        top: buttonRect.bottom + window.scrollY,
        left: buttonRect.right + window.scrollX - 180
      });
    }
    setIsOpen((prevState) => !prevState);
  };

  // Flip the dropdown upward when there isn't enough room below the trigger.
  // Runs after the dropdown is rendered so we can measure its actual height.
  useLayoutEffect(() => {
    if (!isOpen || !dropdownRef.current || !parentRef.current) return;
    const buttonRect = parentRef.current.getBoundingClientRect();
    const dropdownHeight = dropdownRef.current.offsetHeight;
    const viewportHeight = window.innerHeight;
    const margin = 8;

    const spaceBelow = viewportHeight - buttonRect.bottom;
    const spaceAbove = buttonRect.top;

    let top;
    if (spaceBelow < dropdownHeight + margin && spaceAbove > spaceBelow) {
      // Not enough room below AND more room above → flip up
      top = buttonRect.top + window.scrollY - dropdownHeight - margin;
    } else {
      top = buttonRect.bottom + window.scrollY;
    }
    setPosition((prev) => (prev.top === top ? prev : { ...prev, top }));
  }, [isOpen]);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(event.target) &&
        buttonRef.current &&
        !buttonRef.current.contains(event.target)
      ) {
        setIsOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, []);

  const handleMenuItemClick = (onClick, disabled) => {
    if (disabled) return;
    setIsOpen(false);
    if (onClick) onClick();
  };

  const renderMenuItem = (item, index) => {
    const menuItemButton = (
      <button onClick={() => handleMenuItemClick(item.onClick, item.disabled)}  className={`flex items-center block w-full text-left px-4 py-2 text-sm text-black hover:bg-gray-100 ${item.disabled ? "opacity-50 cursor-not-allowed" : ""}`} disabled={item.disabled}>
        {item.icon && (
          <span className="mr-2 text-lg text-gray-800">{item.icon}</span>
        )}
        {item.label}
      </button>
    );

    if (item.disabled) {
      return (
        <li key={index}>
          <Tooltip message={item?.tooltipMessage} position="top" className="bg-[#ec5023]">
            {menuItemButton}
          </Tooltip>
        </li>
      );
    }

    return <li key={index}>{menuItemButton}</li>;
  };

  const dropdownContent = isOpen ? (
    <div 
      ref={dropdownRef} 
      className="fixed z-[9999] bg-white divide-y divide-gray-100 rounded-lg shadow w-44" 
      style={{ top: position.top, left: position.left }}
    >
      <ul className="py-2 text-sm text-gray-700">
        {menuItems.map((item, index) => renderMenuItem(item, index))}
      </ul>
    </div>
  ) : null;

  return (
    <>
      <button id={`${id}-button`} onClick={toggleDropdown} className="inline-flex items-center p-2 text-sm font-medium text-center text-gray-900 bg-white rounded-lg hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-50" type="button"  ref={buttonRef}>
        <svg className="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 3">
          <path d="M2 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.041 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM14 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z" />
        </svg>
        {buttonLabel}
      </button>

      {isOpen && ReactDOM.createPortal(dropdownContent, document.body)}
    </>
  );
};

export default DropdownMenu;