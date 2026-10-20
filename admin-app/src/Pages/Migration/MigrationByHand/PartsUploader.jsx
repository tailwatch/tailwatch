import React, { useRef } from "react";
import { Check, CircleAlert, FileUp, Loader2, ScrollText } from "lucide-react";
import ProgresBar from "../../../Components/Progressbar/ProgresBar.jsx";
import LogsScreen from "../../../Components/LogsScreen/LogsScreen.jsx";
import MigrationNote from "../Components/MigrationNote";
import ScreenHeader from "../Components/ScreenHeader";
import ActionBar from "../Components/ActionBar";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { cn } from "../../../Components/Utils/cn";
import { chunkSize, formatBytes } from "../migrationFormat";
import { partsToShow } from "./partsList";
import { BY_HAND_STEPS } from "./steps";

// The same bar every other long operation here draws: brand while it runs.
const BAR_RUNNING = "linear-gradient(to right, #007980, #85cbcf)";

/**
 * The parts, as they arrive.
 *
 * Two numbers matter and they are different numbers: how many parts have landed, and how
 * far through the one currently being sent. A single bar cannot say both — a part is
 * anything up to several hundred megabytes, so a count that reads "3 of 9" sits still for
 * minutes at a time on a perfectly healthy upload.
 *
 * The list of what is still missing is the other half. Parts are selected from a folder by
 * name, so the names are what an operator matches against — and a part refused for its
 * name needs to be findable, not counted.
 */
