export const SettingItem = ({ id, label, description, checked, onChange, disabled }) => (
    <div className="bg-white p-2.5 rounded-lg border border-gray-200 transition-all duration-300 hover:border-[#007980] hover:shadow-sm w-full min-w-0">
        <label htmlFor={id} className="flex items-start cursor-pointer">
            <div className="relative mr-2 mt-0.5 flex-shrink-0">
                <input type="checkbox" id={id} className="sr-only" checked={checked} onChange={onChange} disabled={disabled} />
                <div className={`w-4 h-4 border-2 rounded flex items-center justify-center transition-all duration-200 ${checked ? 'bg-[#007980] border-[#007980]' : 'border-gray-300 bg-white'
                    } ${disabled ? 'opacity-50' : ''}`}>
                    {checked && (
                        <svg className="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                        </svg>
                    )}
                </div>
            </div>
            <div className="flex-1 min-w-0">
                <div className="font-semibold text-gray-800 text-sm truncate">{label}</div>
                {description && (
                    <div className="text-xs text-gray-600 leading-snug mt-0.5">{description}</div>
                )}
            </div>
        </label>
    </div>
);