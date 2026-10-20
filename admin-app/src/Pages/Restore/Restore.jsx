import React, { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Header from "../../Components/Header/Header";
import InfoBar from "../../Components/InfoBar/InfoBar.jsx";
import RestoreProgress from "../Backup/RestoreProgress/RestoreProgress.jsx";
import { fetchRestoreStatus } from "../Backup/RestoreServices/RestoreServices.jsx";

/**
 * How long a backup named in the address is waited for before the screen says no restore of
 * it is running.
 *
 * A migration hands its backup over before the restore exists: the restore first checks
 * every part and the checksum of every database file, and only then records itself. That
 * takes seconds on a small site and can take minutes on a large one, and the migration
 * screen comes here the moment the transfer ends, inside that gap.
 */
export const WAIT_FOR_START_MS = 180000;

/** How often the screen asks while it waits for a restore to begin. */
export const POLL_WAITING_MS = 3000;

/**
 * How often it keeps asking once it has said nothing is running. Slower, but not never: a
 * restore that starts later than the wait above, or from another tab, then appears here by
 * itself instead of only after a reload.
 */
export const POLL_IDLE_MS = 10000;

const isLive = (phase) => !!phase && !["idle", "done", "failed"].includes(phase);

// A finished restore deliberately stays on this screen. The result — what was replaced,
// what was warned about, the console — is the reason someone waited for it, and bouncing
// back to the list the moment it ends throws that away. One function for the life of the
// page, because the card restarts its polling whenever this prop changes.
const stayOnResult = () => {};

/**
 * What the screen shows for one answer from the server.
 *
 * The server keeps a single record, the most recent restore. So until the restore of the
 * backup in the address has begun, it answers either "idle" or with an earlier restore of
 * another backup, already finished. Neither says the one asked for will not come.
 *
 * @param {object|null} status   fetchRestoreStatus()'s answer.
 * @param {string}      wanted   The backup named in the address, or "".
 * @param {number}      waitedMs How long this screen has been open.
 * @return {{view: string, folderDate?: string}|null} null when the answer says nothing.
 */
export const decideView = (status, wanted, waitedMs) => {
  // A failed read, or a session the restore signed out, is no news.
  if (!status || status.signedOut) {
    return null;
  }

  // A running restore is the one thing worth showing, whichever backup it came from.
  if (isLive(status.phase) && status.folderDate) {
    return { view: "progress", folderDate: status.folderDate };
  }

  if (wanted) {
    if (status.phase !== "idle" && status.folderDate === wanted) {
      return { view: "progress", folderDate: wanted };
    }
    return { view: waitedMs < WAIT_FOR_START_MS ? "waiting" : "idle" };
  }

  // Nothing named: the last restore's result is read back, if there has been one.
  if (status.phase !== "idle" && status.folderDate) {
    return { view: "progress", folderDate: status.folderDate };
  }
  return { view: "idle" };
};

/**
 * The screen a restore runs on.
 *
 * The most destructive thing the plugin does gets a screen, the way every other long
 * operation has one, rather than a panel above the backup list it was launched from: the
 * rest of that list would still be offering Delete and Restore on other rows, none of which
 * can be honoured while one is running. Pressing Restore moves here; the backup list stays
 * a list.
 *
 * It reads which backup is running from the SERVER, not from what the previous screen
 * passed in. A restore signs the operator out about half the time — the session tables are
 * among the ones replaced — and the first thing they do is come back to this URL. If this
 * page could only show a restore it had personally started, that return would find an
 * empty screen in the middle of the one operation nobody should have to guess about.
 *
 * Keyed by the backup in the address, so moving between the two restore addresses opens a
 * fresh screen rather than one still holding the other's answer.
 */
const Restore = () => {
  const params = useParams();
  const wanted = params.folderDate ?? "";

  return <RestoreScreen key={wanted} wanted={wanted} />;
};

const RestoreScreen = ({ wanted }) => {
  const navigate = useNavigate();

  // checking -> waiting -> progress, or idle; "signedOut" when nothing can be read any more.
  const [view, setView] = useState("checking");
  const [folderDate, setFolderDate] = useState("");
  const openedAt = useRef(Date.now());

  // The progress card found nothing for its backup before it ever saw that restore run, so
  // the screen takes over the asking again.
  const handBack = useCallback(() => {
    setFolderDate("");
    setView(wanted && Date.now() - openedAt.current < WAIT_FOR_START_MS ? "waiting" : "idle");
  }, [wanted]);

  useEffect(() => {
    // The card polls while it is shown; a signed-out session cannot read anything.
    if (view === "progress" || view === "signedOut") {
      return undefined;
    }

    let cancelled = false;
    let timer = null;
    const every = view === "idle" ? POLL_IDLE_MS : POLL_WAITING_MS;

    const ask = async () => {
      const status = await fetchRestoreStatus();
      if (cancelled) {
        return;
      }

      const next = decideView(status, wanted, Date.now() - openedAt.current);

      if (next === null) {
        // On the first answer, a backup named in the address goes to the card, which
        // already treats a missed poll as no news and waits out a maintenance gate.
        if (view === "checking" && wanted) {
          setFolderDate(wanted);
          setView("progress");
          return;
        }
        if (status?.signedOut) {
          setView("signedOut");
          return;
        }
        timer = setTimeout(ask, every);
        return;
      }

      if (next.view === "progress") {
        setFolderDate(next.folderDate);
        setView("progress");
        return;
      }

      if (next.view !== view) {
        setView(next.view);
        return;
      }

      timer = setTimeout(ask, every);
    };

    timer = setTimeout(ask, view === "checking" ? 0 : every);

    return () => {
      cancelled = true;
      if (timer) {
        clearTimeout(timer);
      }
    };
  }, [view, wanted]);

  return (
    <div>
      <Header
        title="Restore"
        showBackIcon={true}
        backIconCallback={() => navigate("/dashboard/backup/files")}
      />

      <div className="p-4">
        {view === "waiting" && (
          <InfoBar
            type="progress"
            message={`Waiting for the restore of ${wanted} to start. The backup is checked first, which can take a few minutes on a large site; this screen follows it as soon as it begins.`}
          />
        )}

        {view === "idle" && (
          <InfoBar
            type="info"
            message={
              wanted
                ? `No restore of ${wanted} is running or on record. If a migration brought this backup, its log on the Migration screen says why its restore did not start. You can also choose a backup from the Backup Vault to restore this site from it.`
                : "No restore is running. Choose a backup from the Backup Vault to restore this site from it."
            }
          />
        )}

        {view === "signedOut" && (
          <InfoBar
            type="info"
            message="You were signed out, most likely because a restore replaced the site's user accounts. Sign in again to follow it."
          />
        )}

        {view === "progress" && folderDate !== "" && (
          <RestoreProgress folderDate={folderDate} onIdle={handBack} onFinished={stayOnResult} />
        )}
      </div>
    </div>
  );
};

export default Restore;
