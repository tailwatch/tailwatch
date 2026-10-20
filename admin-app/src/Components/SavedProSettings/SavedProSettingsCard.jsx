import React, { useState } from "react";
import { toast } from "react-toastify";
import { Archive } from "lucide-react";
import ActionButton from "../Buttons/ActionButton";
import { alertService } from "../AlertService/AlertService";
import { useSavedProSettings } from "../Context/SavedProSettingsContext";
import SavedSettingsList from "./SavedSettingsList";

// When the kept settings come back, by why they are not in use now.
const COME_BACK = {
  disconnected: "when this site is connected again to a Tailwatch account on a Business or Agency plan.",
  suspended: "when your Tailwatch subscription is active again.",
  plan: "when this site's Tailwatch plan is Business or Agency.",
};

// The Tailwatch Pro settings a plan lock set aside, on the Account page.
const SavedProSettingsCard = () => {
  const { saved = [], state, forget } = useSavedProSettings();
  const [forgetting, setForgetting] = useState(false);

  if (!saved.length || !forget) {
    return null;
  }

  const handleForget = async () => {
    const confirmed = await alertService.verify(
      "They will not come back when your plan includes them again. Your current settings stay as they are.",
      "Forget your saved Tailwatch Pro settings?",
      "Forget",
      "Cancel"
    );
    if (!confirmed) return;

    setForgetting(true);
    try {
      const answer = await forget();
      if (answer.code === 200) {
        toast.success(answer.message || "Your saved Tailwatch Pro settings were forgotten.");
      } else {
        toast.error(answer.message || "The saved settings could not be forgotten.");
      }
    } catch (error) {
      toast.error("The saved settings could not be forgotten.");
    } finally {
      setForgetting(false);
    }
  };

  return (
    <div className="mt-4 sm:mt-6 lg:mt-8 bg-white rounded-lg shadow-sm border border-gray-300 p-4 sm:p-6">
      <div className="flex items-start gap-3">
        <div className="bg-[#07C07E1A] p-1.5 sm:p-2 rounded-full flex-shrink-0">
          <Archive className="w-4 h-4 sm:w-5 sm:h-5 text-[#007980]" aria-hidden="true" />
        </div>
        <div className="flex-1 min-w-0">
          <h3 className="text-base sm:text-lg font-semibold text-gray-800">Your saved Tailwatch Pro settings</h3>
          <p className="!text-sm text-gray-600 mt-1 leading-relaxed">
            These settings are set aside for now. They are kept, and come back by
            themselves {COME_BACK[state] || COME_BACK.plan} A setting you change in the meantime keeps your new choice.
          </p>
          <SavedSettingsList entries={saved} className="mt-3" />
          <div className="mt-4">
            <ActionButton
              defaultText="Forget saved settings"
              loadingText="Forgetting..."
              isLoading={forgetting}
              onClick={handleForget}
            />
          </div>
        </div>
      </div>
    </div>
  );
};

export default SavedProSettingsCard;
