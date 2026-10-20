import axios from "axios";
import { alertService } from "../../../Components/AlertService/AlertService";
import {
  blockedByProcess,
  announceBlocked,
} from "../../../Components/Hooks/OwningProcess/blockedProcess";
/* global tailwatch_ajax */

/**
 * A migration moves one whole site onto another, so both halves of it are destructive in
 * their own way: the source hands out a credential that reads its entire backup, and the
 * destination ends up replaced. Every refusal below is surfaced with its own reason rather
 * than a generic failure, because "it didn't work" is useless when the two sites are on
 * different hosts and only one of them is in front of you.
 */

const post = async (actionType, data = {}) => {
  const formData = new FormData();
  formData.append("action", "tailwatch_global_ajax_handler");
  formData.append("action_type", actionType);
  formData.append("nonce", tailwatch_ajax.nonce);
  formData.append("data", JSON.stringify(data));

  const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  // The dispatcher wraps every controller response one level deep.
  return response?.data?.data ?? {};
};

/**
 * Whether a refusal was the feature switch rather than anything about the request.
 *
 * The dispatcher answers every gated action with this pair before the controller runs —
 * the same pair the status poll carries. Recognising it here is what lets a press put the
 * page into its disabled state, instead of an alert that says "not enabled" over controls
 * that still look usable.
 */
const isFeatureRefusal = (result) =>
  result?.feature_enable === false || result?.parent_enable === false;

/** The one sentence that names the setting, wherever a refusal surfaces. */
const featureOffNotice = async () => {
  await alertService.warning(
    'Migration is switched off for this site. Turn on "Allow Migration" under Restore & Migration in Features.',
    "Migration is switched off"
  );
};

/**
 * SOURCE — mint a pairing key.
 *
 * The key is returned exactly once and is not stored in a recoverable form, so the caller
 * has to keep what it gets. Losing it costs nothing: generate another.
 */
export const issuePairingKey = async (folder = "") => {
  try {
    const result = await post("tailwatch_migration_issue_key", { folder });
    if (isFeatureRefusal(result)) {
      await featureOffNotice();
      return null;
    }
    // 402 is the plan, not a fault: the plan does not include the automatic transfer.
    // Titled as an upgrade rather than a failure, because nothing went wrong and the
    // by-hand route below still does the same job.
    if (result.code === 402) {
      await alertService.info(
        result.message ||
          "Your Tailwatch plan does not include moving a site automatically.",
        "Included in a higher plan"
      );
      return null;
    }

    // 409 and 422 are about the backup that was chosen, not about the key — the message
    // says which, and a generic "could not be generated" would hide it.
    if (result.code === 409 || result.code === 422) {
      await alertService.error(
        result.message || "That backup cannot be sent to another site.",
        "Not that backup"
      );
      return null;
    }

    if (result.code !== 200 || !result.key) {
      await alertService.error(
        result.message || "The pairing key could not be generated.",
        "Could not start"
      );
      return null;
    }
    return result;
  } catch (error) {
    console.error("Error issuing pairing key", error);
    await alertService.error(
      "The pairing key could not be generated.",
      "Could not start"
    );
    return null;
  }
};

/** SOURCE — whether a key is currently pending, without returning it. */
export const fetchPairingStatus = async () => {
  try {
    const result = await post("tailwatch_migration_pairing_status");
    return result.code === 200 ? result : null;
  } catch (error) {
    return null;
  }
};

/** SOURCE — discard the pending key and any paired session. */
export const revokePairingKey = async () => {
  try {
    const result = await post("tailwatch_migration_revoke_key");
    return result.code === 200;
  } catch (error) {
    return false;
  }
};

/**
 * Explain a refusal from either of the two pairing calls.
 *
 * Both fail in the same ways and for the same reasons — they are two halves of one act —
 * so the wording lives in one place rather than drifting apart between them.
 */
