import React, { useState } from "react";
import { toast } from "react-toastify";
import { History } from "lucide-react";
import { alertService } from "../AlertService/AlertService";
import { useSavedProSettings } from "../Context/SavedProSettingsContext";
import SavedSettingsList from "./SavedSettingsList";

// A compact card at the top of the Dashboard and the Account page once saved Tailwatch Pro
// settings were given back, until the owner keeps them or undoes it.
const RestoredProSettingsNotice = () => {
  const { restored, undo, dismiss } = useSavedProSettings();
  const [busy, setBusy] = useState(false);

  if (!restored?.entries?.length || !undo || !dismiss) {
    return null;
  }

  const runAction = async (action, failure) => {
    setBusy(true);
    try {
      const answer = await action();
      if (answer.code === 200) {
        toast.success(answer.message);
      } else {
        toast.error(answer.message || failure);
      }
    } catch (error) {
      toast.error(failure);
    } finally {
      setBusy(false);
    }
  };

  const handleKeep = () => runAction(dismiss, "The notice could not be closed.");

  const handleUndo = async () => {
    const confirmed = await alertService.verify(
      "The settings in use before these came back will be put back, and the features switched on again will be switched off.",
      "Undo?",
      "Undo",
      "Cancel"
    );
    if (confirmed) {
      await runAction(undo, "The settings could not be put back.");
    }
  };

  return (
    <div className="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 shadow-sm">
      <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-brand-50">
        <History className="h-4 w-4 text-brand-600" aria-hidden="true" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="!text-sm leading-snug text-gray-500">
          <span className="font-semibold text-gray-900">Your Tailwatch Pro settings are back in use</span>
          {" · they were set aside earlier"}
        </div>
        <SavedSettingsList entries={restored.entries} variant="chips" className="mt-1.5" />
      </div>
      <div className="flex flex-shrink-0 items-center gap-2">
        <button
          type="button"
          disabled={busy}
          onClick={handleUndo}
          className="rounded-md border border-brand-500 bg-white px-3 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 disabled:opacity-50"
        >
          Undo
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={handleKeep}
          className="rounded-md bg-brand-600 px-3 py-1 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
        >
          Keep
        </button>
      </div>
    </div>
  );
};

export default RestoredProSettingsNotice;
