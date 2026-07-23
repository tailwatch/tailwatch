import React, { useRef } from 'react';

const FilterTabs = ({ activeCategory, setActiveCategory, counts, setSearchTerm, showPushNotificationToggle, pushNotificationToggle }) => {
  const scrollRef = useRef(null);
  const dragState = useRef({ isDown: false, startX: 0, scrollLeft: 0, moved: 0 });

  const onMouseDown = (e) => {
    const el = scrollRef.current;
    if (!el) return;
    dragState.current.isDown = true;
    dragState.current.startX = e.pageX - el.offsetLeft;
    dragState.current.scrollLeft = el.scrollLeft;
    dragState.current.moved = 0;
    el.style.cursor = 'grabbing';
  };

  const endDrag = () => {
    const el = scrollRef.current;
    dragState.current.isDown = false;
    if (el) el.style.cursor = 'grab';
  };

  const onMouseMove = (e) => {
    if (!dragState.current.isDown) return;
    const el = scrollRef.current;
    if (!el) return;
    e.preventDefault();
    const x = e.pageX - el.offsetLeft;
    const walk = x - dragState.current.startX;
    dragState.current.moved = Math.max(dragState.current.moved, Math.abs(walk));
    el.scrollLeft = dragState.current.scrollLeft - walk;
  };

  const onClickCapture = (e) => {
    if (dragState.current.moved > 5) {
      e.preventDefault();
      e.stopPropagation();
    }
  };
   const categories = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active Features' },
    { id: 'security', label: 'Security' },
    { id: 'optimization', label: 'Optimization' },
    { id: 'performance', label: 'Performance' },
    { id: 'recovery', label: 'Recovery' },
    { id: 'monitoring', label: 'Monitoring' },
  ];


  const handleTabClick = (categoryId) => {
    setActiveCategory(categoryId);
    setSearchTerm('');
  };

  return (
    <div className="mb-3 sm:mb-4">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 sm:gap-4">
        <div
          ref={scrollRef}
          onMouseDown={onMouseDown}
          onMouseMove={onMouseMove}
          onMouseUp={endDrag}
          onMouseLeave={endDrag}
          onClickCapture={onClickCapture}
          className="filter-tabs-scroll flex space-x-1.5 sm:space-x-2 overflow-x-auto w-full sm:w-auto select-none"
          style={{ scrollbarWidth: 'none', msOverflowStyle: 'none', cursor: 'grab' }}
        >
          {categories.map((category) => (
            <button
              key={category.id}
              onClick={() => handleTabClick(category.id)}
              className={`px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-lg font-medium transition-all text-xs sm:text-sm whitespace-nowrap ${activeCategory === category.id
                  ? 'bg-[#007980] text-white shadow-md'
                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
            >
              {category.label}
              {counts && counts[category.id] > 0 && (
                <span className={`ml-1.5 sm:ml-2 px-1.5 sm:px-2 py-0.5 text-[10px] sm:text-xs rounded-full ${activeCategory === category.id
                    ? 'bg-white text-[#007980]'
                    : 'bg-gray-300 text-gray-700'
                  }`}>
                  {counts[category.id]}
                </span>
              )}
            </button>
          ))}
        </div>
        {showPushNotificationToggle && (
          <div className="w-full sm:w-auto">
            {pushNotificationToggle}
          </div>
        )}
      </div>
      <style>{`.filter-tabs-scroll::-webkit-scrollbar { display: none; }`}</style>
    </div>
  );
};

export default FilterTabs;