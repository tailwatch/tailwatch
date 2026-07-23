import React, { useState, useEffect, useRef } from 'react';
import { getSheduleCron } from '../../Pages/CronJob/CronJobServices/CronJobServices';
import { ChevronDown } from 'lucide-react';

const GetCronSchedule = ({ slug, onOptionSelect }) => {
    const [scheduleCronData, setScheduleCronData] = useState([]);
    const [loading, setLoading] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const [selectedOption, setSelectedOption] = useState(null);
    const [pagination, setPagination] = useState({ page: 1, limit: 10, total_pages: 0, total_schedules: 0 });
    const dropdownRef = useRef(null);
    const optionsContainerRef = useRef(null);    
        
    useEffect(() => {
        fetchScheduleCronData();
    }, []);

    useEffect(() => {
        if (slug && scheduleCronData.length > 0) {
            const matchingOption = scheduleCronData.find(option => option.slug === slug);
            if (matchingOption) {
                setSelectedOption(matchingOption);
                if (onOptionSelect) {
                    onOptionSelect(matchingOption);
                }
            }
        }
    }, [slug, scheduleCronData]);

    const fetchScheduleCronData = async (page = 1) => {
        await getSheduleCron({
            setScheduleCronData: (data) => {
                if (page === 1) {
                    setScheduleCronData(data);
                } else {
                    setScheduleCronData(prev => [...prev, ...data]);
                }
            },
            setLoading, page, limit: 10, setPagination
        });
    };

    const handleScroll = () => {
        if (optionsContainerRef.current) {
            const { scrollTop, scrollHeight, clientHeight } = optionsContainerRef.current;
            if (scrollHeight - scrollTop <= clientHeight + 10) {
                if (pagination.page < pagination.total_pages && !loading) {
                    fetchScheduleCronData(pagination.page + 1);
                }
            }
        }
    };

    const toggleDropdown = () => {
        if (scheduleCronData.length > 0) {
            setIsOpen(!isOpen);
            if (!isOpen) {
                if (!selectedOption && scheduleCronData.length > 0) {
                    const firstOption = scheduleCronData[0];
                    setSelectedOption(firstOption);
                    if (onOptionSelect) {
                        onOptionSelect(firstOption);
                    }
                }

                setTimeout(() => {
                    const targetOption = selectedOption || scheduleCronData[0];
                    if (targetOption) {
                        const selectedElement = optionsContainerRef.current?.querySelector(`[data-slug="${targetOption.slug}"]`);
                        if (selectedElement) {
                            selectedElement.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        }
                    }
                }, 100);
            }
        }
    };

    const selectOption = (option) => {
        setSelectedOption(option);
        setIsOpen(false);
        if (onOptionSelect) {
            onOptionSelect(option);
        }
    };

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    return (
        <div className="w-full relative" ref={dropdownRef}>
            <div className={`p-3 border rounded-md flex justify-between items-center cursor-pointer bg-white ${scheduleCronData.length === 0 ? 'opacity-70 cursor-not-allowed' : ''}`} onClick={toggleDropdown} >
                <span>{selectedOption ? selectedOption.display : loading ? 'Loading...' : 'Select Schedule'}</span>
                <ChevronDown size={20} className={`transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`} />
            </div>

            {isOpen && (
                <div className="absolute z-10 mt-1 w-full bg-white border rounded-md shadow-lg max-h-60 overflow-y-auto" ref={optionsContainerRef} onScroll={handleScroll} >
                    {scheduleCronData.map((option, index) => (
                        <div
                            key={`${option.slug}-${index}`}
                            className={`p-2 hover:bg-gray-100 cursor-pointer ${selectedOption?.slug === option.slug ? 'bg-gray-50' : ''}`}
                            onClick={() => selectOption(option)}
                            data-slug={option.slug}
                        >
                            {option.display}
                        </div>
                    ))}

                    {loading && (
                        <div className="p-2 text-center text-gray-500">
                            Loading more options...
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default GetCronSchedule;