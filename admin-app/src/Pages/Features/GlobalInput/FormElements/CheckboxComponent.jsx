import React from 'react';
import { Crown, Sparkles } from 'lucide-react';
import { GiCheckMark } from "react-icons/gi";


// CustomCheckbox component for premium styling
const CustomCheckbox = ({ checked, disabled }) => (
  <div className="relative !w-6 !h-6">
    {/* Checkbox box */}
    <span className={`absolute !inset-0 !rounded-md !border-2 !transition-all !duration-200 ${checked ? '!bg-gradient-to-br !from-blue-50 !to-[#85cbcf] !border !border-[#85cbcf]' : '!bg-white !border-gray-300'} ${disabled ? '!opacity-60' : ''}`}
    ></span>
    {/* Tick icon, half inside and half outside */}
    {checked && (
      <span className="absolute !bottom-[6px] !right-[-6px] !text-gray-500 !drop-shadow-md">
        <GiCheckMark className="!w-6 !h-6" />
      </span>
    )}
  </div>
);

const ComingSoonBadge = () => (
  <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-[#f0fbfb] border border-[#85cbcf]/60 text-[#007980] text-xs font-semibold shadow-sm">
    <Sparkles className="w-3.5 h-3.5 text-[#007980]" />
    <span>Coming Soon</span>
  </span>
);

const ProBadge = ({ planRequired }) => (
  <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-orange-50 border border-orange-200 text-orange-700 text-xs font-semibold shadow-sm">
    <Crown className="w-3.5 h-3.5 text-orange-500" />
    <span>{planRequired || 'Pro'}</span>
  </span>
);

const CheckboxComponent = ({ id, type, label, planRequired, description, checked, disabled, commingSoon, display, handleCheckboxChange }) => {
  const isDisabled = disabled || commingSoon;
  const noopChange = () => {};
  const stopAndBlock = (e) => {
    e.stopPropagation();
    if (commingSoon) e.preventDefault();
  };

  return (
    <>
      {display === 'flex' ? (
        <label htmlFor={id} className={`!flex !flex-col !items-start !gap-1 ${isDisabled ? '!cursor-not-allowed' : '!cursor-pointer'}`} onClick={stopAndBlock}>
          <span className={`!text-sm !font-semibold ${isDisabled ? '!text-gray-400' : '!text-black'}`}>
            {label}
            {commingSoon ? <ComingSoonBadge /> : disabled && <ProBadge planRequired={planRequired} />}
          </span>
          <div className="!relative">
            <input type="checkbox" id={id} className="!hidden" checked={checked} disabled={isDisabled} onChange={commingSoon ? noopChange : handleCheckboxChange} onClick={stopAndBlock} />
            <CustomCheckbox checked={checked} disabled={isDisabled} />
          </div>
        </label>
      ) : (
        <div className={`!p-4 !rounded-lg ${isDisabled ? '!cursor-not-allowed' : '!cursor-pointer'}`}>
          <label className={`!flex !items-center ${isDisabled ? '!cursor-not-allowed' : '!cursor-pointer'}`} onClick={stopAndBlock}>
            <input type="checkbox" id={id} className="!hidden" checked={checked} disabled={isDisabled} onChange={commingSoon ? noopChange : handleCheckboxChange} onClick={stopAndBlock} />
            <CustomCheckbox checked={checked} disabled={isDisabled} />
            <span className={`!ml-2 !text-lg !font-semibold ${isDisabled ? '!text-black' : '!text-black'}`}>
              {label}
              {commingSoon ? <ComingSoonBadge /> : disabled && <ProBadge planRequired={planRequired} />}
            </span>
          </label>
          {description && <p className="!mt-2 !text-black !text-sm !pl-[30px]">{description}</p>}
        </div>
      )}
    </>
  );
};

export default CheckboxComponent;