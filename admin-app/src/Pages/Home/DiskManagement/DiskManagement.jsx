import { useRef } from 'react';
import { Table, RefreshCcw, Database } from "lucide-react";
import IconButton from "../../../Components/Buttons/IconButton";
import Skeleton from "react-loading-skeleton";
import { otherGradient, bgprimary, iconprimary, primaryGreen } from '../../../Components/Utils/Colors/Colors'

const DiskManagement = ({ data, isLoading, fetchData }) => {
  const lordIconRef = useRef();

  const handleCardMouseEnter = () => {
    if (lordIconRef.current) lordIconRef.current.play();
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) lordIconRef.current.stop();
  };

  if (isLoading || !data?.database) {
    return (
      <div className="py-6">
        <Skeleton height={447} borderRadius={12} />
      </div>
    );
  }

  return (
    <div
      className={`rounded-xl shadow-lg ${otherGradient} border border-[#85cbcf] overflow-hidden transition-all duration-300 hover:shadow-xl p-4 sm:p-6 h-full flex flex-col`}
      onMouseEnter={handleCardMouseEnter}
      onMouseLeave={handleCardMouseLeave}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-4 gap-2">
        <div className="flex items-center gap-2 sm:gap-3 min-w-0 flex-1">
          <div className={`w-9 h-9 sm:w-10 sm:h-10 rounded-full bg-[${primaryGreen}] flex items-center justify-center flex-shrink-0`}>
            <Database size={24} className="text-[#007980]" />
          </div>
          <div className="min-w-0">
            <h2 className="text-base sm:text-lg font-semibold text-black leading-tight">Database Storage</h2>
            <p className="text-xs sm:text-sm text-black hidden sm:block">
              Track website database tables and storage usage.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
          <div className={`py-1 px-2 sm:px-3 text-xs font-medium text-white rounded-lg ${bgprimary} whitespace-nowrap`}>
            {data?.database?.totalSize}
          </div>
          <IconButton
            onClick={fetchData}
            icon={RefreshCcw}
            bgColor={`bg-[${primaryGreen}]`}
            hoverBgColor="hover:bg-[#07C07E1A]"
            textColor={iconprimary}
            roundedFull={true}
            className="!p-2 !rounded-[5px] transition duration-200 hover:shadow-sm"
          />
        </div>
      </div>

      {/* Table list */}
      <div className="flex-1 overflow-y-auto custom-scroll-bar max-h-[300px] sm:max-h-[350px] md:max-h-[383px] pr-1">
        <ul className="space-y-2">
          {data?.database?.tables?.map((table, index) => (
            <li
              key={index}
              className="bg-white rounded-lg px-3 py-2 sm:py-3 flex items-center justify-between shadow-sm hover:shadow-md transition border border-[#07C07E1A] gap-2"
            >
              {/* Table name */}
              <div className="flex items-center gap-2 min-w-0 flex-1">
                <div className="w-6 h-6 rounded-full bg-[#07C07E1A] flex items-center justify-center flex-shrink-0">
                  <Table className={iconprimary} size={12} />
                </div>
                <span className="text-xs sm:text-sm text-black truncate">{table?.name}</span>
              </div>

              {/* Badges */}
              <div className="flex items-center gap-1.5 sm:gap-2 flex-shrink-0">
                <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 whitespace-nowrap">
                  {table?.size}
                </span>
                <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-[#07C07E1A] text-[#007980] whitespace-nowrap hidden sm:inline">
                  {table?.rows} rows
                </span>
                <span className="text-xs font-medium px-1.5 py-0.5 rounded-full bg-[#07C07E1A] text-[#007980] whitespace-nowrap sm:hidden">
                  {table?.rows}r
                </span>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

export default DiskManagement;
