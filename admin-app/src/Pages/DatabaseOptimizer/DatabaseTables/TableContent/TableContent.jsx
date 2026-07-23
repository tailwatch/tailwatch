import React, { useRef, useMemo } from 'react';
import { MappingData } from './MappingData.jsx/MappingData';

const formatKeyToTitle = (key) => {
  return key
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const TableContent = ({ steps = {}, licenseConnected = false, proPluginActive = false, currentPlan = null }) => {
  const iconRefs = useRef({});

  const flattenedData = useMemo(() => {
    return Object.entries(steps).map(([key, step]) => {
      if (!iconRefs.current[key]) {
        iconRefs.current[key] = React.createRef();
      }
      return { key, step };
    });
  }, [steps]);

  const getLockStatus = (step) => {
    const requiredPlan = step?.required_plan;
    if (!requiredPlan) return { isProLocked: false };
    if (!licenseConnected) return { isProLocked: true, badge: 'Connect to Tailwach', accessible: false };
    if (!proPluginActive) return { isProLocked: true, badge: 'Upgrade to pro', accessible: false };
    return { isProLocked: true, badge: requiredPlan, accessible: true, planMatched: currentPlan === requiredPlan };
  };

  const handleCardMouseEnter = (key, isActive) => {
    if (iconRefs.current[key]?.current && isActive) {
      iconRefs.current[key].current.play();
    }
  };

  const handleCardMouseLeave = (key, isActive) => {
    if (iconRefs.current[key]?.current && isActive) {
      iconRefs.current[key].current.stop();
    }
  };

  return (
    <div className="w-full">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-4 mt-[30px]">
        {flattenedData.map(({ key, step }) => {
          const itemMapping = MappingData[key] || { description: "No description available", icon: null };
          const pendingCount = step?.pending_count ?? 0;
          const isSelected = !!step?.selected;
          const lockStatus = getLockStatus(step);

          let badgeText = null;
          let badgeClass = '';
          let cardFaded = false;

          if (lockStatus.isProLocked) {
            badgeText = lockStatus.badge;
            if (!lockStatus.accessible) {
              badgeClass = 'text-red-600';
              cardFaded = true;
            } else {
              badgeClass = 'text-[#007980]';
              cardFaded = !isSelected;
            }
          } else if (!isSelected) {
            badgeText = 'Not Enabled';
            badgeClass = 'text-red-600';
            cardFaded = true;
          }

          const cardActive = !cardFaded;

          return (
            <div
              className={`border border-gray-300 rounded-lg shadow-sm p-4 bg-white relative flex items-center transition-transform transform hover:shadow-md ${cardFaded ? "opacity-50 " : ""}`}
              key={key}
              onMouseEnter={() => handleCardMouseEnter(key, cardActive)}
              onMouseLeave={() => handleCardMouseLeave(key, cardActive)}
            >
              <div className="w-8 h-8 sm:w-10 sm:h-10 flex items-center justify-center">
                {itemMapping.icon ? itemMapping.icon(iconRefs.current[key]) : (
                  <div className="w-full h-full bg-gray-200 rounded flex items-center justify-center">
                    <span className="text-gray-500 text-xs">?</span>
                  </div>
                )}
              </div>

              <div className="ml-4">
                <h3 className="text-md sm:text-md font-semibold">
                  {itemMapping.label || formatKeyToTitle(key)}{" "}
                  <span className={pendingCount === 0 ? "text-green-600" : "text-red-600"}>
                    ({pendingCount})
                  </span>
                </h3>
                <p className="text-black text-sm sm:text-base">{itemMapping.description}</p>
              </div>

              {badgeText && (
                <div className="absolute top-1.5 right-1.5 flex items-center justify-center bg-gray-200 bg-opacity-50 px-1.5 py-0.5 rounded">
                  <span className={`${badgeClass} font-medium text-[10px] leading-none`}>{badgeText}</span>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
};
export default TableContent;
