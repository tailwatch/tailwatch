import React from "react";
import { CircleAlert, Database, HardDriveUpload, Users } from "lucide-react";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { cn } from "../../../Components/Utils/cn";
import ChoiceCard from "../Components/ChoiceCard";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import ActionBar from "../Components/ActionBar";
import { describeContents, describeParts, factLine, formatBytes } from "../migrationFormat";
import { AWAY_STEPS } from "./steps";

/**
 * Which of this site's backups the key will hand over.
 *
 * Chosen here, by the person whose data it is. A key that means one backup is a far easier
 * thing to hand to someone than a key to the whole history — which is why the alternative
 * is a deliberate tick rather than the default.
 *
 * Cards rather than a native select: a closed dropdown of backups, each described by a
 * run-on string of date, contents and size, is the wrong control for a decision about
 * what leaves your site.
 */
const OfferPicker = ({
  backups,
  chosen,
  letThemChoose,
  busy,
  pending,
  pendingExpires,
  onChoose,
  onLetThemChoose,
  onAction,
  onBack,
}) => (
  <div className="w-full space-y-5">
    <ScreenHeader
      eyebrow="Move this site away"
      title="Choose what the key hands over"
      lede="The key carries this site's address as well, so there is nothing else to copy. It is valid for one hour and can only be used once."
      steps={AWAY_STEPS}
      step={0}
      onBack={onBack}
      disabled={busy}
    />

    {backups.length === 0 && (
      <MigrationNote icon={CircleAlert} tone="warning" title="This site has no backup that can be sent yet.">
        Take one from the Backup Vault first, then come back here.
      </MigrationNote>
    )}

    {/* A key minted earlier and carried off this screen. It was shown once and cannot be
        shown again, so the only thing this can usefully say is that it exists and when it
        dies — and that generating another one replaces it. */}
    {pending && (
      <MigrationNote icon={CircleAlert} title={`A key is already active and expires in ${pendingExpires}.`}>
        It was shown once when it was created. Generating a new one below replaces it, and
        the old one stops working immediately.
      </MigrationNote>
    )}

    <div className="space-y-2.5">
      {backups.map((backup) => (
        <ChoiceCard
          key={backup.folder}
          icon={backup.database && !backup.files ? Database : HardDriveUpload}
          title={backup.generated || backup.folder}
          subtitle={factLine(
            describeContents(backup),
            formatBytes(backup.bytes),
            describeParts(backup.parts)
          )}
          note={
            backup.incremental
              ? "Incremental — it holds only what changed since an earlier backup, so it cannot be moved on its own."
              : ""
          }
          trailing="radio"
          selected={!letThemChoose && chosen === backup.folder}
          disabled={busy || backup.incremental || letThemChoose}
          onClick={() => onChoose(backup.folder)}
        />
      ))}
    </div>

    {/* Not one of the backups above, so not a card in that list: it is the decision to
        hand over the list itself. */}
    {/* Off as well when there is nothing to send: handing over the choice of an empty
        history is not a different outcome, and leaving it pressable would suggest it is
        the way round the disabled button below. */}
    <button
      type="button"
      onClick={() => onLetThemChoose(!letThemChoose)}
      disabled={busy || backups.length === 0}
      aria-pressed={letThemChoose}
      className={cn(
        "group flex w-full items-start gap-3.5 rounded-2xl border p-4 text-left transition-all",
        "focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600/30",
        busy || backups.length === 0
          ? "cursor-not-allowed border-gray-200 bg-gray-50/60 opacity-70"
          : letThemChoose
          ? "border-brand-600 bg-white ring-1 ring-brand-600/20"
          : "border-dashed border-gray-300 bg-white hover:border-brand-600/40"
      )}
    >
      <span
        className={cn(
          "mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border transition-colors",
          letThemChoose
            ? "border-brand-600/30 bg-brand-600 text-white"
            : "border-gray-200 bg-gray-50 text-gray-400"
        )}
      >
        <Users className="h-[18px] w-[18px]" aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-[14.5px] font-semibold leading-tight text-gray-900">
          Let the other site choose instead
        </span>
        <span className="mt-1 block text-[13px] leading-relaxed text-gray-500">
          It will be able to see this site's backups and pick any of them — convenient when
          both sites are yours, and a lot to hand over when they are not.
        </span>
      </span>
      <span
        className={cn(
          "mt-1.5 flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded border-2 transition-colors",
          letThemChoose ? "border-brand-600 bg-brand-600 text-white" : "border-gray-300"
        )}
        aria-hidden="true"
      >
        {letThemChoose && <span className="text-[10px] font-bold leading-none">✓</span>}
      </span>
    </button>

    <ActionBar
      note={
        backups.length === 0
          ? "There is nothing to hand over yet. A key is only worth minting once this site has a backup that can be sent."
          : "Generating a key changes nothing on this site. It can be revoked at any time before it is spent."
      }
    >
      <ActionButton
        onClick={onAction}
        isLoading={busy}
        loadingText="Generating..."
        defaultText={pending ? "Generate a new key" : "Generate connection key"}
        // No backups means no key worth minting — "let the other site choose" would
        // otherwise leave this pressable, handing over a key to an empty history and
        // turning a fact the screen already states into a refusal from the server.
        isDisabled={busy || backups.length === 0 || (!letThemChoose && !chosen)}
      />
    </ActionBar>
  </div>
);

export default OfferPicker;
