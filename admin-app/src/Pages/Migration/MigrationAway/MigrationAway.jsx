import React, { useEffect, useMemo, useState } from "react";
import { buildConnectionToken } from "../connectionToken.js";
import { expiresIn } from "../migrationFormat";
import OfferPicker from "./OfferPicker";
import KeyHandover from "./KeyHandover";

/**
 * Copying this site onto another one.
 *
 * The same questions as bringing a site here, asked from the other end — which backup, and
 * are you sure — but everything that follows happens over there: the transfer runs on the
 * destination and the restore replaces the destination. Nothing on this site changes at
 * any point, which is the one thing these screens have to keep saying, because "move this
 * site away" sounds like it moves this site.
 *
 * The key IS the handover. Shown once, good for an hour, single use, revocable.
 */
const MigrationAway = ({ migration, onLeave }) => {
  const { pairing, issuing, issueKey, revokeKey } = migration;

  const [chosen, setChosen] = useState("");
  // Ticked deliberately: the key then opens this site's whole history instead of one
  // backup, which is convenient when both sites are yours and a lot to hand over when
  // they are not.
  const [letThemChoose, setLetThemChoose] = useState(false);
  const [issued, setIssued] = useState(null);
  const [revoking, setRevoking] = useState(false);

  // Its own value, so the effect below is not woken by a fresh empty array every render.
  const backups = useMemo(() => pairing?.backups || [], [pairing]);

  // The newest one that can stand on its own, once the site has said what it has.
  useEffect(() => {
    if (chosen || backups.length === 0) return;
    const usable = backups.find((backup) => !backup.incremental);
    if (usable) setChosen(usable.folder);
  }, [backups, chosen]);

  const folderToSend = letThemChoose ? "" : chosen;

  const generate = async () => {
    const minted = await issueKey(folderToSend);
    if (!minted) return;

    setIssued({
      ...minted,
      folder: minted.folder || "",
      // Built from what the site just returned — the address and the key it minted — so
      // there is no second request and nothing new for the server to hand out.
      token: buildConnectionToken(minted.site_url, minted.key),
    });
  };

  const revoke = async () => {
    setRevoking(true);
    try {
      if (await revokeKey()) {
        setIssued(null);
      }
    } finally {
      setRevoking(false);
    }
  };

  if (issued) {
    return (
      <KeyHandover
        issued={issued}
        busy={revoking ? "revoking" : ""}
        onRevoke={revoke}
        onBack={() => setIssued(null)}
      />
    );
  }

  return (
    <OfferPicker
      backups={backups}
      chosen={chosen}
      letThemChoose={letThemChoose}
      busy={issuing}
      pending={!!pairing?.pending}
      pendingExpires={expiresIn(pairing?.expires)}
      onChoose={setChosen}
      onLetThemChoose={setLetThemChoose}
      onAction={generate}
      onBack={onLeave}
    />
  );
};

export default MigrationAway;
