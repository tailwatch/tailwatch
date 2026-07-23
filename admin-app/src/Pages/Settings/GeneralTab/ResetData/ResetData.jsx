import { useRef, useState } from 'react';
import { RotateCcw, Loader2 } from 'lucide-react';
import ResetOptionsModal from './ResetOptionsModal';

const ResetData = ({ handleResetAllSettings, loading, isReset }) => {
    const lordIconRef = useRef();
    const [isModalOpen, setIsModalOpen] = useState(false);

    const handleCardMouseEnter = () => {
    if (lordIconRef.current) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) {
      lordIconRef.current.stop();
    }
  };

  const isButtonDisabled = loading || isReset;

  const handleConfirm = async ({ resetSettings, resetData }) => {
    await handleResetAllSettings({ resetSettings, resetData });
    setIsModalOpen(false);
  };

    return (
        <>
        <div className="bg-white border w-full border-red-300 rounded-xl p-6 shadow-sm hover:shadow-md transition-shadow" onMouseEnter={handleCardMouseEnter} onMouseLeave={handleCardMouseLeave}>

            <div className="flex items-start gap-4">
                <div className="flex-shrink-0 w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <RotateCcw size={28} className="text-red-600" />
                </div>
                <div className="flex-1">
                    <h3 className="text-lg font-semibold text-gray-900 mb-2">Reset Data</h3>
                    <p className="text-gray-600 mb-4">
                        Permanently delete all features configuration settings and plugin data.
                        This action cannot be undone.
                    </p>
                    <div className="flex items-center gap-3">
                        <button onClick={() => setIsModalOpen(true)} disabled={isButtonDisabled}
                            className={`flex items-center gap-2 px-4 py-2 ${isButtonDisabled ? 'bg-gray-300 text-white cursor-not-allowed' : 'bg-red-600 text-white'} rounded-lg hover:bg-red-700 transition-colors`}
                        >
                            {isReset ? (
                                <Loader2 className="w-4 h-4 animate-spin" />
                            ) : (
                                <RotateCcw className="w-4 h-4" />
                            )}
                            {isReset ? 'Resetting...' : 'Reset Data'}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ResetOptionsModal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          onConfirm={handleConfirm}
          isSubmitting={isReset}
        />
        </>
    )
}

export default ResetData