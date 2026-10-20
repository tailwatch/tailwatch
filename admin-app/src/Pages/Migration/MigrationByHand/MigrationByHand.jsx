import React from "react";
import { useNavigate } from "react-router-dom";
import { CheckCircle2 } from "lucide-react";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import ManifestLoader from "./ManifestLoader";
import PartsUploader from "./PartsUploader";

/**
 * Moving a site by hand, which is the route that always works.
 *
 * Only the half that happens HERE. Exporting is done on the other site, from its own
 * Backup Vault, so this screen carries no instructions for a machine the operator is not
 * sitting at.
 *
 * Which of the two screens shows is read from the site, not from what was pressed: an
 * import outlives the page, so arriving here with parts already uploaded lands on the
 * uploader rather than on a form offering to start the import over.
 */
const MigrationByHand = ({ migration, onLeave }) => {
  const navigate = useNavigate();
  const {
    manual,
    expectedParts,
    importing,
    finished,
    loadingManifest,
    uploading,
    uploadPercent,
    scanning,
    blockManual,
    migrationOff,
    logs,
    setLogs,
    isLogsVisible,
    setIsLogsVisible,
    loadManifest,
    uploadParts,
    scanFolderForParts,
    cancelImport,
  } = migration;

  // Every part arrived and the restore has it. The migration is not over — it has moved,
  // and the screen that reports the rest of it is the Restore screen.
  if (finished) {
    return (
      <div className="w-full">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
          <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600">
              <CheckCircle2 className="h-5 w-5" aria-hidden="true" />
            </span>
            <div className="min-w-0 flex-1">
              <h3 className="text-sm font-semibold text-gray-900">
                {finished} arrived complete and is being applied to this site.
              </h3>
              <p className="mt-1 text-[13px] leading-relaxed text-gray-600">
                Every part was checked against the migration file. Replacing this site's
                files and database runs on the Restore screen, which is where the rest of
                this migration is reported.
              </p>
            </div>
          </div>
          <div className="mt-4">
            <ActionButton
              onClick={() =>
                navigate(`/dashboard/restore/${encodeURIComponent(finished)}`)
              }
              defaultText="Watch it finish"
              className="px-3 py-1"
            />
          </div>
        </div>
      </div>
    );
  }

  if (importing) {
    return (
      <PartsUploader
        manual={manual}
        expectedParts={expectedParts}
        uploading={uploading}
        uploadPercent={uploadPercent}
        scanning={scanning}
        blocked={blockManual}
        logs={logs}
        setLogs={setLogs}
        isLogsVisible={isLogsVisible}
        onToggleLogs={() => setIsLogsVisible(!isLogsVisible)}
        onUpload={uploadParts}
        onScan={scanFolderForParts}
        onDiscard={cancelImport}
        onBack={onLeave}
      />
    );
  }

  return (
    <ManifestLoader
      busy={loadingManifest}
      blocked={blockManual}
      disabled={migrationOff}
      onLoad={loadManifest}
      onBack={onLeave}
    />
  );
};

export default MigrationByHand;
