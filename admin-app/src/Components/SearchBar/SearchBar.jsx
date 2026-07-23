import React from "react";
import { FaSearch } from "react-icons/fa";

const SearchBar = ({ searchQuery, setSearchQuery }) => {
  return (
    <div className="!relative !w-full">
      <div className="!absolute !inset-y-0 !left-0 !pl-3 !flex !items-center !pointer-events-none">
        <FaSearch className="!text-gray-500" />
      </div>
      <input
        value={searchQuery}
        onChange={(e) => setSearchQuery(e.target.value)}
        type="text"
        id="simple-search"
        className="!block !w-full !p-2 !pl-10 !text-sm !text-gray-200 !border !border-gray-700 !rounded-lg !bg-gray-700 !placeholder-gray-400 focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980] !shadow-sm !outline-none !transition-all"
        placeholder="Search"
      />
    </div>
  );
};

export default SearchBar;