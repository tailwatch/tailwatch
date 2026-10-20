import React from "react";
import { useNavigate } from "react-router-dom";
import { CheckCircle2, ScrollText, XCircle } from "lucide-react";
import LogsScreen from "../../../Components/LogsScreen/LogsScreen.jsx";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { cn } from "../../../Components/Utils/cn";
import { formatBytes } from "../migrationFormat";

/**
 * The last transfer, once it is over.
 *
 * Its own card above the screen that starts a new one, rather than a panel stacked under a
 * live form: several results — transferred, applied, failed — stacked under a form still
 * offering to start another make it impossible to tell which one is current.
 *
 * A finished transfer stays on record indefinitely, so nothing here is presented as news:
 * the log is fetched only when it is asked for, and the way on to the restore that applied
 * it is a link, not a redirect.
 */
const MigrationSummary = ({ migration }) => {
  const navigate = useNavigate();
  const {
    status,
    applied,
    applyFailed,
    starting,
    retry,
    logs,
    setLogs,
    isLogsVisible,
    showLastLog,
    hideLog,
    blockAutomatic,
  } = migration;

  const failed = status?.phase === "failed";
  const errors = status?.errors || [];

  const facts = [
    status?.pairedWith ? { key: "from", label: "From", value: status.pairedWith } : null,
    status?.folder ? { key: "backup", label: "Backup", value: status.folder } : null,
    status?.partsTotal
      ? { key: "parts", label: "Parts", value: `${status.partsDone} / ${status.partsTotal}` }
      : null,
    status?.bytesDone
      ? { key: "copied", label: "Copied", value: formatBytes(status.bytesDone) }
      : null,
  ].filter(Boolean);

  // What the transfer amounts to for this site, which is not the same question as whether
  // the bytes arrived. A transfer that landed and then failed to apply is a site that has
  // not moved, and saying "complete" about it would be the most misleading thing here.
  const headline = failed
    ? "The transfer did not finish"
    : applyFailed
    ? "The backup arrived, but applying it did not finish"
    : applied
    ? "That site was brought here and applied"
    : "The backup was transferred";

  const detail = failed
    ? "Nothing on this site was changed."
    : applyFailed
    ? "The transfer itself was fine. What stopped was the restore that replaces this site."
    : applied
    ? "This site is now a copy of the one it was brought from."
    : "Replacing this site's files and database runs on the Restore screen, which is where the rest of this migration is reported.";

  const bad = failed || applyFailed;

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="flex items-start gap-3">
        <span
          className={cn(
            "flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border",
            bad
              ? "border-red-200 bg-red-50 text-red-600"
              : "border-emerald-200 bg-emerald-50 text-emerald-600"
          )}
        >
          {bad ? (
            <XCircle className="h-5 w-5" aria-hidden="true" />
          ) : (
            <CheckCircle2 className="h-5 w-5" aria-hidden="true" />
          )}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-wider text-gray-400">
            Last migration
          </p>
          <h3 className="mt-0.5 text-sm font-semibold text-gray-900">{headline}</h3>
          <p className="mt-1 text-[13px] leading-relaxed text-gray-600">{detail}</p>
        </div>
      </div>

      {facts.length > 0 && (
        <dl className="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-200 sm:grid-cols-4">
          {facts.map((fact) => (
            <div key={fact.key} className="bg-white px-3 py-2.5">
              <dt className="text-[10.5px] font-semibold uppercase tracking-wide text-gray-400">
                {fact.label}
              </dt>
              <dd className="mt-0.5 truncate text-[13px] font-medium text-gray-800" title={fact.value}>
                {fact.value}
              </dd>
            </div>
          ))}
        </dl>
      )}

      {errors.length > 0 && (
        <ul className="mt-3 list-inside list-disc space-y-1 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-xs text-red-800">
          {errors.map((message, index) => (
            <li key={index}>{message}</li>
          ))}
        </ul>
      )}

      <div className="mt-4 flex flex-wrap items-center gap-2.5">
        {failed && (
          <ActionButton
            onClick={retry}
            isLoading={starting}
            loadingText="Starting..."
            defaultText="Try again"
            isDisabled={!!blockAutomatic || starting}
            className="px-3 py-1"
          />
        )}

        {status?.folder && !failed && (
          <ActionButton
            onClick={() =>
              navigate(`/dashboard/restore/${encodeURIComponent(status.folder)}`)
            }
            defaultText={applied || applyFailed ? "Open the report" : "Open the Restore screen"}
            className="px-3 py-1"
          />
        )}

        <button
          type="button"
          onClick={isLogsVisible ? hideLog : showLastLog}
          className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900"
        >
          <ScrollText className="h-3.5 w-3.5" aria-hidden="true" />
          {isLogsVisible ? "Hide the log" : "Read the log"}
        </button>
      </div>

      {failed && (
        <p className="mt-3 text-xs leading-relaxed text-gray-500">
          Trying again uses the pairing already made. If the other site has since forgotten
          it, generate a new key there and connect again.
        </p>
      )}

      {logs.length > 0 && isLogsVisible && (
        <div className="mt-4">
          <p className="mb-2 text-xs text-gray-500">
            From the last migration. Nothing is being written to it now.
          </p>
          <LogsScreen
            logs={logs}
            setLogs={setLogs}
            isLogsVisible={isLogsVisible}
            isPaused={false}
            isCanceled={false}
            isCompleted={false}
            cancelMessage="The migration stopped."
            successMessage="Every part arrived and was checked."
            // Nothing is running, so there is no silence to explain.
            showStallNotice={false}
          />
        </div>
      )}
    </div>
  );
};

export default MigrationSummary;
