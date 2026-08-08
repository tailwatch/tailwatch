import { useEffect, useState } from 'react';
import { AlertTriangle, Cog, Database, Loader2, RotateCcw, X, Check } from 'lucide-react';

const OptionCard = ({ id, icon: Icon, title, description, checked, onToggle, disabled }) => (
  <label
    htmlFor={id}
    className={`relative flex gap-3 rounded-lg border p-4 transition-all cursor-pointer select-none ${
      checked
        ? 'border-[#007980] bg-[#007980]/5 ring-1 ring-[#007980]/30'
        : 'border-gray-200 bg-white hover:border-gray-300'
    } ${disabled ? 'opacity-60 cursor-not-allowed' : ''}`}
  >
    <div
      className={`flex-shrink-0 mt-0.5 w-5 h-5 rounded border flex items-center justify-center transition-colors ${
        checked ? 'bg-[#007980] border-[#007980]' : 'bg-white border-gray-300'
      }`}
    >
      {checked && <Check className="w-3.5 h-3.5 text-white" strokeWidth={3} />}
    </div>
    <input
      type="checkbox"
      id={id}
      className="sr-only"
      checked={checked}
      onChange={onToggle}
      disabled={disabled}
    />
    <div className="flex-1 min-w-0">
      <div className="flex items-center gap-2 mb-1">
        <Icon className={`w-4 h-4 ${checked ? 'text-[#007980]' : 'text-gray-500'}`} />
        <span className="text-sm font-semibold text-gray-900">{title}</span>
      </div>
      <p className="text-xs text-gray-600 leading-relaxed">{description}</p>
    </div>
  </label>
);

const ResetOptionsModal = ({ isOpen, onClose, onConfirm, isSubmitting }) => {
  const [resetSettings, setResetSettings] = useState(false);
  const [resetData, setResetData] = useState(false);

  // Reset selections whenever the modal is (re)opened so a prior state doesn't linger.
  useEffect(() => {
    if (isOpen) {
      setResetSettings(false);
      setResetData(false);
    }
  }, [isOpen]);

  if (!isOpen) return null;

  const canSubmit = (resetSettings || resetData) && !isSubmitting;

  const handleSubmit = () => {
    if (!canSubmit) return;
    onConfirm({ resetSettings, resetData });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden">
        <div className="flex items-start justify-between px-6 pt-5 pb-3 border-b border-gray-100">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
              <RotateCcw className="w-5 h-5 text-red-600" />
            </div>
            <div>
              <h3 className="text-base font-semibold text-gray-900">Reset Options</h3>
              <p className="text-xs text-gray-500">Choose what to reset. At least one is required.</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            aria-label="Close"
            className="!text-gray-400 hover:!text-gray-600 hover:!bg-gray-100 rounded-lg !p-1.5 transition-colors disabled:opacity-50"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="px-6 py-4 space-y-3">
          <OptionCard
            id="tailwatch-reset-settings"
            icon={Cog}
            title="Reset Settings"
            description="Restore all plugin settings and feature configurations to their default values."
            checked={resetSettings}
            onToggle={() => setResetSettings((v) => !v)}
            disabled={isSubmitting}
          />
          <OptionCard
            id="tailwatch-reset-data"
            icon={Database}
            title="Reset Data"
            description="Permanently delete all captured data — scan history, logs, blocked IPs, and monitoring records."
            checked={resetData}
            onToggle={() => setResetData((v) => !v)}
            disabled={isSubmitting}
          />

          <div className="flex items-start gap-2 rounded-lg bg-red-50 border border-red-100 px-3 py-2">
            <AlertTriangle className="w-4 h-4 text-red-600 mt-0.5 flex-shrink-0" />
            <p className="text-xs text-red-700 leading-relaxed">
              This action cannot be undone. Please make sure before proceeding.
            </p>
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 px-6 py-4 bg-gray-50 border-t border-gray-100">
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="!px-4 !py-2 !text-sm !font-medium !text-gray-700 !bg-white !border !border-gray-300 !rounded-lg hover:!bg-gray-50 transition-colors disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canSubmit}
            className={`inline-flex items-center gap-2 !px-4 !py-2 !text-sm !font-medium !text-white !rounded-lg transition-colors ${
              canSubmit
                ? '!bg-red-600 hover:!bg-red-700'
                : '!bg-gray-300 !cursor-not-allowed'
            }`}
          >
            {isSubmitting ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                Starting...
              </>
            ) : (
              <>
                <RotateCcw className="w-4 h-4" />
                Confirm Reset
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ResetOptionsModal;
