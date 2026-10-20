import React, { useState } from "react";
import { alertService } from "../../../Components/AlertService/AlertService.js";
import { Spinner } from "../../../Components/Spinner/Spinner.jsx";
import ConnectByKey from "./ConnectByKey";
import BackupPicker from "./BackupPicker";
import { describeContents, factLine, formatBytes } from "../migrationFormat";
import { restorePoints, restoreWarning, scopeOfContents, SCOPE_FULL, TRANSFER_FIRST } from "../restoreImpact";

/**
 * Bringing another site onto this one.
 *
 * Two steps, in this order, and the order matters: the key is spent to ask what is on
 * offer, and only then is a backup chosen. Nothing on either site has changed when the
 * list comes back — the only step that changes anything is the last one, and it is
 * confirmed once, in full.
 */
const MigrationBring = ({ migration, onLeave }) => {
  const { status, connecting, starting, blockAutomatic, connect, resume, start } = migration;

  // What the other site offered, once it has been paired with. null is "not paired yet",
  // which is a different screen from "paired and it has nothing to send".
  const [offer, setOffer] = useState(null);
  const [chosen, setChosen] = useState("");

  // The newest backup that can actually be moved. Incremental ones are listed so it is
  // clear why they are not on offer, but they are never the default.
  const newestUsable = (backups) =>
    (backups ?? []).find((backup) => !backup.incremental)?.folder || "";

  const openOffer = (listed) => {
    setOffer(listed);
    setChosen(listed.pinned ? listed.backups[0]?.folder || "" : newestUsable(listed.backups));
  };

  const handleConnect = async (sourceUrl, key) => {
    const listed = await connect(sourceUrl, key);
    if (listed) openOffer(listed);
  };

  const handleResume = async () => {
    const listed = await resume();
    if (listed) openOffer(listed);
  };

  /**
   * The one confirmation, and it names what is being replaced with what.
   *
   * After this the transfer runs by itself and the restore follows it without asking
   * again — which is exactly why this sentence has to say so.
   */
  const handleStart = async () => {
    if (!offer) return;

    const picked = (offer.backups ?? []).find((backup) => backup.folder === chosen);

    const { confirmed } = await alertService.confirmImpact({
      title: "Bring that site here?",
      subject: {
        primary: picked ? `Backup taken on ${picked.generated || picked.folder}` : "Its most recent backup",
        secondary: factLine(`From ${offer.sourceUrl}`, describeContents(picked), formatBytes(picked?.bytes)),
      },
      points: [
        { icon: "transfer", text: TRANSFER_FIRST },
        ...restorePoints({ scope: picked ? scopeOfContents(picked) : SCOPE_FULL, fromOtherSite: true }),
      ],
      warning: restoreWarning({ fromOtherSite: true, extra: "This is the only confirmation you will be asked for." }),
      confirmButtonText: "Start transfer",
      cancelButtonText: "Cancel",
    });
    if (!confirmed) return;

    if (await start(offer.sourceUrl, chosen)) {
      setOffer(null);
      setChosen("");
      onLeave();
    }
  };

  // Nothing is clickable while the move is being started: the consent has been given, the
  // request is out, and the next screen is whatever the site answers.
  const holding = starting && (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <Spinner />
    </div>
  );

  if (offer) {
    return (
      <>
        {holding}
        <BackupPicker
          offer={offer}
          chosen={chosen}
          busy={starting}
          blocked={blockAutomatic}
          onChoose={setChosen}
          onStart={handleStart}
          onBack={() => {
            setOffer(null);
            setChosen("");
          }}
        />
      </>
    );
  }

  return (
    <ConnectByKey
      busy={connecting}
      blocked={blockAutomatic}
      pairedWith={status?.pairedWith || ""}
      onConnect={handleConnect}
      onResume={handleResume}
      onBack={onLeave}
    />
  );
};

export default MigrationBring;