const explainRefusal = async (result, fallback) => {
  if (isFeatureRefusal(result)) {
    await featureOffNotice();
    return;
  }

  // 422 covers both "that address is not one this site may contact" and "the other site
  // rejected the pairing" — the message says which.
  if (result.code === 422) {
    await alertService.error(
      result.message || "The other site could not be paired with.",
      "Pairing failed"
    );
    return;
  }

  if (result.code === 409) {
    // A restore already running is the common way to reach this: both replace the
    // site, so the second one is refused. Naming it and going there is the only
    // useful thing to do with the refusal.
    const blocked = blockedByProcess(result);
    await alertService.warning(
      result.message ||
        "Another background process is running. Wait for it to finish before migrating.",
      "Process in progress"
    );
    announceBlocked(blocked);
    return;
  }

  if (result.code === 403) {
    await alertService.error(
      result.message || "You do not have permission to migrate this site.",
      "Not permitted"
    );
    return;
  }

  await alertService.error(result.message || fallback, "Could not start");
};

/**
 * DESTINATION — pair with a source and ask which of its backups it will send.
 *
 * The step before the transfer. It spends the key, so the pairing it leaves behind is what
 * startMigration() continues from: choosing a backup must not cost a second key.
 *
 * Returns { backups, choosable } or null when the pairing itself failed. `choosable` is
 * false when the other site could not be asked — an older build there — which is not an
 * error: that site has exactly one answer, and the transfer will take its newest backup.
 */
export const listSourceBackups = async (sourceUrl, pairingKey) => {
  try {
    const result = await post("tailwatch_list_source_backups", {
      sourceUrl,
      pairingKey,
    });

    if (result.code === 200) {
      return {
        sourceUrl: result.sourceUrl || sourceUrl,
        backups: Array.isArray(result.backups) ? result.backups : [],
        choosable: result.choosable !== false,
        // Whether the other site's key names one backup rather than opening its history.
        pinned: result.pinned === true,
      };
    }

    await explainRefusal(result, "The other site could not be paired with.");
    return null;
  } catch (error) {
    console.error("Error listing the other site's backups", error);
    await alertService.error(
      "The other site could not be paired with. Check that this site can reach it.",
      "Could not start"
    );
    return null;
  }
};

/**
 * DESTINATION — begin pulling one backup from a paired source.
 *
 * `pairingKey` is empty when the pairing was already made by listSourceBackups(); `folder`
 * is empty when the other site is to choose, which means its newest backup.
 *
 * Returns true only when the transfer actually started. Everything else is explained here
 * so the caller only has to handle the happy path.
 */
export const startMigration = async (sourceUrl, pairingKey, folder = "") => {
  try {
    const result = await post("tailwatch_start_migration", {
      sourceUrl,
      pairingKey,
      folder,
    });

    if (result.code === 200) {
      return true;
    }

    await explainRefusal(result, "The migration could not be started.");
    return false;
  } catch (error) {
    console.error("Error starting migration", error);
    await alertService.error(
      "The migration could not be started. Check that this site can reach the other one.",
      "Could not start"
    );
    return false;
  }
};

/**
 * DESTINATION — transfer progress.
 *
 * Returns null on a transport error rather than a fake idle state: a failed poll must not
 * be read as "the transfer finished".
 */
export const fetchMigrationStatus = async () => {
  try {
    const result = await post("tailwatch_migration_status");
    if (result.code !== 200) {
      return null;
    }
    return {
      phase: result.phase ?? "idle",
      folder: result.folder ?? "",
      partsTotal: result.parts_total ?? 0,
      partsDone: result.parts_done ?? 0,
      percent: result.percent ?? 0,
      // Bytes rather than parts: a part is anything from 50 to 400 MB, so a part count
      // cannot tell a slow transfer from a stopped one.
      bytesTotal: result.bytes_total ?? 0,
      bytesDone: result.bytes_done ?? 0,
      seconds: result.seconds ?? 0,
      errors: Array.isArray(result.errors) ? result.errors : [],
      readyToRestore: !!result.ready_to_restore,
      // A site paired with but not yet transferred from — what is left after a page
      // reload between choosing a site and choosing its backup.
      pairedWith: result.paired_with ?? "",
    };
  } catch (error) {
    return null;
  }
};

/**
 * DESTINATION — try a stopped transfer again.
 *
 * No key: the pairing the failed transfer was using is still there, and the source keeps
 * its session for an hour past its last authenticated request. Past that it refuses, and
 * the message says so — which is when a new key is genuinely needed.
 */
export const retryMigration = async () => {
  try {
    const result = await post("tailwatch_retry_migration");

    if (result.code === 200) {
      return true;
    }

    await explainRefusal(result, "The transfer could not be started again.");
    return false;
  } catch (error) {
    console.error("Error retrying migration", error);
    await alertService.error(
      "The transfer could not be started again. Check that this site can reach the other one.",
      "Could not start"
    );
    return false;
  }
};

