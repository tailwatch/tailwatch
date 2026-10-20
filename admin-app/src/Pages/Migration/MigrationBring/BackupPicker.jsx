import React from "react";
import { CircleAlert, Database, HardDriveDownload } from "lucide-react";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import ChoiceCard from "../Components/ChoiceCard";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import ActionBar from "../Components/ActionBar";
import { describeContents, describeParts, factLine, formatBytes } from "../migrationFormat";
import { BRING_STEPS } from "./steps";

/**
 * Which of the other site's backups to bring.
 *
 * The pairing is already made at this point and nothing on either site has changed: the
 * key has been spent, a list has come back, and this choice can still be walked away from.
 *
 * Incremental backups are listed rather than hidden — someone looking for last night's
 * backup needs to see that it is there and why it cannot come on its own.
 */
const BackupPicker = ({ offer, chosen, busy, blocked, onChoose, onStart, onBack }) => {
  const backups = offer.backups || [];
  // Nothing to choose: the other site named one backup when it minted its key, or it is an
  // older build that cannot list them and will send its newest.
  const fixed = offer.pinned || offer.choosable === false;

  return (
    <div className="w-full space-y-5">
      <ScreenHeader
        eyebrow="Bring a site here"
        title="Choose the backup to bring"
        lede={`Paired with ${offer.sourceUrl}. Everything on this site is replaced with what you pick — the transfer runs first, and the restore follows it without asking again.`}
        steps={BRING_STEPS}
        step={1}
        onBack={onBack}
        disabled={busy}
      />

      {blocked && <MigrationNote icon={CircleAlert} tone="warning">{blocked}</MigrationNote>}

      {backups.length === 0 && offer.choosable && (
        <MigrationNote icon={CircleAlert} tone="bad" title="That site has no completed backup to send.">
          Take one over there first, then generate a new key.
        </MigrationNote>
      )}

      {offer.pinned && backups.length > 0 && (
        <MigrationNote icon={CircleAlert}>
          The other site's key is for this one backup. Nothing else of theirs can be taken
          with it.
        </MigrationNote>
      )}

      {!offer.pinned && offer.choosable === false && (
        <MigrationNote icon={CircleAlert}>
          That site could not be asked which backups it has — it is probably running an
          older version of Tailwatch. Its most recent complete backup will be transferred.
        </MigrationNote>
      )}

      {/* An older site that cannot be listed still has something to show for itself: what
          is going to happen, named, so the confirmation is not the first mention of it. */}
      {backups.length === 0 && offer.choosable === false && (
        <ChoiceCard
          icon={HardDriveDownload}
          title="That site's most recent backup"
          subtitle="Chosen by the other site, because this version cannot be asked for a list."
          trailing="radio"
          selected
          disabled
        />
      )}

      <div className="space-y-2.5">
        {backups.map((backup) => (
          <ChoiceCard
            key={backup.folder}
            icon={backup.database && !backup.files ? Database : HardDriveDownload}
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
            selected={chosen === backup.folder}
            disabled={busy || backup.incremental || fixed}
            onClick={() => onChoose(backup.folder)}
          />
        ))}
      </div>

      <ActionBar note="Nothing on this site has changed yet. The size shown is what will travel.">
        <ActionButton
          onClick={onStart}
          isLoading={busy}
          loadingText="Starting..."
          defaultText="Start transfer"
          isDisabled={
            busy || !!blocked || (offer.choosable !== false && (backups.length === 0 || !chosen))
          }
        />
      </ActionBar>
    </div>
  );
};

export default BackupPicker;
