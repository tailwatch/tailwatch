import React, { useState, useMemo, useEffect } from 'react';
import Table from '../../../../Components/Table/Table';
import IconButton from '../../../../Components/Buttons/IconButton.jsx';
import { BiExport } from "react-icons/bi";
import { MdSettingsBackupRestore } from "react-icons/md";
import TableControlStrip from '../../../../Components/TableControlStrip/TableControlStrip.jsx';
import { alertService } from '../../../../Components/AlertService/AlertService.js';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField.jsx';
import ActionButton from '../../../../Components/Buttons/ActionButton.jsx';
import InfoBar from '../../../../Components/InfoBar/InfoBar.jsx';
import Callout from '../../../../Components/Callout/Callout.jsx';
import { Lock } from 'lucide-react';
import ShowCanvas from '../../../../Components/ShowCanvasButton/ShowCanvas.jsx';
import UseMigrationFeature from '../../../../Components/Hooks/MigrationFeature/UseMigrationFeature.jsx';
import { useFeatureContent } from '../../../../Components/Hooks/useFeatureContent/useFeatureContent';
import { fetchManualImportStatus } from '../../../Migration/MigrationServices/MigrationServices.jsx';
import { restorePoints, restoreWarning, scopeOfBackupType, SCOPE_DATABASE, SCOPE_FILES, SCOPE_FULL } from '../../../Migration/restoreImpact.js';
import { useNavigate } from 'react-router-dom';
import { startRestore, fetchRestoreStatus } from '../../RestoreServices/RestoreServices.jsx';