export const MIGRATION_PHASE_LABELS = {
  idle: "Nothing running",
  manifest: "Asking the other site what to send",
  pull: "Transferring the backup",
  // The fallback: this site could not fetch the backup, so the other one is sending
  // it instead. Worth naming rather than hiding — the transfer is still going, but
  // it is now waiting on the far end, which is where to look if it stops.
  push: "Waiting for the other site to send it",
  done: "Transfer complete",
  failed: "Transfer failed",
};

/* ==========================================================================
 * MANUAL — carrying the backup by hand.
 *
 * The automatic transfer needs the two sites to be able to reach each other. Plenty
 * cannot: a local build, a site behind a VPN, a server with outbound HTTP blocked, an
 * install whose address does not resolve yet. For those, a person moving the files is the
 * only thing that always works, and this is that path.
 *
 * The manifest is a packing list, not the backup. It carries no credential and grants
 * nothing — holding one lets you know a backup's part names and digests, not fetch them —
 * which is why it is safe to email.
 * ========================================================================== */

/**
 * SOURCE — describe one backup so it can be carried elsewhere.
 *
 * Returns `{ manifest }` on success and `{ message }` on a refusal, so a caller that
 * renders the reason on the page itself can pass `quiet` and not be interrupted by an
 * alert saying the same thing twice.
 */
export const exportMigrationManifest = async (folderDate, { quiet = false } = {}) => {
  const explain = async (message, title) => {
    if (!quiet) {
      await alertService.error(message, title);
    }
    return { message };
  };

  try {
    const result = await post("tailwatch_export_migration_manifest", {
      folderDate,
    });

    if (isFeatureRefusal(result)) {
      if (quiet) {
        return {
          message:
            'Migration is switched off for this site. Turn on "Allow Migration" under Restore & Migration in Features.',
          featureDisabled: true,
        };
      }
      await featureOffNotice();
      return { featureDisabled: true };
    }

    if (result.code !== 200 || !result.manifest) {
      return explain(
        result.message || "That backup could not be exported.",
        "Could not export"
      );
    }

    return { manifest: result.manifest };
  } catch (error) {
    console.error("Error exporting migration manifest", error);
    return explain(
      "That backup could not be exported.",
      "Could not export"
    );
  }
};

/**
 * SOURCE — a one-shot URL for one part of a backup.
 *
 * The grant is minted per file and expires; it is not a standing permission, so a link
 * that leaks is worth very little and not for long.
 */
export const requestPartDownload = async (folder, part) => {
  try {
    const result = await post("tailwatch_issue_backup_download", {
      folder,
      file: part.file,
      area: part.area,
    });

    if (result.code === 200 && result.url) {
      return result.url;
    }

    if (isFeatureRefusal(result)) {
      await featureOffNotice();
      return null;
    }

    await alertService.error(
      result.message || "That backup file is not available for download.",
      "Could not download"
    );
    return null;
  } catch (error) {
    console.error("Error requesting a download link", error);
    await alertService.error(
      "That backup file is not available for download.",
      "Could not download"
    );
    return null;
  }
};

/**
 * DESTINATION — take in parts that are already in the backup folder.
 *
 * The way round the browser for anyone who can reach the server directly. Parts put in
 * place by FTP, rsync or a file manager are found, checked against the migration file and
 * counted as received.
 */
export const scanForParts = async () => {
  try {
    const result = await post("tailwatch_scan_for_parts");

    if (isFeatureRefusal(result)) {
      await featureOffNotice();
      return null;
    }

    if (result.code !== 200) {
      await alertService.error(
        result.message || "This site's backup folder could not be checked.",
        "Could not check"
      );
      return null;
    }

    // Named rather than counted: a part that is present but wrong is the one worth
    // knowing about, because the import would otherwise carry on and the restore would
    // fail later with the site already closed.
    if (Array.isArray(result.rejected) && result.rejected.length) {
      await alertService.warning(
        `These files are in the folder but do not match this backup, so they were not taken in: ${result.rejected.join(
          ", "
        )}`,
        "Some files did not match"
      );
    } else {
      await alertService.info(result.message, "Checked the backup folder");
    }

    return result;
  } catch (error) {
    console.error("Error scanning for parts", error);
    await alertService.error(
      "This site's backup folder could not be checked.",
      "Could not check"
    );
    return null;
  }
};

