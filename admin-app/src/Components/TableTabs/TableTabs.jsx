import React, { useState, useRef, useEffect } from "react";

const TableTabs = ({ tabs, activeTab, setActiveTab, isDisabled }) => {
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [visibleTabCount, setVisibleTabCount] = useState(6);
  const moreRef = useRef(null);

  useEffect(() => {
    const handleResize = () => {
      const width = window.innerWidth;
      if (width < 640) {
        setVisibleTabCount(2); // Mobile: show 2 tabs + More
      } else if (width < 768) {
        setVisibleTabCount(3); // Small tablet: show 3 tabs + More
      } else if (width < 1024) {
        setVisibleTabCount(4); // Tablet: show 4 tabs + More
      } else {
        setVisibleTabCount(6); // Desktop: show 6 tabs + More
      }
    };

    handleResize();
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  useEffect(() => {
    if (isDropdownOpen) {
      const handleClickOutside = (event) => {
        if (moreRef.current && !moreRef.current.contains(event.target)) {
          setIsDropdownOpen(false);
        }
      };
      document.addEventListener("click", handleClickOutside);
      return () => {
        document.removeEventListener("click", handleClickOutside);
      };
    }
  }, [isDropdownOpen]);

  const activeTabObj = tabs.find((tab) => tab.key === activeTab);
  let visibleTabs, moreTabs;

  if (activeTabObj && tabs.slice(0, visibleTabCount).some((tab) => tab.key === activeTab)) {
    visibleTabs = tabs.slice(0, visibleTabCount);
    moreTabs = tabs.slice(visibleTabCount);
  } else {
    const firstN = tabs.slice(0, visibleTabCount - 1);
    visibleTabs = activeTabObj ? [...firstN, activeTabObj] : tabs.slice(0, visibleTabCount);
    moreTabs = tabs.filter((tab) => !visibleTabs.includes(tab));
  }

  return (
    <div className="mb-3 sm:mb-4 border-b border-gray-200 dark:border-gray-700">
      <ul className="flex flex-wrap -mb-px text-xs sm:text-sm font-medium text-center" role="tablist">
        {visibleTabs.map((tab) => (
          <li key={tab.key} className="" role="presentation">
            <button
              disabled={isDisabled}
              className={`relative inline-block p-2 sm:p-3 md:p-4 border-b-2 rounded-t-lg transition-colors ${isDisabled
                  ? "opacity-50 cursor-not-allowed border-transparent text-gray-400"
                  : activeTab === tab.key
                    ? "border-[#007980] text-[#007980]"
                    : "border-transparent text-black hover:text-[#007980] hover:border-[#007980]"
                }`}
              onClick={() => !isDisabled && setActiveTab(tab.key)}
              type="button"
              role="tab"
            >

              <span className="whitespace-nowrap">{tab.label}</span>
              {tab.count > 0 && (
                <span className="absolute inline-flex items-center justify-center w-4 h-4 sm:w-5 sm:h-5 text-[10px] sm:text-xs font-bold text-white bg-[#48bcc2] border-2 border-white rounded-full -top-[0.1rem] -right-[0.2rem] sm:-right-[0.3rem]">
                  {tab.count}
                </span>
              )}
            </button>
          </li>
        ))}
        {moreTabs.length > 0 && (
          <li ref={moreRef} className="relative" role="presentation">
            <button
              disabled={isDisabled}
              className={`relative inline-flex items-center p-2 sm:p-3 md:p-4 border-b-2 rounded-t-lg transition-colors ${isDisabled
                  ? "opacity-50 cursor-not-allowed border-transparent text-gray-400"
                  : moreTabs.some((tab) => tab.key === activeTab)
                    ? "border-[#007980] text-[#007980]"
                    : "border-transparent text-black hover:text-[#007980] hover:border-[#007980]"
                }`}
              onClick={() => {
                if (!isDisabled) {
                  setIsDropdownOpen(!isDropdownOpen);
                }
              }}
              type="button"
            >

              <span className="whitespace-nowrap">More</span>
              <svg
                aria-hidden="true"
                className="w-3 h-3 sm:w-[14px] sm:h-[14px] ml-1 inline transition-transform"
                style={{ transform: isDropdownOpen ? 'rotate(180deg)' : 'rotate(0deg)' }}
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                xmlns="http://www.w3.org/2000/svg"
              >
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            {isDropdownOpen && (
              <div className="absolute left-0 sm:left-auto sm:right-0 mt-2 w-full min-w-[180px] sm:w-48 bg-white border border-gray-200 rounded shadow-lg z-10 max-h-64 overflow-y-auto">
                <ul>
                  {moreTabs.map((tab) => (
                    <li key={tab.key}>
                      <button
                        className={`w-full text-left px-3 sm:px-4 py-2 hover:bg-gray-100 transition-colors text-xs sm:text-sm ${activeTab === tab.key ? "text-[#007980] bg-gray-50" : "text-black"
                          }`}
                        onClick={() => {
                          setActiveTab(tab.key);
                          setIsDropdownOpen(false);
                        }}
                      >
                        <span className="whitespace-nowrap">{tab.label}</span>
                        {tab.count > 0 && (
                          <span className="ml-2 inline-flex items-center justify-center w-4 h-4 text-[10px] sm:text-xs font-bold text-white bg-[#48bcc2] rounded-full">
                            {tab.count}
                          </span>
                        )}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </li>
        )}
      </ul>
    </div>
  );
};

export default TableTabs;