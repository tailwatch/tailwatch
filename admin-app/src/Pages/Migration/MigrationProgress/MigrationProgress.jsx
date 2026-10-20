import React from "react";
import { useNavigate } from "react-router-dom";
import { CircleAlert, Loader2, ShieldAlert, ShieldCheck } from "lucide-react";
import LogsScreen from "../../../Components/LogsScreen/LogsScreen.jsx";
import ProgresBar from "../../../Components/Progressbar/ProgresBar.jsx";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { cn } from "../../../Components/Utils/cn";
import { formatBytes, transferRate } from "../migrationFormat";
import { MIGRATION_PHASE_LABELS } from "../MigrationServices/MigrationServices.jsx";

// The same bar every other module uses: brand teal while it runs.
const BAR_RUNNING = "linear-gradient(to right, #007980, #85cbcf)";

/**
 * A transfer, while it is happening.
 *
 * The status is already being asked for by the module's one poll, so this screen adds only
 * the running commentary — a second status poll would ask the same site the same question
 * twice as often for no more information.
 *
 * Two things this has to get right, because a migration is the one operation where the
 * interesting half is on a machine the operator cannot see:
 *
 * - **Bytes, not parts.** A part is anything from 50 to 400 MB, so "3 of 9" can sit still
 *   for ten minutes on a healthy transfer. The bytes and the averaged rate are what say
 *   whether anything is moving.
 * - **Which way it is going.** When this site cannot fetch the backup the other one pushes
 *   it instead. The transfer is still fine, but it is now waiting on the far end — which
 *   is where to look if it stops, and nobody can guess that from a progress bar.
 */
const MigrationProgress = ({ migration }) => {
  const navigate = useNavigate();
  const {
    status,
    applying,
    logs,
    setLogs,
    isLogsVisible,
    setIsLogsVisible,
  } = migration;

  const phase = status?.phase ?? "manifest";
  const pushing = phase === "push";
  const bytesTotal = status?.bytesTotal ?? 0;
  const bytesDone = status?.bytesDone ?? 0;
  // The site's own figure where it has one; bytes where it does not. A transfer that has
  // only just been asked for has neither, and 0% is the honest answer then.
  const percent = applying
    ? 100
    : Math.min(
        100,
        Math.round(status?.percent || (bytesTotal ? (bytesDone / bytesTotal) * 100 : 0))
      );

  const rate = transferRate(bytesDone, status?.seconds);

  const stats = [
    status?.partsTotal
      ? { key: "parts", label: "Parts", value: `${status.partsDone} / ${status.partsTotal}` }
      : null,
    bytesTotal
      ? {
          key: "copied",
          label: "Copied",
          value: `${formatBytes(bytesDone)} / ${formatBytes(bytesTotal)}`,
        }
      : null,
    rate ? { key: "rate", label: "Rate", value: rate } : null,
  ].filter(Boolean);

  return (
    // Full width, unlike the screens that only ask a question: the console is the thing
    // being read here, and a log line wrapped into three is harder to follow than the run.
    <div className="w-full">
      <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <h3 className="text-base font-semibold text-gray-800">
              {applying ? "Replacing this site" : "Bringing that site here"}
            </h3>
            {status?.folder && (
              <p className="mt-0.5 break-all text-xs text-gray-500">
                <span className="font-medium">{status.folder}</span>
                {status?.pairedWith ? ` from ${status.pairedWith}` : ""}
              </p>
            )}
          </div>
          {logs.length > 0 && (
            <ActionButton
              onClick={() => setIsLogsVisible(!isLogsVisible)}
              defaultText={isLogsVisible ? "Hide Logs" : "Show Logs"}
              className="shrink-0 rounded-md bg-[#007980] px-3 py-1 text-white hover:bg-opacity-80"
            />
          )}
        </div>

        <div className="mt-3">
          <ProgresBar progress={percent} bgColor={BAR_RUNNING} />
        </div>

        {/* What is happening right now, tinted by what it means for this site: a transfer
            changes nothing here, the restore that follows it changes everything. */}
        <div
          className={cn(
            "mt-3 flex items-start gap-3 rounded-xl border px-3 py-2.5",
            applying ? "border-amber-200 bg-amber-50" : "border-brand-100 bg-brand-50/60"
          )}
        >
          {applying ? (
            <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
          ) : (
            <Loader2
              className="mt-0.5 h-5 w-5 shrink-0 animate-spin text-brand-600"
              aria-hidden="true"
            />
          )}
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-gray-900">
              {applying
                ? "Replacing this site's files and database"
                : MIGRATION_PHASE_LABELS[phase] ?? phase}
            </p>
            <p className="mt-0.5 text-xs text-gray-600">
              {applying
                ? "The backup has been transferred. The rest of this migration is reported on the Restore screen."
                : "This continues in the background. You can leave this page."}
            </p>
          </div>
          {applying && (
            <ActionButton
              onClick={() =>
                navigate(`/dashboard/restore/${encodeURIComponent(status?.folder || "")}`)
              }
              defaultText="Watch it finish"
              className="shrink-0 px-3 py-1"
            />
          )}
        </div>

        {!applying && stats.length > 0 && (
          <dl className="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-3">
            {stats.map((stat) => (
              <div key={stat.key} className="bg-white px-3 py-2.5">
                <dt className="text-[10.5px] font-semibold uppercase tracking-wide text-gray-400">
                  {stat.label}
                </dt>
                <dd className="mt-0.5 truncate text-sm font-medium tabular-nums text-gray-800">
                  {stat.value}
                </dd>
              </div>
            ))}
          </dl>
        )}

        {pushing && (
          <div className="mt-3 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5">
            <CircleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
            <p className="text-xs leading-relaxed text-gray-700">
              This site could not download the backup, so the other site is sending it
              instead. Nothing is wrong yet — but if this stops moving, the problem is at
              the other end.
            </p>
          </div>
        )}

        {logs.length > 0 && (
          <div className="mt-4">
            <LogsScreen
              logs={logs}
              setLogs={setLogs}
              isLogsVisible={isLogsVisible}
              isPaused={false}
              isCanceled={false}
              isCompleted={applying}
              cancelMessage="The migration stopped."
              successMessage="Every part arrived and was checked."
              // The card above already says when the site has gone quiet, in words that fit
              // a transfer. A second notice saying "don't panic" is the wrong voice for it.
              showStallNotice={false}
            />
          </div>
        )}
      </div>

      <div className="mt-3 flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50/70 px-3.5 py-3">
        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
        <p className="text-[13px] leading-relaxed text-gray-600">
          The transfer runs on this site's scheduler, so it keeps going whether or not this
          page is open. Nothing here is replaced until every part has arrived and been
          checked.
        </p>
      </div>
    </div>
  );
};

export default MigrationProgress;
