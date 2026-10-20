import React, { useState } from "react";
import { Check, Clock, KeyRound, ShieldAlert } from "lucide-react";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import ActionBar from "../Components/ActionBar";
import { expiresIn } from "../migrationFormat";
import { AWAY_STEPS } from "./steps";

const STEPS = [
  "Open Migration on the site you are moving to.",
  "Choose \"Bring a site here\" and paste this key.",
  "Pick this backup over there and start the transfer.",
];

/**
 * The one thing to carry to the other site.
 *
 * It is a credential: whoever holds it can pull this site's backup. So it is shown once,
 * it says out loud how long it lasts, and it can be taken back. A key that cannot be shown
 * again gets a screen of its own.
 */
const KeyHandover = ({ issued, busy, onRevoke, onBack }) => {
  const [copied, setCopied] = useState(false);
  const token = issued?.token || "";

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(token);
      setCopied(true);
    } catch (error) {
      // Blocked in some contexts; the key is on screen and selectable either way.
      setCopied(false);
    }
  };

  return (
    <div className="w-full space-y-5">
      <ScreenHeader
        eyebrow="Move this site away"
        title="Carry this key to the other site"
        lede="Treat it like a password: it lets that site pull this one's backup. Nothing on this site changes at any point."
        steps={AWAY_STEPS}
        step={1}
        onBack={onBack}
        disabled={!!busy}
      />

      <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex items-center justify-between gap-3">
          <span className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
            <KeyRound className="h-4 w-4" aria-hidden="true" />
            Connection key
          </span>
          <span className="inline-flex items-center gap-1.5 text-xs text-gray-500">
            <Clock className="h-3.5 w-3.5" aria-hidden="true" />
            Expires in {expiresIn(issued?.expires)}
          </span>
        </div>

        <div className="mt-2.5 flex flex-col gap-2 sm:flex-row sm:items-center">
          <input
            readOnly
            value={token}
            onFocus={(event) => event.target.select()}
            aria-label="Connection key"
            className="h-9 w-full min-w-0 flex-1 rounded-lg border border-gray-300 bg-gray-50 px-3 font-mono text-xs text-gray-800"
          />
          <ActionButton
            onClick={copy}
            defaultText={copied ? "Copied" : "Copy key"}
            className="shrink-0 px-3 py-1"
          />
        </div>

        <p className="mt-2 flex items-center gap-1.5 text-xs text-gray-500">
          {copied ? (
            <Check className="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" />
          ) : null}
          {issued?.folder
            ? `This key hands over one backup: ${issued.folder}.`
            : "This key lets the other site choose which backup to take."}
        </p>
      </div>

      <ol className="space-y-2.5">
        {STEPS.map((step, index) => (
          <li key={step} className="flex items-start gap-3">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-brand-100 bg-brand-50 text-[11px] font-semibold text-brand-700">
              {index + 1}
            </span>
            <span className="pt-0.5 text-[13px] leading-relaxed text-gray-600">{step}</span>
          </li>
        ))}
      </ol>

      <MigrationNote icon={ShieldAlert} tone="warning">
        This is the only time the key is shown. If you lose it, generate a new one — the old
        one stops working the moment you do.
      </MigrationNote>

      <ActionBar note="Revoking stops any site holding this key from pairing, and stops a paired site transferring.">
        <ActionButton
          onClick={onRevoke}
          isLoading={busy === "revoking"}
          loadingText="Revoking..."
          defaultText="Revoke this key"
          isDisabled={!!busy}
          className="px-3 py-1"
        />
      </ActionBar>
    </div>
  );
};

export default KeyHandover;
