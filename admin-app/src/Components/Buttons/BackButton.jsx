import React from 'react';
import { useNavigate } from 'react-router-dom';
import { IoCaretBackOutline } from "react-icons/io5";

const BackButton = ({ text = "Back", onClick }) => {
  const navigate = useNavigate();

  const handleClick = () => {
    if (onClick) {
      onClick();
    } else {
      navigate(-1);
    }
  };

  return (
    <div
      onClick={handleClick}
      className="cursor-pointer inline-flex items-center border border-[#5a5a5a] px-[7px] py-[4px] rounded-md text-[#007980] hover:bg-gray-200 hover:border-gray-300 hover:bg-opacity-80"
    >
      <IoCaretBackOutline className="h-6 w-6" />
      <span className="ml-1 font-bold text-lg">{text}</span>
    </div>
  );
};

export default BackButton;
