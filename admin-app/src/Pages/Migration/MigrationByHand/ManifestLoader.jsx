import React, { useRef } from "react";
import { CircleAlert, FileJson, Info } from "lucide-react";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import { BY_HAND_STEPS } from "./steps";

/**
 * The migration file, which is where a by-hand move starts.
 *
 * It is a packing list — part names, sizes and digests — and it comes from the other
 * site's Backup Vault, not from here. Saying where it comes from is most of this screen's
 * job: the commonest way to get stuck is looking for an export button on the site you are
 * moving TO.
 *
 * Loading it is the last moment before a sequence that ends with this site replaced, so
 * the single confirmation is attached to this press and not to the uploads that follow.
 */
const ManifestLoader = ({ busy, blocked, disabled, onLoad, onBack }) => {
  const input = useRef(null);

  const choose = async (event) => {
    const file = event.target.files?.[0];
    if (file) {
      await onLoad(file);
    }
    if (input.current) {
      input.current.value = "";
    }
  };

  return (
    <div className="w-full space-y-5">
      <ScreenHeader
        eyebrow="Move it manually"
        title="Load the migration file"
        lede="Carry a backup from another site to this one. It works between any two sites, including ones that cannot reach each other over the network."
        steps={BY_HAND_STEPS}
        step={0}
        onBack={onBack}
        disabled={busy}
      />

      {blocked && <MigrationNote icon={CircleAlert} tone="warning">{blocked}</MigrationNote>}

      <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex items-start gap-3.5">
          <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand-100 bg-brand-50 text-brand-600">
            <FileJson className="h-[18px] w-[18px]" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <h3 className="text-[14.5px] font-semibold leading-tight text-gray-900">
              Migration file from the other site
            </h3>
            <p className="mt-1 text-[13px] leading-relaxed text-gray-500">
              Open the Backup Vault on the site you are moving away from, pick the backup,
              and take its migration file and its parts. The file holds no passwords or keys
              and grants nothing, so it is safe to email.
            </p>

            <input
              ref={input}
              type="file"
              accept="application/json,.json"
              disabled={busy || disabled || !!blocked}
              onChange={choose}
              className="mt-3 block w-full text-xs text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white hover:file:bg-opacity-80 disabled:cursor-not-allowed disabled:opacity-60"
            />
          </div>
        </div>
      </div>

      <MigrationNote icon={Info}>
        A backup already on this site under the same name has to be removed first, so the two
        sets cannot be mixed together. You are asked to confirm once, when this file loads —
        not again for each part.
      </MigrationNote>
    </div>
  );
};

export default ManifestLoader;
