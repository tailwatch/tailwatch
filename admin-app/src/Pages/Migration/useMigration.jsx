import { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { alertService } from "../../Components/AlertService/AlertService.js";
import { useFeaturesData } from "../../Components/Context/FeaturesDataContext.jsx";
import UseMigrationFeature from "../../Components/Hooks/MigrationFeature/UseMigrationFeature.jsx";
import { fetchRestoreStatus } from "../Backup/RestoreServices/RestoreServices.jsx";
import { chunkSize, describeParts, factLine, formatBytes } from "./migrationFormat.js";
import { restorePoints, restoreWarning, scopeOfContents, SCOPE_FULL, TRANSFER_FIRST } from "./restoreImpact.js";
import {
  getLocalStorage,
  updateLocalStorage,
} from "../../Components/Utils/HelperFunctions/LocalStorageHelper";
import {
  beginManualImport,
  cancelManualImport,
  fetchManualImportStatus,
  fetchMigrationLogs,
  fetchMigrationStatus,
  fetchPairingStatus,
  issuePairingKey,
  listSourceBackups,
  retryMigration,
  revokePairingKey,
  startMigration,
  scanForParts,
  uploadMigrationPart,
} from "./MigrationServices/MigrationServices.jsx";

/** Quick while something is happening, a heartbeat otherwise. */
const POLL_BUSY_MS = 3000;
const POLL_IDLE_MS = 15000;
const LOG_POLL_MS = 3000;

/**
 * Everything this site can say about moving itself, and everything that can be asked of it.
 *
 * The state lives here rather than in a page component, so each screen is what it actually
 * is — one question — and is put away by not rendering it rather than by hiding it with a
 * CSS class. This is the single thing they all read from.
 *
 * The polling here is the module's ONE poll. Each screen adds its own commentary at most;
 * none of them asks the site for its status again, because two pollers on one page produce
 * two answers to the same question and the screen believes whichever arrived last.
 */
export const useMigration = () => {
  const navigate = useNavigate();
  const { proPluginActive } = useFeaturesData();
  const { migrationFeature, refreshMigrationFeature } = UseMigrationFeature();

  // What the site reports. Everything else on this screen is derived from these four.
  const [pairing, setPairing] = useState(null);
  const [status, setStatus] = useState(null);
  const [manual, setManual] = useState(null);
  const [appliedPhase, setAppliedPhase] = useState("");

  // The running commentary. A part count cannot say WHICH part was refused, or why.
  const [logs, setLogs] = useState([]);
  // Closed on arrival, opened by a run that is actually happening. Open by default, it
  // would reopen a finished migration's console on every visit — read from index zero,
  // which makes last week's lines look like this minute's.
  const [isLogsVisible, setIsLogsVisible] = useState(false);

  // In-flight flags, owned here because the actions that set them are here.
  const [issuing, setIssuing] = useState(false);
  const [connecting, setConnecting] = useState(false);
  const [starting, setStarting] = useState(false);
  // A refetch of the whole module, as opposed to the background poll. Held as state
  // because the screens are supposed to go back to their skeleton while it runs: a settings
  // panel can have changed what every card on this page says, and cards that sit there
  // looking answered while the answer is being re-read are cards nobody can trust.
  const [refreshing, setRefreshing] = useState(false);
  const [loadingManifest, setLoadingManifest] = useState(false);
  const [uploading, setUploading] = useState("");
  // Whether the site is being asked to look in its own backup folder.
  const [scanning, setScanning] = useState(false);
  const [uploadPercent, setUploadPercent] = useState(0);
  const [finished, setFinished] = useState("");

  const logIndex = useRef(0);
  // The phase the last poll saw, so the move onto the restore fires on the change into
  // "done" rather than every time that phase is observed.
  const lastPhase = useRef("");
  // Read by the pollers, which must not restart every time it changes.
  const busyRef = useRef(false);
  const wasBusy = useRef(false);
  // The latest answer about the by-hand import, for the upload loop.
  //
  // That loop runs across several awaits while parts land one after another, and `manual`
  // captured from the render that started it is the answer from before the first part
  // arrived. Reading the ref means the second part is checked against what the site knows
  // now, not against a list that is already out of date.
  const manualRef = useRef(null);

  useEffect(() => {
    manualRef.current = manual;
  }, [manual]);

  // Only a definite "no" blocks. Undefined means an older pro build that does not report
  // the plan, and that must read as allowed rather than silently disabling a transfer
  // someone is entitled to.
  const planBlocked = pairing?.plan_allows === false;

  const running = !!status && !["idle", "done", "failed"].includes(status.phase);
  const importing = !!manual && manual.phase !== "idle";

  // The transfer is over and the restore it started is not. Reported separately because a
  // migration is only finished when this site IS the other one, which happens on the
  // restore screen — but the transfer's own phase says "done" the moment the bytes land
  // and stays that way for good.
  const applying =
    status?.phase === "done" &&
    appliedPhase !== "" &&
    !["idle", "done", "failed"].includes(appliedPhase);
  const applied = status?.phase === "done" && appliedPhase === "done";
  const applyFailed = status?.phase === "done" && appliedPhase === "failed";

  // Named by the server, which asks the same guard the start asks. Reading a list of
  // blocking process types here would be a copy of the backend's list, and the copy is
  // what goes stale.
  const blockedBy = manual?.blockedBy ?? "";
  // Our own work in progress is not an obstacle to report — the screens already show it.
  const inTheWay =
    blockedBy && !["migration"].includes(blockedBy) ? manual?.blockedReason || "" : "";

  const busy = !!(running || importing);
  // Whether the screen still has something to find out. Wider than the console, because
  // the summary has to stop saying "being applied" by itself when the restore ends.
  const watching = !!(busy || applying);

  // The two routes are the same process type, so each stops the other. Known from what
  // this screen is already watching rather than from another request.
  const blockAutomatic =
    inTheWay || (importing ? "A backup is already being brought in manually on this site." : "");
  const blockManual =
    inTheWay || (running ? "A transfer from another site is already running here." : "");

  // Whether the site has answered the question the whole gate depends on.
  //
  // `tailwatch_manual_import_status` is the one call every install answers — free or pro,
  // feature on or off — and it carries the same feature_enable / parent_enable pair every
  // other module reads. So it, and not the cached feature list, is what decides whether
  // this module is locked. Until it has come back nothing is decided at all: a lock card
  // drawn from a guess and then withdrawn a moment later is worse than a short wait, and
  // would tell people the feature is off while it is being switched on.
  const gateKnown = manual !== null;

  // Two different "off"s, and they need two different offers.
  //
  // The feature card switched off entirely -> there is nothing to configure, so offer to
  // switch it on where the operator already is. The card on but "Allow Migration"
  // unticked -> switching the feature on again would do nothing; the box is what needs
  // ticking, so send them to its settings instead.
  const parentOff = gateKnown && manual.parentEnable === false;
  const migrationOff = gateKnown && !parentOff && manual.featureEnable === false;

  // Whether the site has answered enough for a screen to be drawn rather than a skeleton.
  //
  // Not only on first arrival: a refetch puts it back to false, so closing the settings
  // panel returns the same skeleton the module opens with rather than leaving the old
  // answers on screen for the second it takes to replace them.
  const ready = gateKnown && !refreshing && (!proPluginActive || pairing !== null);

  /* ------------------------------------------------------------------- loading */

  const loadPairing = useCallback(async () => {
    setPairing(await fetchPairingStatus());
  }, []);

  /**
   * Every part this import expects, by name, in the order the migration file lists them.
   *
   * The site reports how many parts there are and names only the ones still missing. The
   * full list is in the migration file, which only the browser sees, so it is kept when
   * the file is loaded, and in storage too: an upload is measured in hours, and a reload
   * must not shrink the list back to what is left.
   */
  const [expectedParts, setExpectedParts] = useState(() => {
    const stored = getLocalStorage("migration_import", "parts");
    return Array.isArray(stored) ? stored : [];
  });

  // Names only: the sizes and digests in the file are the site's business.
  const rememberExpectedParts = useCallback((parts) => {
    const names = Array.isArray(parts)
      ? parts.map((part) => part?.file).filter(Boolean)
      : [];
    setExpectedParts(names);
    updateLocalStorage("migration_import", { parts: names });
  }, []);

  const forgetExpectedParts = useCallback(() => {
    setExpectedParts([]);
    updateLocalStorage("migration_import", {}, ["parts"]);
  }, []);

  const loadManualStatus = useCallback(async () => {
    const next = await fetchManualImportStatus();
    if (next) {
      setManual(next);
    }
  }, []);

  /**
   * Everything this module shows, read again.
   *
   * Used when the settings panel closes and when the feature card is toggled. Either can
   * have changed the feature, one of the boxes inside it, or nothing at all — and the
   * screens here are built from four separate answers, not one.
   *
   * All four are re-read. Refreshing only the feature and the by-hand status would leave a
   * key minted or revoked while the panel was open, or a transfer that finished behind it,
   * reported as it was before the panel opened until the idle poll comes round.
   *
   * The three live reads go together rather than in sequence: they are independent
   * questions to the same site, and waiting for each in turn is three round trips of
   * staring at stale cards for no reason.
   */
  const refreshAll = useCallback(async () => {
    setRefreshing(true);
    try {
      await refreshMigrationFeature();

      const asking = [loadManualStatus()];

      // Only a pro install has a pairing or a transfer to ask about; on a free one these
      // actions are not registered and would answer with a refusal for no purpose.
      if (proPluginActive) {
        asking.push(loadPairing());
        asking.push(
          (async () => {
            const next = await fetchMigrationStatus();
            if (next) {
              setStatus(next);
            }
          })()
        );
      }

      await Promise.all(asking);
    } finally {
      // In a finally, so a site that stops answering mid-refresh leaves a screen rather
      // than a skeleton that never resolves into one.
      setRefreshing(false);
    }
  }, [refreshMigrationFeature, loadManualStatus, loadPairing, proPluginActive]);

  // Whichever call discovered the switch was off, the page goes to the same state — and
  // re-polls, so it recovers by itself if it was turned back on in the meantime.
  const markMigrationOff = useCallback(() => {
    setManual((current) => ({
      phase: "idle",
      folder: "",
      total: 0,
      received: 0,
      missing: [],
      resume: {},
      chunkBytes: 0,
      ...(current || {}),
      featureEnable: false,
      parentEnable: false,
    }));
  }, []);

  useEffect(() => {
    if (proPluginActive) {
      loadPairing();
    }
  }, [proPluginActive, loadPairing]);

  // A transfer outlives the page — it runs on cron — so adopt whatever is already in
  // flight on mount rather than showing an idle form over a running job.
  useEffect(() => {
    let cancelled = false;
    let timer = null;

    const poll = async () => {
      // The by-hand half answers on every install, and its answer — what would refuse a
      // migration started right now — governs the automatic half as well. One timer, so
      // the two routes can never disagree about what this site is doing.
      const carried = await fetchManualImportStatus();
      if (!cancelled && carried) {
        setManual(carried);
      }

      const next = proPluginActive ? await fetchMigrationStatus() : null;
      if (!cancelled && next) {
        // Follow the transfer onto the restore it just started. Deliberately on the
        // TRANSITION into "done", not on the state being "done": that phase stays in the
        // option after the restore has long finished, so redirecting whenever it is seen
        // would trap anyone opening this screen again days later.
        const was = lastPhase.current;
        lastPhase.current = next.phase;

        setStatus(next);

        if ("done" === next.phase && was && "done" !== was && next.folder) {
          navigate(`/dashboard/restore/${encodeURIComponent(next.folder)}`);
          return;
        }

        // Only the restore this transfer's backup went into. Any other one on record is
        // somebody else's work and says nothing about whether this migration landed.
        if ("done" === next.phase && next.folder) {
          const restore = await fetchRestoreStatus();
          if (!cancelled) {
            setAppliedPhase(
              restore && restore.folderDate === next.folder ? restore.phase : ""
            );
          }
        } else if (!cancelled) {
          setAppliedPhase("");
        }
      }

      if (!cancelled) {
        // Kept quick while something is happening and slowed to a heartbeat otherwise, so
        // a transfer started from another tab is still picked up without this screen
        // asking every three seconds for days after a migration has ended.
        timer = setTimeout(poll, busyRef.current ? POLL_BUSY_MS : POLL_IDLE_MS);
      }
    };

    poll();
    return () => {
      cancelled = true;
      if (timer) {
        clearTimeout(timer);
      }
    };
  }, [proPluginActive, navigate]);

  useEffect(() => {
    busyRef.current = watching;
  }, [watching]);

  // Followed while something is writing to it, and not otherwise. Loading it on arrival
  // would put a finished migration's console on the screen every time this page is
  // opened, under a page that says nothing is running. The record still exists; it is
  // asked for rather than served unbidden.
  useEffect(() => {
    if (!busy) {
      return undefined;
    }

    let cancelled = false;
    let timer = null;

    const loop = async () => {
      const tail = await fetchMigrationLogs(logIndex.current);
      if (cancelled) return;
      if (tail && tail.newLogs.length > 0) {
        logIndex.current = tail.lastLogIndex;
        setLogs((current) => [...current, ...tail.newLogs]);
      }
      timer = setTimeout(loop, LOG_POLL_MS);
    };

    loop();

    return () => {
      cancelled = true;
      if (timer) {
        clearTimeout(timer);
      }
    };
  }, [busy]);

  // A TRANSFER opens its console by itself: it runs on the two sites' schedulers, there is
  // nothing for the operator to do while it does, and the commentary is the only thing on
  // that screen worth watching.
  //
  // A by-hand import is the opposite. The operator is the one doing the work — choosing
  // files, watching a part climb — and a console unrolling under that is noise over the
  // thing they are actually using. So it stays closed there until they ask for it, which
  // is why this follows `running` rather than `busy`.
  //
  // Either way it never opens on its own over a migration that has already finished.
  useEffect(() => {
    if (running && !wasBusy.current) {
      setIsLogsVisible(true);
    }
    wasBusy.current = running;
  }, [running]);

  /**
   * Follow a by-hand migration onto the restore it started.
   *
   * The last part landing is not the end of a migration — it is the moment it stops being
   * this screen's business. The site starts replacing itself, and everything worth knowing
   * from then on is the restore's: the phase, how far it has got, whether the site came
   * back. This screen can report none of it.
   *
   * The upload's own reply carries `restoring` and redirects on it, but that only fires
   * when the restore happened to start within that one request. It can start a moment
   * later, or the last part can arrive some other way, and the operator must not be left
   * on the migration screen reading a notice that a restore is running somewhere, with no
   * way to it.
   *
   * So the site's own answer is followed instead. `blockedBy` is the process type now
   * holding the site, and an import only reports a folder while it has one, so this
   * cannot fire for a restore that has nothing to do with the migration just finished —
   * that case belongs to ProcessLock.
   *
   * Once, and only once. A redirect that re-fires is a screen nobody can leave.
   */
  const followedRestore = useRef(false);
  useEffect(() => {
    if (followedRestore.current || manual?.blockedBy !== "restore") {
      return;
    }

    const folder = finished || manual?.folder || "";
    if (!folder) {
      return;
    }

    followedRestore.current = true;
    navigate(`/dashboard/restore/${encodeURIComponent(folder)}`);
  }, [finished, manual, navigate]);

  /* ------------------------------------------------------------------ the away half */

  /** Mint a key. `folder` empty means the other site gets to choose. */
  const issueKey = useCallback(
    async (folder) => {
      setIssuing(true);
      try {
        const result = await issuePairingKey(folder);
        if (!result) return null;
        await loadPairing();
        return result;
      } finally {
        setIssuing(false);
      }
    },
    [loadPairing]
  );

  const revokeKey = useCallback(async () => {
    const confirmed = await alertService.confirm(
      "Any site holding this key will no longer be able to pair, and a paired site will stop being able to transfer.",
      "Revoke the pairing key?",
      "Revoke",
      "Cancel"
    );
    if (!confirmed) return false;

    await revokePairingKey();
    await loadPairing();
    return true;
  }, [loadPairing]);

  /* ----------------------------------------------------------------- the bring half */

  /**
   * Spend a key and ask what is on offer.
   *
   * Pairing and transferring are two steps, because the backup to bring is chosen in
   * between. Nothing on this site changes at this point — the key is spent and a list
   * comes back, and the operator can walk away from it.
   */
  const connect = useCallback(async (url, key) => {
    setConnecting(true);
    try {
      return await listSourceBackups((url || "").trim(), (key || "").trim());
    } finally {
      setConnecting(false);
    }
  }, []);

  /**
   * Come back to a pairing this site already made, after the page was reloaded.
   *
   * No key: the one that was spent is still what the two sites are using.
   */
  const resume = useCallback(async () => {
    setConnecting(true);
    try {
      return await listSourceBackups(status?.pairedWith ?? "", "");
    } finally {
      setConnecting(false);
    }
  }, [status]);

  const start = useCallback(async (sourceUrl, folder) => {
    setStarting(true);
    try {
      if (!(await startMigration(sourceUrl, "", folder))) return false;
      setStatus(await fetchMigrationStatus());
      return true;
    } finally {
      setStarting(false);
    }
  }, []);

  /**
   * The pairing outlives the failure, so this is the whole of "try again" — no second
   * key, no second choice of backup.
   *
   * Still asked, as the first attempt was: a retry that gets through replaces this site
   * the same way, and the summary offering it may be read long after that first yes.
   */
  const retry = useCallback(async () => {
    const { confirmed } = await alertService.confirmImpact({
      title: "Try the transfer again?",
      subject: status?.pairedWith
        ? { primary: `From ${status.pairedWith}`, secondary: "The same backup as the first attempt" }
        : null,
      points: [
        { icon: "transfer", text: TRANSFER_FIRST },
        ...restorePoints({ scope: SCOPE_FULL, fromOtherSite: true }),
      ],
      warning: restoreWarning({ fromOtherSite: true }),
      confirmButtonText: "Try again",
      cancelButtonText: "Cancel",
    });
    if (!confirmed) return false;

    setStarting(true);
    try {
      if (await retryMigration()) {
        setStatus(await fetchMigrationStatus());
        return true;
      }
      return false;
    } finally {
      setStarting(false);
    }
  }, [status]);

  /* --------------------------------------------------------------------- by hand */

  const loadManifest = useCallback(
    async (file) => {
      setLoadingManifest(true);
      try {
        let parsed;
        try {
          parsed = JSON.parse(await file.text());
        } catch (error) {
          await alertService.error(
            "That file is not a Tailwatch migration file.",
            "Could not read it"
          );
          return false;
        }

        // Asked once, here, and not again. Loading the file is the last moment before a
        // sequence that ends with this site replaced: the parts land in the backup tree,
        // and the moment the last one arrives the restore starts on its own.
        // The file lists each part with the half of the site it carries, so the question
        // says only what this backup will actually replace.
        const parts = Array.isArray(parsed?.parts) ? parsed.parts : [];
        const areas = parts.map((part) => part?.area);
        const bytes = parts.reduce((sum, part) => sum + (Number(part?.size) || 0), 0);
        const { confirmed } = await alertService.confirmImpact({
          title: "Migrate this site?",
          subject: {
            primary: parsed?.site_url ? `Backup from ${parsed.site_url}` : "Backup from another site",
            secondary: factLine(describeParts(parts.length), formatBytes(bytes)),
          },
          points: [
            { icon: "upload", text: "Nothing changes until every part has been uploaded. Then the restore starts on its own." },
            ...restorePoints({
              scope: scopeOfContents({ files: areas.includes("files"), database: areas.includes("database") }),
              fromOtherSite: true,
            }),
          ],
          warning: restoreWarning({ fromOtherSite: true }),
          confirmButtonText: "Migrate",
          cancelButtonText: "Cancel",
        });
        if (!confirmed) return false;

        const began = await beginManualImport(parsed);
        if (began) {
          setFinished("");
          rememberExpectedParts(parsed?.parts);
        }
        // Either way: a success needs the new state, and a refusal may have been the
        // switch, which the poll will report.
        await loadManualStatus();
        return began;
      } finally {
        setLoadingManifest(false);
      }
    },
    [loadManualStatus, rememberExpectedParts]
  );

  const uploadParts = useCallback(
    async (chosen) => {
      const current = manualRef.current;
      if (!chosen.length || !current) return;

      // Only the parts this import is still waiting for. Sending the rest would be
      // refused one at a time with a message about each, which reads like a fault rather
      // than the operator having selected the whole folder.
      const wanted = chosen.filter((file) => current.missing.includes(file.name));
      const ignored = chosen.length - wanted.length;

      if (!wanted.length) {
        await alertService.warning(
          "None of those files are parts this import is waiting for. Check the names against the list below.",
          "Nothing to upload"
        );
        return;
      }

      for (const file of wanted) {
        setUploading(file.name);
        setUploadPercent(0);

        // Read per part, from the ref: the site can revise the chunk size between parts,
        // and the floor means an answer of zero slows this down rather than slicing the
        // part a byte at a time.
        const bytes = chunkSize(manualRef.current?.chunkBytes);

        // How much of this part the site already holds, from the poll rather than from
        // anything this page was keeping — a reload is exactly the case this exists for,
        // and a reload is the moment the page's own count is gone.
        const from = Math.max(
          0,
          Number(manualRef.current?.resume?.[file.name]) || 0
        );

        const result = await uploadMigrationPart(
          file,
          bytes,
          (fraction) => setUploadPercent(Math.round(fraction * 100)),
          from
        );

        if (!result.ok) {
          setUploading("");
          if (result.featureDisabled) {
            markMigrationOff();
          }
          await alertService.error(result.message, `${file.name} was not accepted`);
          await loadManualStatus();
          return;
        }

        if (result.ready) {
          const folder = result.folder || manualRef.current?.folder || "";
          setFinished(folder);
          forgetExpectedParts();

          // The last part starts the restore, so the migration is not over — it has
          // moved. Following it is the whole point: the detail from here on is the
          // restore's, and leaving the operator on a screen reporting a finished
          // migration while the site is mid-replacement is the one thing this must not do.
          if (result.restoring) {
            navigate(`/dashboard/restore/${encodeURIComponent(folder)}`);
            return;
          }
        }

        // So the next part is checked against what the site knows now.
        await loadManualStatus();
      }

      setUploading("");
      setUploadPercent(0);

      if (ignored > 0) {
        await alertService.warning(
          `${ignored} selected ${ignored === 1 ? "file was" : "files were"} not part of this backup and ${ignored === 1 ? "was" : "were"} skipped.`,
          "Some files were skipped"
        );
      }
    },
    [loadManualStatus, markMigrationOff, navigate, forgetExpectedParts]
  );

  /**
   * Take in whatever is already sitting in this site's backup folder.
   *
   * The way round the browser for anyone who can reach the server directly. Carrying
   * gigabytes through a browser twice — once down from the other site, once up into this
   * one — is the weakest part of moving a site by hand, and parts put in place by FTP,
   * SSH or a file manager are just as good once they have been checked against the
   * migration file.
   *
   * The reply carries the same shape the status poll does, so the screen is updated from
   * it directly rather than waiting for the next round to notice: the operator pressed a
   * button and should see what it did.
   */
  const scanFolderForParts = useCallback(async () => {
    setScanning(true);
    let result;
    try {
      result = await scanForParts();
    } finally {
      setScanning(false);
    }

    if (!result) {
      return;
    }

    // A scan that takes in the last missing parts finishes the import the way the last
    // upload does, and starts the restore - so it is followed there the same way.
    if (result.ready) {
      const folder = result.folder || manualRef.current?.folder || "";
      setFinished(folder);
      forgetExpectedParts();
      if (result.restoring) {
        navigate(`/dashboard/restore/${encodeURIComponent(folder)}`);
        return;
      }
    }

    setManual((current) => ({
      ...(current || {}),
      phase: result.phase || current?.phase,
      missing: Array.isArray(result.missing) ? result.missing : current?.missing,
      total: typeof result.total === "number" ? result.total : current?.total,
      received:
        typeof result.received === "number" ? result.received : current?.received,
    }));

    // Then ask the site properly. The scan reports what it found, not what the import has
    // become — it marks parts received and saves, and it is the status call that says
    // whether anything followed from that. Reading it here means the screen never has to
    // wait for the next poll to catch up with a button the operator just pressed.
    await loadManualStatus();
  }, [loadManualStatus, navigate, forgetExpectedParts]);

  const cancelImport = useCallback(async () => {
    const confirmed = await alertService.confirm(
      "The parts uploaded so far will be deleted and the import will start over. Nothing on this site is affected.",
      "Discard this import?",
      "Discard",
      "Keep it"
    );
    if (!confirmed) return false;

    if (await cancelManualImport()) {
      forgetExpectedParts();
    }
    setFinished("");
    await loadManualStatus();
    return true;
  }, [loadManualStatus, forgetExpectedParts]);

  /* ----------------------------------------------------------------------- logs */

  /** Reading the record of a migration that has finished, on request. */
  const showLastLog = useCallback(async () => {
    const tail = await fetchMigrationLogs(0);
    if (tail) {
      logIndex.current = tail.lastLogIndex;
      setLogs(tail.newLogs);
    }
    setIsLogsVisible(true);
  }, []);

  const hideLog = useCallback(() => {
    // Put away for good when nothing is running: keeping the lines in memory would only
    // give the next visit a console to reopen.
    setLogs([]);
    logIndex.current = 0;
    setIsLogsVisible(false);
  }, []);

  return {
    // What the site says
    ready,
    gateKnown,
    pairing,
    status,
    manual,
    expectedParts,
    proPluginActive,
    migrationFeature,

    // What that means
    running,
    importing,
    applying,
    applied,
    applyFailed,
    busy,
    planBlocked,
    parentOff,
    migrationOff,
    inTheWay,
    blockAutomatic,
    blockManual,

    // In flight
    issuing,
    connecting,
    starting,
    loadingManifest,
    uploading,
    uploadPercent,
    scanning,
    finished,

    // Doing things
    issueKey,
    revokeKey,
    connect,
    resume,
    start,
    retry,
    loadManifest,
    uploadParts,
    scanFolderForParts,
    cancelImport,
    // Both doors into the settings panel — the lock card's toggle and the Change Settings
    // button — land here, so closing either refetches the whole module rather than the
    // corner of it that happened to be nearest.
    afterFeatureToggle: refreshAll,
    refresh: refreshAll,

    // The console
    logs,
    setLogs,
    isLogsVisible,
    setIsLogsVisible,
    showLastLog,
    hideLog,
  };
};

export default useMigration;
