import React, { useEffect, useState } from 'react';
import { tailwatch } from '../../../Components/Hooks/useImages/useImages';

const steps = [
    { label: 'Verifying', stepKey: 'check_php_version' },
    { label: 'Initializing', stepKey: 'db_initialize' },
    { label: 'Features', stepKey: 'recommended_features' },
    { label: 'Completed', stepKey: 'features_implemented' },
];

const VisitSteps = ({ visitStep }) => {
    const [currentStepIndex, setCurrentStepIndex] = useState(0);

    useEffect(() => {
        const index = steps.findIndex((s) => s.stepKey === visitStep);
        if (index !== -1) {
            setCurrentStepIndex(index);
        }
    }, [visitStep]);

    const progressPercentage = (currentStepIndex / (steps.length - 1)) * 100;

    return (
        <div className="w-full max-w-5xl mx-auto mb-8">
            {/* Logo Header */}
            <div className="flex justify-center mb-12">
                <div className="bg-white border border-teal-200 rounded-2xl shadow-lg px-8 py-6 backdrop-blur-sm bg-opacity-95">
                    <img src={tailwatch} alt="Tailwatch" className="h-20 object-contain" />
                </div>
            </div>

            {/* Progress Steps */}
            <div className="bg-white bg-opacity-95 border border-teal-200 backdrop-blur-sm rounded-2xl shadow-lg p-8">
                <div className="flex justify-between items-center relative">                        
                    {/* Background Progress Line */}
                    <div className="absolute top-6 left-[10%] right-[10%] h-1 bg-gray-200 rounded-full"></div>
                    
                    {/* Active Progress Line */}
                    <div 
                        className="absolute top-6 left-[10%] h-1 bg-gradient-to-r from-teal-500 via-teal-600 to-emerald-500 rounded-full transition-all duration-700 ease-out shadow-lg"
                        style={{ width: `calc(${progressPercentage}% * 0.8)` }}
                    >
                        <div className="absolute right-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-white rounded-full shadow-md"></div>
                    </div>

                    {/* Step Circles */}
                    <div className="flex justify-between w-full relative z-10">
                        {steps.map((step, index) => {
                            const isCompleted = index < currentStepIndex;
                            const isActive = index === currentStepIndex;

                            return (
                                <div key={step.stepKey} className="flex flex-col items-center space-y-3 flex-1">
                                    <div
                                        className={`w-12 h-12 rounded-full flex items-center justify-center transition-all duration-500 transform
                                            ${isCompleted 
                                                ? 'bg-gradient-to-br from-teal-500 to-emerald-600 shadow-xl scale-110' 
                                                : isActive 
                                                    ? 'bg-gradient-to-br from-teal-500 to-emerald-600 shadow-2xl scale-125 ring-4 ring-teal-200' 
                                                    : 'bg-white border-2 border-gray-300 shadow-md'}`}
                                    >
                                        {isCompleted ? (
                                            <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                                            </svg>
                                        ) : isActive ? (
                                            <div className="relative flex items-center justify-center">
                                                <div className="absolute w-full h-full rounded-full border-2 border-white opacity-60 animate-ping"></div>
                                                <svg className="w-6 h-6 text-white relative z-10" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 15.5A3.5 3.5 0 018.5 12 3.5 3.5 0 0112 8.5a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5m7.43-2.92c.04-.34.07-.69.07-1.08s-.03-.73-.07-1.08l2.3-1.77c.21-.16.27-.46.13-.7l-2.18-3.78a.536.536 0 00-.65-.22l-2.71 1.09c-.57-.43-1.17-.79-1.84-1.06l-.42-2.88C14.46 2.22 14.24 2 14 2h-4c-.25 0-.46.18-.5.42l-.41 2.88C8.5 5.59 7.9 5.95 7.33 6.38L4.62 5.29a.506.506 0 00-.65.22L1.79 9.29c-.14.24-.08.54.13.7l2.3 1.77c-.04.35-.07.7-.07 1.08s.03.73.07 1.08l-2.3 1.77c-.21.16-.27.46-.13.7l2.18 3.78c.13.24.41.32.65.22l2.71-1.09c.57.43 1.17.79 1.84 1.06l.41 2.88c.05.24.26.42.5.42h4c.25 0 .46-.18.5-.42l.41-2.88c.67-.27 1.27-.63 1.84-1.06l2.71 1.09c.24.1.52.02.65-.22l2.18-3.78c.14-.24.08-.54-.13-.7l-2.3-1.77z"/>
                                                </svg>
                                            </div>
                                        ) : (
                                            <span className="text-base font-bold text-gray-400">
                                                {index + 1}
                                            </span>
                                        )}
                                    </div>
                                    <span
                                        className={`text-sm font-semibold text-center transition-all duration-300 ${
                                            isCompleted || isActive ? 'text-teal-700' : 'text-gray-500'
                                        }`}
                                    >
                                        {step.label}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default VisitSteps;