/** DESTINATION — accept a manifest and start expecting its parts. */
export const beginManualImport = async (manifest) => {
  try {
    const result = await post("tailwatch_begin_manual_import", { manifest });
    if (isFeatureRefusal(result)) {
      await featureOffNotice();
      return null;
    }
    if (result.code !== 200) {
      await alertService.error(
        result.message || "That migration file could not be read.",
        result.code === 409 ? "Already present" : "Could not start"
      );
      return null;
    }
    return result;
  } catch (error) {
    console.error("Error starting manual import", error);
    await alertService.error(
      "That migration file could not be read.",
      "Could not start"
    );
    return null;
  }
};

/** Attempts per chunk, the first one included. */
export const CHUNK_ATTEMPTS = 3;

/** Waits before the 2nd and 3rd attempts, in ms. Short, then long enough to outlast a blip. */
export const CHUNK_RETRY_WAITS = [1000, 3000];

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Whether a chunk that did not land is worth sending again.
 *
 * Exported because it is the whole decision, and the expensive mistake is retrying the wrong
 * thing. A transport failure means the request never got an answer, and a 500 means the site
 * could not write the piece — a dropped connection, a host hiccup, a moment of disk
 * pressure, all of which pass. Everything else is the site being specific: 400 the chunk set
 * is unusable, 409 no import has been started, 413 the chunk is bigger than this host
 * accepts, and the feature having been switched off mid-upload. Re-sending any of those
 * cannot change the answer; it only delays telling the operator the one thing they can act
 * on, three times over.
 *
 * @param {unknown} transport Error thrown by the request, or null when it answered.
 * @param {object|null} result The site's decoded answer, when there was one.
 * @returns {boolean}
 */
export const chunkIsWorthRetrying = (transport, result) =>
  null !== transport && undefined !== transport ? true : 500 === result?.code;

/**
 * DESTINATION — send one part, split into chunks the host will accept.
 *
 * Chunking is not an optimisation. A 50MB part will not survive most hosts' upload limits
 * in one request, and telling people to raise those limits is telling them to reconfigure
 * their server in order to use a backup plugin. The size comes from the site itself —
 * guessing it here is how you get "no upload was received" with nothing to act on.
 *
 * Resolves with what the server said about the LAST chunk, because that is the response
 * that carries the verification result and whether the whole set has landed.
 *
 * `startChunk` is how many leading pieces the site says it already holds, so a part cut off
 * by a reload carries on rather than starting over — on a part of 80MB that is the
 * difference between losing a minute and losing ten. It is the site's count and never a
 * remembered one: the browser forgets everything on reload, and the pieces on disk are the
 * only honest record of what arrived.
 */
