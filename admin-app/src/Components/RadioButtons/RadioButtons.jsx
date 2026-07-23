import { FaLock } from 'react-icons/fa';

const RadioButtons = ({ 
  id, 
  index, 
  value, 
  isLocked, 
  selectedValue, 
  handleChange,
  title,
  description 
}) => {
    const isSelected = selectedValue === value;

    return (
        <div className="!relative" key={index}>
    <div className="!flex !flex-wrap !gap-2">
        <label
            className={`!inline-flex !items-center !border-2 !rounded-lg !p-3 !transition-all !duration-200 hover:!bg-gray-50 !w-full ${
                isSelected
                    ? '!bg-gradient-to-br !from-blue-50 !to-[#85cbcf] !border-[#85cbcf] !shadow-sm'
                    : '!border-gray-200 !bg-white hover:!border-[#85cbcf]'
            } ${isLocked ? '!opacity-90 !cursor-not-allowed' : '!cursor-pointer'}`}
            htmlFor={`${id}-${index}`}
        >
            <div className="!relative !flex !items-center !w-full">
                {/* Hidden native input — handles form state & accessibility */}
                <input
                    type="radio"
                    id={`${id}-${index}`}
                    name={id}
                    disabled={isLocked}
                    value={value}
                    checked={isSelected}
                    onChange={handleChange}
                    className="!sr-only"
                />
                {/* Custom radio indicator — fully WP-safe, no native appearance dependency */}
                <div
                    className={`!w-5 !h-5 !rounded-full !border-2 !flex !items-center !justify-center !flex-shrink-0 !transition-all !duration-200
                        ${isSelected ? '!border-[#85cbcf] !bg-[#85cbcf]' : '!border-gray-300 !bg-white'}
                        ${isLocked ? '!opacity-60' : ''}`}
                >
                    {isSelected && (
                        <div className="!w-2 !h-2 !rounded-full !bg-white !flex-shrink-0" />
                    )}
                </div>
                
                <div className="!ml-3 !flex-1">
                    {title && description ? (
                        <div>
                            <div className={`!font-medium ${isLocked ? '!text-black' : '!text-black'}`}>
                                {title}
                            </div>
                            <div className="!text-sm !text-gray-500">
                                {description}
                            </div>
                        </div>
                    ) : (
                        <span className={`!font-medium !text-sm ${isLocked ? '!cursor-not-allowed !text-black' : '!text-black !cursor-pointer'}`}>
                            {value}
                        </span>
                    )}
                </div>
                
                {isLocked && (
                    <span className="!flex !items-center !ml-2 !text-sm !font-medium !text-black">
                        <span className="!mr-1">Pro</span>
                        <FaLock className="!text-gray-400" />
                    </span>
                )}
            </div>
        </label>
    </div>
</div>
    );
};
export default RadioButtons;
