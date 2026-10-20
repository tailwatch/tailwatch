import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import Header from '../../../../Components/Header/Header';
import ActionButton from '../../../../Components/Buttons/ActionButton.jsx';
import InfoBar from '../../../../Components/InfoBar/InfoBar.jsx';
import { Download, FileCode2, Database, FileJson } from 'lucide-react';
import {
  exportMigrationManifest,
  requestPartDownload,
} from '../../../Migration/MigrationServices/MigrationServices.jsx';

/**
 * One backup, and everything you can take away from it.
 *
 * A backup is not one file. It is a set of parts — the site's files in numbered archives,
 * the database in its own — and moving a site by hand means carrying every one of them.
 * So this screen is a packing bench: the list of parts with a download for each, and the
 * small migration file that describes them.
 *
 * ## Why the migration file matters more than it looks
 *
 * It is a packing list, not the backup: part names, sizes and digests. It holds no
 * password, no key, and grants nothing — knowing a part's name does not let anyone fetch
 * it — which is what makes it safe to email to whoever is standing at the other site.
 *
 * It is JSON and carries no credential at all: an XML parser is a liability for a file
 * that arrives from another site, and a secret in a file people email around is a secret
 * in the open.
 *
 * ## The other half
 *
 * The destination does the receiving, over in Migration: load this file there, upload the
 * parts it names, and the site reassembles and verifies them. Nothing is overwritten until
 * the restore is confirmed separately.
 */