const BackupContent = ({ activeTab, currentPageData, handleDelete, deletingFileId, backupStatus, onRestoreFinished, checkBackupStatus }) => {
  const navigate = useNavigate();

  const [selectedFiles, setSelectedFiles] = useState([]);
  const [isDeleting, setIsDeleting] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  // The folder a restore is currently running for, or '' when none is.
  const [restoreRunning, setRestoreRunning] = useState('');
  const [startingRestore, setStartingRestore] = useState('');
  // Whether this install offers restoring only half of a complete backup. Answered by the
  // status poll rather than guessed here, so the choice is offered or withheld before
  // anything is pressed instead of after a refusal.
  const [partialAllowed, setPartialAllowed] = useState(false);
  // Whether this site may be restored onto at all, in the pair every other module uses.
  // Both start null so the button is not disabled on first paint, before the site has
  // answered.
  const [featureEnable, setFeatureEnable] = useState(null);
  // Restoring and migrating are two switches, and this screen has a button for each. One
  // flag would grey out Restore when migration is off, and tell the operator to enable
  // the wrong setting.
  const [migrationEnable, setMigrationEnable] = useState(null);
  const migrationOff = migrationEnable === false;
  // Restoring and exporting both belong to the Restore & Migration feature, so the way to
  // change either is the same door the Features screen uses.
  const { migrationFeature, refreshMigrationFeature } = UseMigrationFeature();
  const [parentEnable, setParentEnable] = useState(null);
  const restoreOff = featureEnable === false || parentEnable === false;
  // The feature card off and the Allow Restoring box unticked are two different fixes. A
  // settings panel cannot be opened on a switched-off feature, so offering one there would
  // give a button that does nothing.
  const featureSwitchedOff = parentEnable === false;
  const { handleToggleChange } = useFeatureContent();
  const [switchingOn, setSwitchingOn] = useState(false);

  // Both buttons answer to this one feature, so a change to it re-asks for both.
  const refreshRestoreSwitches = async () => {
    await refreshMigrationFeature();
    const [status, manual] = await Promise.all([fetchRestoreStatus(), fetchManualImportStatus()]);
    if (status) {
      setFeatureEnable(status.featureEnable);
      setParentEnable(status.parentEnable);
    }
    if (manual) {
      setMigrationEnable(manual.featureEnable !== false && manual.parentEnable !== false);
    }
  };

  /**
   * Closing the settings panel opened from here.
   *
   * Both halves, because this panel is the same panel the Backup screen's own Change
   * Settings button opens and a setting changed in it is no less changed for having been
   * reached from the table. Re-reading only this screen's switches would leave the feature
   * card, the schedule strip and the action buttons in the module above showing what they
   * knew before the panel was opened.
   */
  const afterSettingsClosed = async () => {
    await refreshRestoreSwitches();
    if (typeof checkBackupStatus === 'function') {
      await checkBackupStatus();
    }
  };

  const switchOnRestoreAndMigration = async () => {
    let featureId = migrationFeature?.featureId;
    if (!featureId) {
      featureId = (await refreshMigrationFeature())?.featureId;
    }
    if (!featureId) {
      alertService.info('The feature list is still loading. Try again in a moment.', 'One moment');
      return;
    }

    setSwitchingOn(true);
    try {
      // The same refetch the settings panel does on close. Switching the feature on from
      // here changes exactly what closing that panel changes, so it cannot be the one path
      // that refreshes this table's two switches and leaves the module above them stale.
      await handleToggleChange(featureId, '0', afterSettingsClosed);
    } catch {
      // The toggle has already said why it failed.
    } finally {
      setSwitchingOn(false);
    }
  };

  // A restore outlives the page: it runs on cron, and the site is unreachable for
  // part of it, so the operator will often come back to this tab rather than sit on
  // it. Adopt whatever is already in flight instead of showing an idle table.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const status = await fetchRestoreStatus();
      if (cancelled || !status) return;
      setFeatureEnable(status.featureEnable);
      setParentEnable(status.parentEnable);
      setPartialAllowed(status.partialAllowed);
      if (status.phase && !['idle', 'done', 'failed'].includes(status.phase)) {
        setRestoreRunning(status.folderDate || ' ');
      }
    })();
    return () => { cancelled = true; };
  }, []);

  // The export button answers to the migration switch, so this screen has to know about
  // it. The status route stays reachable when the feature is off, which is what makes it
  // usable as the question.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const status = await fetchManualImportStatus();
      if (cancelled || !status) return;
      setMigrationEnable(status.featureEnable !== false && status.parentEnable !== false);
    })();
    return () => { cancelled = true; };
  }, []);

  const handleRestore = async (file) => {
    // The choice is only real for a backup that HOLDS both halves. A files-only or
    // database-only backup restores what it has and nothing else, so offering to narrow
    // it further would be offering an option that does nothing.
    const isComplete = file.backupType === 'Complete Backup';
    const offerScope = isComplete && partialAllowed;

    // Named the way the list names it, so the question is plainly about the row clicked.
    const named = [file.creation_time, file.backupType].filter(Boolean).join(' · ');

    const { confirmed, choice } = await alertService.confirmImpact({
      title: 'Restore this backup?',
      subject: { primary: named || file.folder_name, secondary: named ? file.folder_name : '' },
      choiceLabel: 'What to restore',
      choices: offerScope
        ? [
            {
              value: SCOPE_FULL,
              label: 'Everything',
              note: 'Files and database, as they were when the backup was taken.',
            },
            {
              value: SCOPE_DATABASE,
              label: 'Database only',
              note: 'Content, users and settings go back. Your files stay, so they may not match.',
            },
            {
              value: SCOPE_FILES,
              label: 'Files only',
              note: 'Plugins, themes and other files go back. Content and settings stay as they are.',
            },
          ]
        : [],
      defaultChoice: SCOPE_FULL,
      // With a choice every line comes, each shown while its scope is picked. Without one,
      // only the lines true for what this backup holds.
      points: offerScope ? restorePoints() : restorePoints({ scope: scopeOfBackupType(file.backupType) }),
      warning: restoreWarning(),
      confirmButtonText: 'Restore',
      cancelButtonText: 'Cancel',
    });
    if (!confirmed) {
      return;
    }
    const scope = offerScope ? choice : SCOPE_FULL;

    setStartingRestore(file.folder_name);
    try {
      const { started, warnings, featureDisabled } = await startRestore(file.folder_name, scope);
      if (started) {
        // Its own screen, not a panel above this table. What follows replaces the site,
        // and the list it was launched from can honour none of its remaining buttons
        // while it runs.
        navigate(`/dashboard/restore/${encodeURIComponent(file.folder_name)}`);
      } else if (featureDisabled) {
        // Switched off since this page loaded — another tab, or someone else. Show the
        // screen's disabled state now rather than leaving the button looking pressable
        // until the next poll.
        setFeatureEnable(false);
      }
    } finally {
      setStartingRestore('');
    }
  };

  // Create a map of backup statuses by backup_id for quick lookup
  const backupStatusMap = useMemo(() => {
    if (!backupStatus || !Array.isArray(backupStatus)) return {};
    return backupStatus.reduce((acc, status) => {
      acc[status.backup_id] = status;
      return acc;
    }, {});
  }, [backupStatus]);

  // Merge currentPageData with backupStatus
  const enrichedData = useMemo(() => {
    return (currentPageData || []).map(file => {
      const statusInfo = backupStatusMap[file.id];
      if (statusInfo) {
        return { ...file, progress_percentage: statusInfo.progress_percentage, status: statusInfo.status,
          completed_files: statusInfo.completed_files, total_files: statusInfo.total_files, downloaded_size: statusInfo.downloaded_size, total_size: statusInfo.total_size,
          is_completed: statusInfo.is_completed, hasProgressData: true
        };
      }
      return {
        ...file,
        hasProgressData: false
      };
    });
  }, [currentPageData, backupStatusMap]);

  const filteredData = searchTerm ? enrichedData.filter(file => file.folder_name.toLowerCase().includes(searchTerm.toLowerCase())) : enrichedData;

  // Determine eligible files for selection
  const eligibleFiles = filteredData.filter(file => file.status !== 'in-progress' && file.status !== 'pause' && file.status !== 'in_progress' );
  const allSelected = eligibleFiles.length > 0 && eligibleFiles.every(file => selectedFiles.includes(file.id));

  const handleSelectFile = (fileId) => {
    if (selectedFiles.includes(fileId)) {
      setSelectedFiles(selectedFiles.filter(id => id !== fileId));
    } else {
      setSelectedFiles([...selectedFiles, fileId]);
    }
  };

  const handleSelectAll = () => {
    const eligibleIds = eligibleFiles.map(f => f.id);
    if (allSelected) {
      setSelectedFiles(selectedFiles.filter(id => !eligibleIds.includes(id)));
    } else {
      setSelectedFiles([...selectedFiles, ...eligibleIds.filter(id => !selectedFiles.includes(id))]);
    }
  };

  const handleDelteAll = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
      return;
    }
    setIsDeleting(true);
    let deleteData;
    deleteData = { ids: [], is_delete: true };
    await handleDelete(deleteData);
    setSelectedFiles([]);
    setIsDeleting(false);
  };

  const handleBulkDelete = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
      return;
    }
    if (selectedFiles.length > 0) {
      setIsDeleting(true);
      let deleteData;
      const ids = selectedFiles;
      deleteData = { ids: ids, is_delete: false };
      await handleDelete(deleteData);
      setSelectedFiles([]);
      setIsDeleting(false);
    }
  };

  // Export opens the backup's own screen rather than handing over the migration file
  // on its own. That file is a packing list — it cannot rebuild anything without the
  // parts it names, and a button that gives you half of what you need sends people to
  // the other site to find out. One door, both halves behind it.
  const handleExport = (file) => {
    if (file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress') {
      return;
    }

    navigate(`/dashboard/backup/backupfiles/${encodeURIComponent(file.folder_name)}&${encodeURIComponent(file.id)}&${encodeURIComponent(activeTab.toLowerCase())}`, {
      state: { backupType: file.backupType, fileId: file.id }
    });
  };

  const formatStatus = (status) => {
    if (!status) return 'Unknown';    
    return status.replace('_', '-');
  };

  const getColumns = () => {
    const columns = [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0 || !!restoreRunning}/>, 'Folder Name', 'Folder Size', 'Creation Time', 'Backup Type'];
        
    if (backupStatus && Array.isArray(backupStatus) && backupStatus.length > 0) {
      columns.push('Progress');
    }
    
    columns.push('Status', 'Action');
    return columns;
  };

  const renderRow = (file, index) => {
    const isInProgress = file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress';
    const progressPercentage = file.progress_percentage ?? 0;
    
    return (
      <>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          <CheckboxField checked={selectedFiles.includes(file.id)} onChange={() => handleSelectFile(file.id)} disabled={isInProgress || !!restoreRunning}/>        
        </td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.folder_name}</td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          {file.downloaded_size && file.total_size 
            ? `${file.downloaded_size} / ${file.total_size}` 
            : file.folder_size}
        </td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.creation_time}</td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.backupType}</td>
        
        {/* Progress Column - Only render if backupStatus exists */}
        {backupStatus && Array.isArray(backupStatus) && backupStatus.length > 0 && (
          <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
            {file.hasProgressData ? (
              <div>
                <div className="flex items-center space-x-2">
                  <div className="w-24 bg-gray-200 rounded-full h-2.5">
                    <div className={`h-2.5 rounded-full transition-all duration-300 ${ progressPercentage === 100 ? 'bg-green-500' : 'bg-blue-500'}`} style={{ width: `${progressPercentage}%` }} ></div>
                  </div>
                  <span className="text-xs font-medium">{progressPercentage}%</span>
                </div>
                {file.completed_files !== undefined && file.total_files !== undefined && (
                  <div className="text-xs text-gray-500 mt-1">
                    {file.completed_files}/{file.total_files} files
                  </div>
                )}
                <div className="text-xs text-blue-600 mt-1 italic">
                  Migration backup in progress
                </div>
              </div>
            ) : (
              <span className="text-gray-400 text-lg">----</span>
            )}
          </td>
        )}

        {/* Status Column */}
        <td className="px-3 py-4 whitespace-nowrap text-sm">
          <span className={`px-2 py-1 rounded text-xs font-medium ${ file.status === 'completed' ? 'bg-green-100 text-green-800' : file.status === 'in_progress' || file.status === 'in-progress' ? 'bg-blue-100 text-blue-800' : file.status === 'pause' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' }`}>
            {formatStatus(file.status)}
          </span>
        </td>
        
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          <div className="flex items-center space-x-2">
            <IconButton icon={BiExport} text="Export" tooltip={migrationOff ? 'Migration is switched off in Features' : 'Get the migration file and parts for this backup'} disabled={isInProgress || !!deletingFileId || !!restoreRunning || migrationOff} onClick={() => handleExport(file)} />
            <IconButton icon={MdSettingsBackupRestore} text={startingRestore === file.folder_name ? 'Starting...' : 'Restore'} tooltip={restoreOff ? 'Restoring is switched off in Features' : ''} disabled={isInProgress || !!deletingFileId || !!restoreRunning || !!startingRestore || restoreOff} onClick={() => handleRestore(file)} />
          </div>
        </td>
      </>
    );
  };

  return (
    <div>
      {/* A setting that is off, said the way a setting should be: a white card with the
          brand down its edge, not a full-width amber alarm stacked under the page's
          schedule banner. */}
      {/* The bottom margin is the caller's here: Callout carries none of its own, and a
          card with no air under it reads as part of the control strip below it. */}
      {restoreOff && !restoreRunning && (
        <div className="mb-4 mt-3">
          <Callout
            icon={Lock}
            title="Restoring is switched off"
            action={
              featureSwitchedOff ? (
                <ActionButton
                  onClick={switchOnRestoreAndMigration}
                  isInProgress={switchingOn}
                  isDisabled={switchingOn}
                  defaultText="Switch on Restore & Migration"
                  inProgressText="Switching on..."
                  className="whitespace-nowrap px-3 py-1 text-white rounded-md"
                />
              ) : (
                <ShowCanvas
                  featureId={migrationFeature?.featureId}
                  onClose={afterSettingsClosed}
                  tableStrip={true}
                />
              )
            }
          >
            {featureSwitchedOff
              ? "Backups are still being taken. Putting one back replaces every file and the whole database, so Restore & Migration stays off until you switch it on."
              : "Restore & Migration is on, but its Allow Restoring box is unticked — open its settings to tick it."}
          </Callout>
        </div>
      )}

      {/* A restore that is already running when this list loads. It is watched on its own
          screen, but the row controls below still have to know, so a backup cannot be
          deleted out from under one. */}
      {restoreRunning && (
        <InfoBar
          type="progress"
          message={
            <>
              A restore of <span className="font-semibold">{restoreRunning}</span> is running.{" "}
              <button
                className="underline"
                onClick={() => navigate(`/dashboard/restore/${encodeURIComponent(restoreRunning)}`)}
              >
                Watch it
              </button>
            </>
          }
        />
      )}
      {/* Deleting a backup while a restore is reading from it would pull the archives
          out from under it, so the delete controls are held closed for the duration. */}
      <TableControlStrip handleDeleteAll={handleDelteAll} disbaled={!!restoreRunning || filteredData.length === 0 || filteredData.some(file => file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress')}
        selectedFilesCount={selectedFiles.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={eligibleFiles.length > 0 && !restoreRunning} />
      <Table columns={getColumns()} data={filteredData} renderRow={renderRow} noDataMessage="No data available" />
    </div>
  );
};

export default BackupContent;