const PartsUploader = ({
  manual,
  expectedParts,
  uploading,
  uploadPercent,
  scanning,
  blocked,
  logs,
  setLogs,
  isLogsVisible,
  onToggleLogs,
  onUpload,
  onScan,
  onDiscard,
  onBack,
}) => {
  const input = useRef(null);

  const received = manual?.received ?? 0;
  const total = manual?.total ?? 0;
  const missing = manual?.missing ?? [];
  const resume = manual?.resume ?? {};
  const partsPercent = total > 0 ? Math.round((received / total) * 100) : 0;
  const { knowsEveryPart, parts } = partsToShow(expectedParts, missing, total);

  const choose = async (event) => {
    const chosen = Array.from(event.target.files || []);
    if (input.current) {
      input.current.value = "";
    }
    if (chosen.length) {
      await onUpload(chosen);
    }
  };

  return (
    <div className="w-full space-y-5">
      <ScreenHeader
        eyebrow="Move it manually"
        title="Upload the parts"
        lede="Every part is checked against the migration file as it arrives. When the last one lands, this site is replaced with that backup automatically."
        steps={BY_HAND_STEPS}
        step={1}
        onBack={onBack}
        disabled={!!uploading}
      />

      {blocked && <MigrationNote icon={CircleAlert} tone="warning">{blocked}</MigrationNote>}

      <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <h3 className="break-all text-[14.5px] font-semibold leading-tight text-gray-900">
              {manual?.folder}
            </h3>
            <p className="mt-0.5 text-xs text-gray-500">
              {received} of {total} parts received
            </p>
          </div>
          <span className="shrink-0 rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide text-gray-500">
            {partsPercent}% complete
          </span>
        </div>

        <div className="mt-3">
          <ProgresBar progress={partsPercent} bgColor={BAR_RUNNING} />
        </div>

        {/* The part in flight, which the count above cannot show. Its own row, because a
            single large part can be the whole of what is happening for several minutes. */}
        {uploading ? (
          <div className="mt-3 flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-3 py-2.5">
            <Loader2
              className="mt-0.5 h-4 w-4 shrink-0 animate-spin text-brand-600"
              aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13px] font-medium text-gray-900">{uploading}</p>
              <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-white">
                <div
                  className="h-full rounded-full bg-brand-600 transition-all"
                  style={{ width: `${uploadPercent}%` }}
                />
              </div>
            </div>
            <span className="shrink-0 text-xs tabular-nums text-gray-500">
              {uploadPercent}%
            </span>
          </div>
        ) : (
          <p className="mt-3 text-xs leading-relaxed text-gray-500">
            Still waiting for {missing.length} {missing.length === 1 ? "part" : "parts"}.
            Large parts are sent in pieces of {formatBytes(chunkSize(manual?.chunkBytes))},
            so they do not have to fit inside this server's upload limit.
          </p>
        )}

        <div className="mt-4">
          <label className="text-xs font-medium text-gray-700">Add the parts</label>
          <input
            ref={input}
            type="file"
            multiple
            disabled={!!uploading}
            onChange={choose}
            className="mt-1 block w-full text-xs text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white hover:file:bg-opacity-80 disabled:cursor-not-allowed disabled:opacity-60"
          />
          <p className="mt-1.5 text-xs text-gray-500">
            Select as many as you like — anything that is not a part of this backup is
            skipped rather than refused one at a time.
          </p>
        </div>

        {/* The way round the browser, for anyone who can reach the server directly.
            Carrying gigabytes through a browser twice — once down from the other site,
            once up into this one — is the weakest part of moving a site by hand, and a
            part put in place by FTP is just as good once it has been checked against the
            migration file. Separated by a rule rather than given a tinted box of its own:
            it is the same step by another route, not a different one. */}
        <div className="mt-4 border-t border-gray-100 pt-4">
          <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-xs leading-relaxed text-gray-500">
              Already copied the parts onto this server yourself, by FTP or a file manager?
              Put them in the backup folder and have this site check for them instead of
              uploading them again.
            </p>
            <ActionButton
              onClick={onScan}
              isLoading={scanning}
              loadingText="Checking..."
              defaultText="Check the backup folder"
              isDisabled={!!uploading || scanning}
              className="shrink-0 whitespace-nowrap px-3 py-1"
            />
          </div>
        </div>
      </div>

      {/* Every part with the arrived ones ticked, when the names from the migration file
          are known: it says how big the job is, which a shrinking list of what is left
          cannot. Otherwise only the parts still to come. */}
      {parts.length > 0 && (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
              <FileUp className="h-3.5 w-3.5" aria-hidden="true" />
              {knowsEveryPart ? "Parts" : "Still to come"}
            </p>
            {knowsEveryPart && (
              <p className="text-xs text-gray-500">
                {received} of {total} received
              </p>
            )}
          </div>
          <ul className="mt-2.5 max-h-60 space-y-1 overflow-y-auto">
            {/* A part interrupted by a reload resumes, and its bar opens partway along.
                Said here so that reads as the site picking up where it left off rather
                than as a progress bar that has lost its place. */}
            {parts.map(({ file, done }) => {
              const already = done ? 0 : Number(resume?.[file]) || 0;
              return (
                <li
                  key={file}
                  className={cn(
                    "flex items-center gap-2.5 rounded-lg px-2.5 py-1.5",
                    done ? "bg-emerald-50/70" : "bg-gray-50"
                  )}
                >
                  <span
                    aria-hidden="true"
                    className={cn(
                      "flex h-4 w-4 shrink-0 items-center justify-center rounded-full border",
                      done
                        ? "border-emerald-500 bg-emerald-500 text-white"
                        : uploading === file
                        ? "border-brand-600 text-brand-600"
                        : "border-gray-300"
                    )}
                  >
                    {done ? (
                      <Check className="h-2.5 w-2.5" strokeWidth={3} aria-hidden="true" />
                    ) : uploading === file ? (
                      <Loader2 className="h-2.5 w-2.5 animate-spin" aria-hidden="true" />
                    ) : null}
                  </span>
                  <span
                    className={cn(
                      "min-w-0 flex-1 truncate font-mono text-xs",
                      done ? "text-gray-500" : "text-gray-700"
                    )}
                  >
                    {file}
                  </span>
                  {already > 0 && (
                    <span className="shrink-0 text-[11px] text-gray-500">
                      {already} {already === 1 ? "piece" : "pieces"} already here
                    </span>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      )}

      {/* The running commentary, on request.
          It stays closed while the operator is working — choosing files, watching a part
          climb — because a console unrolling under that is noise over the thing they are
          using. But it is the only place that says WHICH part was refused and why, so the
          moment anything looks wrong it has to be one press away rather than absent. */}
      {logs.length > 0 && (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
              <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                <ScrollText className="h-3.5 w-3.5" aria-hidden="true" />
                Log
              </p>
              <p className="mt-1 text-[13px] leading-relaxed text-gray-500">
                What this import is doing, line by line — including the reason any part was
                turned away.
              </p>
            </div>
            <ActionButton
              onClick={onToggleLogs}
              defaultText={isLogsVisible ? "Hide Logs" : "Show Logs"}
              className="shrink-0 rounded-md bg-[#007980] px-3 py-1 text-white hover:bg-opacity-80"
            />
          </div>

          {isLogsVisible && (
            <LogsScreen
              logs={logs}
              setLogs={setLogs}
              isLogsVisible={isLogsVisible}
              isPaused={false}
              isCanceled={false}
              isCompleted={false}
              cancelMessage="The import stopped."
              successMessage="Every part arrived and was checked."
              // The card above already says what is happening and what is still missing.
              showStallNotice={false}
            />
          )}
        </div>
      )}

      <ActionBar note="Nothing on this site has been replaced yet. The restore starts on its own once the last part arrives.">
        <button
          type="button"
          onClick={onDiscard}
          disabled={!!uploading}
          className="rounded-md px-3 py-1.5 text-xs font-medium text-red-600 transition-colors hover:bg-red-50 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-transparent"
        >
          Discard this import
        </button>
      </ActionBar>
    </div>
  );
};

export default PartsUploader;
