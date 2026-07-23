import { FaSpinner, FaCheckCircle } from "react-icons/fa";

const ProgressStep = ({ progressValue }) => {
    const isComplete = progressValue >= 100;

    return (
        <div className="relative w-full max-w-5xl mx-auto">
            <div className="relative bg-white bg-opacity-95 backdrop-blur-sm shadow-2xl border-2 border-teal-200 rounded-3xl p-10 overflow-hidden">
                {/* Animated Background Gradient */}
                <div className="absolute inset-0 opacity-50"></div>

                {/* Content */}
                <div className="relative text-center">
                    {/* Icon Section */}
                    <div className="relative inline-block mb-8">
                        {isComplete ? (
                            <div className="relative">
                                <div className="bg-gradient-to-br from-emerald-500 to-teal-600 rounded-full p-2 shadow-2xl">
                                    <FaCheckCircle className="text-white text-4xl animate-bounce-once" />
                                </div>
                                <div className="absolute inset-0 blur-2xl bg-emerald-400 opacity-40 animate-pulse"></div>
                            </div>
                        ) : (
                            <div className="relative">
                                <div className="bg-gradient-to-br from-teal-100 to-emerald-100 rounded-full p-2 border-4 border-teal-200 shadow-xl">
                                    <FaSpinner className="animate-spin text-teal-600 text-4xl" />
                                </div>
                                <div className="absolute inset-0 blur-2xl bg-teal-400 opacity-30 animate-pulse"></div>
                            </div>
                        )}
                    </div>

                    {/* Text Section */}
                    <div className="space-y-4 mb-10">
                        <h2 className="text-3xl font-bold bg-gradient-to-r from-teal-600 to-emerald-600 bg-clip-text text-transparent">
                            {isComplete ? 'Setup Complete!' : 'Setting Up Your Website'}
                        </h2>
                        {!isComplete && (
                            <p className="text-gray-600 text-sm font-medium">
                                Please wait while we configure everything for you...
                            </p>
                        )}
                        <p className="text-teal-700 text-2xl font-bold">
                            {progressValue}% Complete
                        </p>
                    </div>

                    {/* Progress Bar */}
                    <div className="relative">
                        {/* Background Track */}
                        <div className="relative h-4 bg-gray-100 rounded-full border-2 border-gray-200 overflow-hidden shadow-inner">
                            {/* Animated Progress Fill */}
                            <div
                                className="absolute top-0 left-0 h-full bg-gradient-to-r from-teal-500 via-emerald-500 to-teal-600 rounded-full transition-all duration-700 ease-out shadow-lg"
                                style={{ width: `${progressValue}%` }}
                            >
                                {/* Shimmer Effect */}
                                <div className="absolute inset-0 bg-white opacity-20 animate-shimmer"></div>

                                {/* Pulsing Glow */}
                                <div className="absolute inset-0 bg-gradient-to-r from-teal-400 to-emerald-400 opacity-50 animate-pulse"></div>

                                {/* Moving Dot */}
                                {progressValue > 0 && progressValue < 100 && (
                                    <div className="absolute right-0 top-1/2 transform -translate-y-1/2 w-3 h-3 bg-white rounded-full shadow-lg animate-pulse"></div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Success Message */}
                    {isComplete && (
                        <div className="mt-8 p-4 bg-gradient-to-r from-emerald-50 to-teal-50 border-2 border-emerald-200 rounded-2xl animate-fade-in">
                            <p className="text-emerald-700 font-semibold text-lg">
                                🎉 Your website is ready to use!
                            </p>
                        </div>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes shimmer {
                    0% { transform: translateX(-100%); }
                    100% { transform: translateX(100%); }
                }
                .animate-shimmer {
                    animation: shimmer 2s infinite;
                }
                @keyframes fade-in {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in {
                    animation: fade-in 0.6s ease-out;
                }
                @keyframes bounce-once {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.2); }
                }
                .animate-bounce-once {
                    animation: bounce-once 0.8s ease-out;
                }
            `}</style>
        </div>
    );
};

export default ProgressStep;