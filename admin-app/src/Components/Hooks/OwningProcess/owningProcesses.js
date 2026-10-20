/**
 * The operations that own this site while they run.
 *
 * Most of what the plugin does can run alongside anything else — a backup, a link scan, a
 * database clean. Three things cannot: restoring, migrating, and putting quarantined files
 * back. While one of those is running the site is closed to visitors, its files and tables
 * are being replaced under the request, and starting anything else is at best refused and
 * at worst damaging.
 *
 * Deliberately narrow. A backup does NOT own the site — you can browse the dashboard while
 * one runs, and locking the UI for it would be an obstruction rather than a protection.
 *
 * The keys are the process types the backend's guard reports in `running_process`, so this
 * map and ProcessGuard::PROCESS_TYPE_LABELS have to keep naming the same things.
 *
 * Plain data with no imports, because both the hook that polls for these and the helper
 * that reads them out of a refusal need the same list, and neither should have to load the
 * other to get it.
 */
export const OWNING_PROCESSES = {
  restore: {
    route: '/dashboard/restore',
    label: 'A restore is running',
    detail:
      'This site is being replaced with the contents of a backup. It stays closed to visitors until that finishes.',
  },
  migration: {
    route: '/dashboard/migration',
    label: 'A migration is running',
    detail: 'A backup is being moved between two sites. Nothing here changes until it finishes.',
  },
  malware_restore: {
    route: '/dashboard/malwarescanner',
    label: 'A malware restore is running',
    detail: 'Quarantined files are being put back. Leave it to finish before changing anything else.',
  },
};

/** Raised when a refused action named one of the processes above. */
export const PROCESS_BLOCKED_EVENT = 'tailwatch:process-blocked';
