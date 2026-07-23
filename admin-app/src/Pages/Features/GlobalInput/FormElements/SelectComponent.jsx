import React, { useRef, useEffect, useState } from 'react';
import ReactDOM from 'react-dom';
import { FaInfoCircle } from 'react-icons/fa';
import { Crown, ChevronDown } from 'lucide-react';
import { useFeaturesData } from '../../../../Components/Context/FeaturesDataContext';
import { resolveIsLocked } from '../../../../Components/Utils/HelperFunctions/LockHelper';

const DROPDOWN_MAX_HEIGHT = 220;

const CustomSelect = ({ id, values, computedSelectedValue, proPluginActive, onChange }) => {
    const [open, setOpen] = useState(false);
    const [dropdownStyle, setDropdownStyle] = useState({});
    const triggerRef = useRef(null);
    const dropdownRef = useRef(null);

    const selectedOption = Object.values(values).find(o => o.value === computedSelectedValue);
    const displayLabel = selectedOption ? (selectedOption.label || selectedOption.value) : 'Please select';

    const hasLockedOptions = Object.values(values).some(o => resolveIsLocked(o, proPluginActive));

    useEffect(() => {
        const handleClickOutside = (e) => {
            const insideTrigger = triggerRef.current && triggerRef.current.contains(e.target);
            const insideDropdown = dropdownRef.current && dropdownRef.current.contains(e.target);
            if (!insideTrigger && !insideDropdown) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleToggle = () => {
        if (!open && triggerRef.current) {
            const rect = triggerRef.current.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;
            const openUpward = spaceBelow < DROPDOWN_MAX_HEIGHT && spaceAbove > spaceBelow;

            setDropdownStyle({
                position: 'fixed',
                left: rect.left,
                minWidth: hasLockedOptions ? Math.max(rect.width, 200) : rect.width,
                maxHeight: DROPDOWN_MAX_HEIGHT,
                zIndex: 999999,
                ...(openUpward
                    ? { bottom: window.innerHeight - rect.top + 4 }
                    : { top: rect.bottom + 4 }),
            });
        }
        setOpen(prev => !prev);
    };

    const handleSelect = (option, locked) => {
        if (locked || option?.is_disabled) return;
        onChange({ target: { value: option.value } });
        setOpen(false);
    };

    const dropdown = open && ReactDOM.createPortal(
        <ul
            ref={dropdownRef}
            style={dropdownStyle}
            className="!bg-white !border !border-gray-200 !rounded-lg !shadow-xl !overflow-y-auto !m-0 !p-0 !list-none"
        >
            <li className="!px-3 !py-1.5 !text-xs !text-gray-400 !cursor-default !select-none">Please select</li>
            {Object.values(values).map((option, index) => {
                const optionLocked = resolveIsLocked(option, proPluginActive);
                const isDisabled = optionLocked || option?.is_disabled;
                const isSelected = option.value === computedSelectedValue;
                return (
                    <li
                        key={index}
                        onMouseDown={(e) => e.stopPropagation()}
                        onClick={() => handleSelect(option, optionLocked)}
                        className={`!relative !flex !items-center !px-3 !py-2 !text-sm !transition-colors !overflow-hidden
                            ${isDisabled ? '!cursor-not-allowed' : '!cursor-pointer hover:!bg-[#f0fafa]'}
                            ${isSelected ? '!bg-[#e6f7f8] !text-[#007980] !font-medium' : '!text-black'}`}
                    >
                        <span
                            className={isDisabled && !isSelected ? '!text-gray-400' : ''}
                            style={{ paddingRight: optionLocked ? '52px' : '0px' }}
                        >
                            {option.label || option.value}
                        </span>

                        {optionLocked && (
                            <span className="!absolute !right-0 !top-0 !h-full !flex !items-center !pr-2">
                                <span className="!inline-flex !items-center !gap-0.5 !px-1.5 !py-0.5 !rounded !bg-orange-100 !text-orange-600 !text-[10px] !font-bold !border !border-orange-200">
                                    <Crown className="!w-2.5 !h-2.5" />
                                </span>
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>,
        document.body
    );

    return (
        <div ref={triggerRef} className="!relative !inline-block !min-w-[160px]">
            <button
                type="button"
                id={id}
                onClick={handleToggle}
                className="!w-full !flex !items-center !justify-between !gap-2 !text-black !text-sm !border !border-gray-300 !rounded !px-2 !py-1 !bg-white focus:!outline-none focus:!ring-[1px] focus:!ring-[#007980] !cursor-pointer"
            >
                <span>{displayLabel}</span>
                <ChevronDown size={14} className={`!text-gray-500 !transition-transform !duration-200 ${open ? '!rotate-180' : ''}`} />
            </button>

            {dropdown}
        </div>
    );
};

const SelectComponent = ({ id, label, description, values, selectedValue, handleSelectChange, renderSubOptions, display }) => {
    const { proPluginActive } = useFeaturesData();
    const selectWrapperClass =
        display === "flex" ? "!flex !flex-col !items-start !gap-1" : "!block !p-4 !rounded-lg !border !border-gray-300 !shadow-md";

    const [userSelected, setUserSelected] = useState(false);
    const preselectedOption = Object.values(values).find(option => option.selected);
    const computedSelectedValue =
        userSelected
            ? (selectedValue ?? "")
            : (preselectedOption ? preselectedOption.value : "");
    const selectedOption = Object.values(values).find(option => option.value === computedSelectedValue);
    const hasSubOptions = selectedOption && selectedOption.sub_options;

    const handleChange = (e) => {
        setUserSelected(true);
        handleSelectChange(e);
    };

    return (
        <div className={selectWrapperClass}>
            {display === "flex" ? (
                <>
                    <div className="!flex !flex-row !items-center !gap-[10px]">
                        <div className="!flex !flex-col !flex-shrink-0">
                            <div className="!flex !items-center !mb-1">
                                <label htmlFor={id} className="form-label !text-black !font-semibold !text-sm">
                                    {label}
                                </label>
                                {description && (
                                    <div className="!ml-2 !relative group">
                                        <span className="!absolute bottom-full !mb-2 left-1/2 !transform -translate-x-1/2 !w-44 !bg-gray-800 !text-white !text-xs !rounded !py-1 !px-2 !opacity-0 group-hover:!opacity-100 !transition-opacity !duration-200 !pointer-events-none !z-10">
                                            {description}
                                        </span>
                                        <FaInfoCircle className="!text-gray-500 !cursor-pointer" />
                                    </div>
                                )}
                            </div>
                            <CustomSelect
                                id={id}
                                values={values}
                                computedSelectedValue={computedSelectedValue}
                                proPluginActive={proPluginActive}
                                onChange={handleChange}
                            />
                        </div>

                        {hasSubOptions && (
                            <div className="!flex-grow !pt-[4px]">
                                {renderSubOptions(selectedOption.sub_options, selectedOption.display)}
                            </div>
                        )}
                    </div>
                </>
            ) : (
                <>
                    <label htmlFor={id} className="form-label !text-black !font-semibold !text-lg !block !mb-1">
                        {label}
                    </label>
                    <CustomSelect
                        id={id}
                        values={values}
                        computedSelectedValue={computedSelectedValue}
                        proPluginActive={proPluginActive}
                        onChange={handleChange}
                    />

                    {Object.keys(values).map((key, index) => {
                        if (computedSelectedValue === values[key].value && values[key].sub_options) {
                            return (
                                <div key={index} className="!ml-6">
                                    {renderSubOptions(values[key].sub_options, values[key].display)}
                                </div>
                            );
                        }
                        return null;
                    })}

                    <p className="!text-black !text-sm">{description}</p>
                </>
            )}
        </div>
    );
};

export default SelectComponent;