const InnerFiles = () => {
  const { files } = useParams();

  // The route packs folder, id and tab into one segment.
  const folderName = decodeURIComponent((files || '').split('&')[0] || '');

  const [manifest, setManifest] = useState(null);
  const [loading, setLoading] = useState(true);
  const [problem, setProblem] = useState('');
  const [downloading, setDownloading] = useState('');
  // Per part: '' | 'handed' | 'failed'. "handed" is deliberately not "downloaded" — the
  // browser never tells a page whether a file reached the disk, and claiming otherwise
  // would have people trust a tick over their own downloads folder.
  const [sent, setSent] = useState({});
  const [runningAll, setRunningAll] = useState(false);
  const stopAll = useRef(false);

  const load = useCallback(async () => {
    if (!folderName) {
      setProblem('This backup could not be identified.');
      setLoading(false);
      return;
    }

    setLoading(true);
    const result = await exportMigrationManifest(folderName, { quiet: true });
    if (result?.manifest) {
      setManifest(result.manifest);
      setProblem('');
    } else {
      // The service already explained anything the operator can act on; this is the
      // screen's own fallback so it never renders an empty bench with no reason.
      setProblem(result?.message || 'This backup cannot be prepared for migration.');
    }
    setLoading(false);
  }, [folderName]);

  useEffect(() => {
    load();
  }, [load]);

  const readableSize = (bytes) => {
    const size = Number(bytes) || 0;
    if (size < 1024) return `${size} B`;
    if (size < 1048576) return `${(size / 1024).toFixed(1)} KB`;
    if (size < 1073741824) return `${(size / 1048576).toFixed(1)} MB`;
    return `${(size / 1073741824).toFixed(2)} GB`;
  };

  const handleDownloadPart = async (part) => {
    setDownloading(part.file);
    try {
      const url = await requestPartDownload(manifest.folder, part);
      if (!url) {
        setSent((current) => ({ ...current, [part.file]: 'failed' }));
        return false;
      }

      const link = document.createElement('a');
      link.href = url;
      link.download = part.file;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      // 'handed', not 'sent'. A click on a download link proves nothing: the browser may
      // drop it, block it, or save a truncated file, and none of that comes back to this
      // page.
      setSent((current) => ({ ...current, [part.file]: 'handed' }));
      return true;
    } finally {
      setDownloading('');
    }
  };

  /**
   * Hand the whole set to the browser, one at a time.
   *
   * Sequential rather than all at once for two reasons: a browser asked for twelve
   * downloads simultaneously will drop some, and each part needs its own grant minted
   * first — twelve requests in parallel is a burst the source site does not need to
   * absorb. The pause between them is what keeps the browser's "allow multiple
   * downloads" prompt to a single appearance instead of one per file.
   *
   * The migration file goes first, because a set of parts without it cannot be imported
   * and this is the moment the operator is paying attention.
   */
  const handleDownloadAll = async () => {
    stopAll.current = false;
    setRunningAll(true);
    handleDownloadManifest();

    try {
      for (const part of manifest.parts) {
        if (stopAll.current) {
          break;
        }
        await handleDownloadPart(part);
        // eslint-disable-next-line no-await-in-loop
        await new Promise((resolve) => setTimeout(resolve, 700));
      }
    } finally {
      setRunningAll(false);
      stopAll.current = false;
    }
  };

  // Written from what the site just handed back, so the file the operator carries is
  // byte-for-byte what the destination will be asked to verify against.



  const handleDownloadManifest = () => {
    const blob = new Blob([JSON.stringify(manifest, null, 2)], {
      type: 'application/json',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `tailwatch-migration-${manifest.folder}.json`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const fileParts = manifest ? manifest.parts.filter((p) => p.area === 'files') : [];
  const dbParts = manifest ? manifest.parts.filter((p) => p.area === 'database') : [];
  const totalBytes = manifest
    ? manifest.parts.reduce((total, part) => total + (Number(part.size) || 0), 0)
    : 0;
  const sentCount = manifest
    ? manifest.parts.filter((part) => sent[part.file] === 'handed').length
    : 0;


  const renderGroup = (title, Icon, parts) => {
    if (!parts.length) {
      return null;
    }

    return (
      <div className="bg-white border border-gray-200 rounded-lg">
        <div className="flex items-center gap-3 border-b border-gray-100 px-6 py-4">
          <div className="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center">
            <Icon className="w-5 h-5 text-gray-500" />
          </div>
          <div>
            <h3 className="text-base font-semibold text-gray-800">{title}</h3>
            <p className="text-xs text-gray-500">
              {parts.length} {parts.length === 1 ? 'part' : 'parts'} ·{' '}
              {readableSize(parts.reduce((total, part) => total + (Number(part.size) || 0), 0))}
            </p>
          </div>
        </div>

        <ul className="divide-y divide-gray-100">
          {parts.map((part) => (
            <li key={`${part.area}/${part.file}`} className="flex items-center gap-3 px-6 py-3">
              <span className="min-w-0 flex-1 truncate text-sm text-gray-800">{part.file}</span>
              <span className="text-xs text-gray-500 shrink-0">{readableSize(part.size)}</span>
              <span className="w-24 shrink-0 text-right text-xs">
                {sent[part.file] === 'handed' && (
                  <span className="text-gray-500">handed to browser</span>
                )}
                {sent[part.file] === 'failed' && (
                  <span className="text-red-700">failed</span>
                )}
              </span>
              <ActionButton
                onClick={() => handleDownloadPart(part)}
                isLoading={downloading === part.file}
                loadingText="Preparing..."
                defaultText={sent[part.file] ? 'Again' : 'Download'}
                isDisabled={runningAll || (downloading !== '' && downloading !== part.file)}
                className="px-3 py-1 shrink-0"
              />
            </li>
          ))}
        </ul>
      </div>
    );
  };

  return (
    <div>
      <Header title="Backup Files" showBackIcon={true} />

      <div className="mx-auto p-6">
        <div className="mb-6">
          <h2 className="text-xl font-semibold text-gray-900">Available downloads</h2>
          <p className="text-sm text-gray-600 mt-1">
            {folderName
              ? `Every part of ${folderName}, plus the migration file that describes them.`
              : 'Every part of this backup, plus the migration file that describes them.'}
          </p>
        </div>

        {loading ? (
          <p className="text-sm text-gray-600">Reading this backup...</p>
        ) : problem ? (
          <InfoBar type="warning" message={problem} />
        ) : (
          <>
            <div className="mb-4 rounded-lg border border-gray-200 bg-white p-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <h3 className="text-lg font-semibold text-gray-800">
                    {manifest.parts.length}{' '}
                    {manifest.parts.length === 1 ? 'file' : 'files'} ·{' '}
                    {readableSize(totalBytes)}
                  </h3>
                  <p className="mt-1 text-sm text-gray-600">
                    The other site needs every one of them, plus the migration file. A set
                    with a part missing is refused there rather than restored half-finished.
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {runningAll && (
                    <button
                      type="button"
                      onClick={() => {
                        stopAll.current = true;
                      }}
                      className="text-xs text-gray-600 hover:underline"
                    >
                      Stop
                    </button>
                  )}
                  <ActionButton
                    onClick={handleDownloadAll}
                    isLoading={runningAll}
                    loadingText={`Sending ${sentCount + 1} of ${manifest.parts.length}...`}
                    defaultText="Download everything"
                    isDisabled={manifest.parts.length === 0}
                    className="px-4 py-2"
                  />
                </div>
              </div>

              {sentCount > 0 && !runningAll && (
                <p className="mt-3 text-xs text-gray-600">
                  {sentCount} of {manifest.parts.length} handed to your browser. Check your
                  downloads folder — a page cannot see whether a file actually saved, so
                  this counts what was sent, not what arrived.
                </p>
              )}

              <div className="mt-4 flex items-center gap-3 border-t border-gray-100 pt-4">
                <FileJson className="h-5 w-5 shrink-0 text-gray-400" />
                <p className="flex-1 text-xs text-gray-600">
                  The migration file is a packing list — part names, sizes and digests. It
                  holds no passwords or keys and grants nothing, so it is safe to email.
                </p>
                <button
                  type="button"
                  onClick={handleDownloadManifest}
                  className="shrink-0 text-xs font-medium text-blue-600 hover:underline"
                >
                  Just the migration file
                </button>
              </div>
            </div>

            <div className="space-y-4">
              {renderGroup('Site files', FileCode2, fileParts)}
              {renderGroup('Database', Database, dbParts)}
            </div>

            {manifest.parts.length === 0 && (
              <InfoBar
                type="warning"
                message="This backup records no parts, so there is nothing to carry. Take a fresh backup and export that instead."
              />
            )}

          </>
        )}

        {!loading && !problem && (
          <p className="mt-6 flex items-center justify-center gap-2 text-xs text-gray-500">
            <Download className="w-3.5 h-3.5" />
            Download every part. A set with one missing is refused at the other end rather
            than restored half-finished.
          </p>
        )}
      </div>
    </div>
  );
};

export default InnerFiles;