export const uploadMigrationPart = async (
  file,
  chunkBytes,
  onProgress,
  startChunk = 0
) => {
  const size = Math.max(1, chunkBytes);
  const chunks = Math.max(1, Math.ceil(file.size / size));

  // An offset at or past the end describes a part sliced at a different chunk size from
  // the site's current one, so its pieces do not line up. Send the whole thing.
  const from = startChunk > 0 && startChunk < chunks ? startChunk : 0;

  // Where the bar starts. Without this a part resuming at its halfway point reads as
  // stalled at zero until the first chunk of this run lands.
  if (from > 0 && onProgress) {
    onProgress(from / chunks);
  }

  for (let chunk = from; chunk < chunks; chunk += 1) {
    const blob = file.slice(chunk * size, Math.min(file.size, (chunk + 1) * size));

    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_upload_migration_chunk");
    formData.append("nonce", tailwatch_ajax.nonce);
    formData.append("data", JSON.stringify({}));
    formData.append("file", file.name);
    formData.append("chunk", String(chunk));
    formData.append("chunks", String(chunks));
    formData.append("from", String(from));
    formData.append("part", blob, file.name);

    // Re-sending is safe: the same index writes the same `.part` file and the site
    // overwrites it, so an attempt that half-arrived cannot leave a short piece behind or
    // count twice.
    let result = null;
    let transport = null;

    for (let attempt = 1; attempt <= CHUNK_ATTEMPTS; attempt += 1) {
      try {
        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
          headers: { "Content-Type": "multipart/form-data" },
        });
        result = response?.data?.data ?? {};
        transport = null;
      } catch (error) {
        transport = error;
        result = null;
      }

      if (!chunkIsWorthRetrying(transport, result) || attempt === CHUNK_ATTEMPTS) {
        break;
      }

      console.warn(
        `Chunk ${chunk + 1} of ${chunks} for ${file.name} did not land; attempt ${
          attempt + 1
        } of ${CHUNK_ATTEMPTS}`
      );
      await pause(CHUNK_RETRY_WAITS[attempt - 1] ?? 3000);
    }

    if (transport) {
      console.error("Error uploading migration chunk", transport);
      return {
        ok: false,
        // Says the attempts were made, so "check the connection" reads as the next step
        // rather than as something that might have worked on a second press.
        message: `That upload did not reach the site after ${CHUNK_ATTEMPTS} attempts. Check the connection and try the part again.`,
      };
    }

    // Mid-upload the switch can still be turned off. Stopping with the reason named beats
    // grinding through the remaining chunks to be refused on every one of them.
    if (isFeatureRefusal(result)) {
      return {
        ok: false,
        featureDisabled: true,
        message:
          'Migration was switched off while this was uploading. Turn on "Allow Migration" under Restore & Migration in Features.',
      };
    }

    if (result.code !== 200) {
      return {
        ok: false,
        message: result.message || "That part was refused by this site.",
      };
    }

    if (onProgress) {
      onProgress((chunk + 1) / chunks);
    }

    // The final chunk is the one that carries assembly, verification, publication — and,
    // when it was the last outstanding part, the start of the restore that applies the
    // whole set. `restoring` is what tells the screen the migration has moved rather
    // than ended.
    if (chunk + 1 === chunks) {
      return {
        ok: true,
        ready: !!result.ready,
        restoring: !!result.restoring,
        folder: result.folder ?? "",
        outstanding: result.outstanding ?? 0,
      };
    }
  }

  return { ok: true, ready: false, outstanding: 0 };
};

/**
 * DESTINATION — what the import is still waiting for.
 *
 * Returns null on a transport error rather than a fake idle state, so a failed poll is
 * never read as "the import finished".
 */
export const fetchManualImportStatus = async () => {
  try {
    const result = await post("tailwatch_manual_import_status");
    if (result.code !== 200) {
      return null;
    }
    return {
      // The same pair every other module reads, carried on every poll so the page can say
      // so plainly rather than refusing each action. A backend that sends neither has to
      // read as enabled.
      featureEnable: result.feature_enable !== false,
      parentEnable: result.parent_enable !== false,
      phase: result.phase ?? "idle",
      folder: result.folder ?? "",
      total: result.total ?? 0,
      received: result.received ?? 0,
      missing: Array.isArray(result.missing) ? result.missing : [],
      // { part name: whole pieces of it already on the site }, for the parts still
      // outstanding. A backend that sends none has to read as "start at zero".
      resume:
        result.resume && typeof result.resume === "object" ? result.resume : {},
      chunkBytes: result.chunk_bytes ?? 0,
      // What would refuse a migration started right now — the same judgement the start
      // itself makes, so the screen can say it before anything is pressed rather than
      // after. Empty when nothing is in the way.
      blockedBy: result.blocked_by ?? "",
      blockedReason: result.blocked_reason ?? "",
    };
  } catch (error) {
    return null;
  }
};

/** DESTINATION — abandon a part-finished import and remove what it half-received. */
export const cancelManualImport = async () => {
  try {
    const result = await post("tailwatch_cancel_manual_import");
    return result.code === 200;
  } catch (error) {
    return false;
  }
};


/**
 * The running commentary for a migration.
 *
 * A part count cannot say WHICH part was refused or why. This can, and it is the same
 * console every other long operation in the plugin uses.
 */
export const fetchMigrationLogs = async (lastLogIndex = 0) => {
  try {
    const result = await post("tailwatch_migration_logs", { lastLogIndex });
    if (result.code !== 200) {
      return null;
    }
    return {
      newLogs: Array.isArray(result.new_logs) ? result.new_logs : [],
      lastLogIndex: result.last_log_index ?? lastLogIndex,
    };
  } catch (error) {
    return null;
  }
};
