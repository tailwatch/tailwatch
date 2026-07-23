import React from "react";
import { ChevronLeft, ChevronRight, MoreHorizontal } from "lucide-react";
const bgprimary = "bg-[#007980]";

const Pagination = ({ 
  currentPage, 
  totalPages, 
  onPageChange, 
  hasData,
  showPageSizeFilter = false,
  pageSize = 10,
  onPageSizeChange,
  totalItems = 0,
  pageSizeOptions = [5, 10, 20, 50, 100]
}) => {
  const handlePrevious = () => {
    if (currentPage > 0) {
      onPageChange(currentPage - 1);
    }
  };

  const handleNext = () => {
    if (currentPage < totalPages - 1) {
      onPageChange(currentPage + 1);
    }
  };

  const getPageNumbers = () => {
    const pageNumbers = [];
    const isMobile = window.innerWidth < 640;
    const maxPages = isMobile ? 5 : 7;
    
    if (totalPages <= maxPages) {
      // Show all pages if total is within limit
      for (let i = 0; i < totalPages; i++) {
        pageNumbers.push(i);
      }
    } else {
      // Always show first page
      pageNumbers.push(0);
      
      if (isMobile) {
        // Mobile: simplified pagination
        if (currentPage <= 2) {
          pageNumbers.push(1, 2);
          pageNumbers.push("ellipsis");
        } else if (currentPage >= totalPages - 3) {
          pageNumbers.push("ellipsis");
          pageNumbers.push(totalPages - 2, totalPages - 1);
        } else {
          pageNumbers.push("ellipsis1");
          pageNumbers.push(currentPage);
          pageNumbers.push("ellipsis2");
        }
      } else {
        // Desktop: full pagination
        if (currentPage <= 3) {
          pageNumbers.push(1, 2, 3, 4);
          pageNumbers.push("ellipsis");
        } else if (currentPage >= totalPages - 4) {
          pageNumbers.push("ellipsis");
          for (let i = totalPages - 5; i < totalPages; i++) {
            pageNumbers.push(i);
          }
        } else {
          pageNumbers.push("ellipsis1");
          pageNumbers.push(currentPage - 1, currentPage, currentPage + 1);
          pageNumbers.push("ellipsis2");
        }
      }
      
      // Always show last page (if not already included)
      if (!pageNumbers.includes(totalPages - 1)) {
        pageNumbers.push(totalPages - 1);
      }
    }
    
    return pageNumbers.filter((page, index, arr) => arr.indexOf(page) === index);
  };

  const startItem = currentPage * pageSize + 1;
  const endItem = Math.min((currentPage + 1) * pageSize, totalItems);

  if (!hasData) {
    return null;
  }

  return (
    <div className="flex flex-col gap-3 sm:gap-4 mt-6 sm:mt-8">
      {/* Page Size Filter */}
      {showPageSizeFilter && (
        <div className="flex flex-col lg:grid lg:grid-cols-3 gap-3 sm:gap-4 items-stretch lg:items-center">
          {/* Left: Page size controls */}
          <div className="flex items-center gap-2 sm:gap-3 justify-start">
            <span className="text-xs sm:text-sm font-medium text-gray-700 whitespace-nowrap">Show</span>
            <select
              value={pageSize}
              onChange={(e) => onPageSizeChange?.(Number(e.target.value))}
              className="px-2 sm:px-3 py-1.5 sm:py-2 text-xs sm:text-sm border border-gray-300 rounded-lg bg-white text-gray-700 hover:border-gray-400 focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] transition-colors"
            >
              {pageSizeOptions.map(size => (
                <option key={size} value={size}>{size}</option>
              ))}
            </select>
            <span className="text-xs sm:text-sm font-medium text-gray-700 whitespace-nowrap">entries</span>
          </div>

          {/* Center: Pagination Controls */}
          <div className="flex flex-wrap justify-center items-center gap-1 sm:gap-2 lg:col-start-2">
            {/* Previous button */}
            <button 
              className="flex items-center justify-center px-2 sm:px-3 md:px-4 py-1.5 sm:py-2 rounded-lg border border-gray-300 bg-white text-xs sm:text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-400 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-white disabled:hover:border-gray-300 transition-all duration-200 shadow-sm hover:shadow-md whitespace-nowrap" 
              onClick={handlePrevious} 
              disabled={currentPage === 0}
            >
              <ChevronLeft className="w-3 h-3 sm:w-4 sm:h-4 mr-0.5 sm:mr-1" />
              <span className="hidden sm:inline">Previous</span>
              <span className="sm:hidden">Prev</span>
            </button>

            {/* Page numbers */}
            <div className="flex items-center gap-0.5 sm:gap-1">
              {getPageNumbers().map((pageNum, index) => {
                if (typeof pageNum === "string" && pageNum.includes("ellipsis")) {
                  return (
                    <div key={`ellipsis-${index}`} className="flex items-center justify-center w-6 h-6 sm:w-8 sm:h-8 md:w-10 md:h-10">
                      <MoreHorizontal className="w-4 h-4 sm:w-5 sm:h-5 text-gray-400" />
                    </div>
                  );
                }

                const isActive = currentPage === pageNum;
                
                return (
                  <button 
                    key={pageNum} 
                    onClick={() => onPageChange(pageNum)} 
                    className={`flex items-center justify-center w-6 h-6 sm:w-8 sm:h-8 md:w-10 md:h-10 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200 ${
                      isActive 
                        ? `${bgprimary} text-white shadow-lg hover:shadow-xl transform hover:scale-105` 
                        : "text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 hover:border-gray-400 shadow-sm hover:shadow-md"
                    }`}
                  >
                    {pageNum + 1}
                  </button>
                );
              })}
            </div>

            {/* Next button */}
            <button 
              className="flex items-center justify-center px-2 sm:px-3 md:px-4 py-1.5 sm:py-2 rounded-lg border border-gray-300 bg-white text-xs sm:text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-400 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-white disabled:hover:border-gray-300 transition-all duration-200 shadow-sm hover:shadow-md whitespace-nowrap" 
              onClick={handleNext} 
              disabled={currentPage >= totalPages - 1}
            >
              <span className="hidden sm:inline">Next</span>
              <span className="sm:hidden">Next</span>
              <ChevronRight className="w-3 h-3 sm:w-4 sm:h-4 ml-0.5 sm:ml-1" />
            </button>
          </div>

          {/* Right: Results info */}
          {totalItems > 0 && (
            <div className="text-xs sm:text-sm text-gray-600 text-center lg:text-right lg:justify-self-end lg:col-start-3">
              <span className="block sm:inline">Showing <span className="font-medium text-gray-900">{startItem}</span> to{" "}
              <span className="font-medium text-gray-900">{endItem}</span></span>
              <span className="block sm:inline sm:ml-1">of <span className="font-medium text-gray-900">{totalItems}</span> results</span>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default Pagination;