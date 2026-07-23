import Header from "../../../../Components/Header/Header";
import { Archive, Download, Plus, FileCode2, Database, FileText } from 'lucide-react';
import { Tooltip as ReactTooltip } from 'react-tooltip';

const COMING_SOON_TOOLTIP_ID = 'backup-inner-coming-soon';

const placeholderItems = [
  { id: 'files', icon: FileCode2, title: 'Files Backup', type: 'ZIP', description: 'Download the archive of your site files.', actionText: 'Download' },
  { id: 'database', icon: Database, title: 'Database Backup', type: 'ZIP', description: 'Download the archive of your database.', actionText: 'Download' },
  { id: 'xml', icon: FileText, title: 'Migration XML', type: 'XML', description: 'Generate a migration configuration file.', actionText: 'Generate XML' },
];

const InnerFiles = () => {
  return (
    <div>
      <Header title="Backup Files" showBackIcon={true} />
      <div className="mx-auto p-6">
        <div className="mb-6">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-xl font-semibold text-gray-900">Available Downloads</h2>
              <p className="text-sm text-gray-600 mt-1">Download your backup files and migration configuration</p>
            </div>
          </div>
        </div>

        <div className="space-y-4">
          {placeholderItems.map((item) => (
            <div key={item.id} className="bg-white border border-gray-300 rounded-lg opacity-90">
              <div className="p-6">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-4">
                    <div className="w-12 h-12 rounded-lg bg-gray-100 flex items-center justify-center shadow-sm">
                      <item.icon className="w-6 h-6 text-gray-400" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center space-x-3">
                        <h3 className="text-lg font-semibold text-gray-700">{item.title}</h3>
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                          {item.type}
                        </span>
                      </div>
                      <p className="text-sm text-gray-500 mt-1">{item.description}</p>
                    </div>
                  </div>
                  <div className="flex items-center space-x-3">
                    <span data-tooltip-id={COMING_SOON_TOOLTIP_ID} data-tooltip-content="Coming Soon" data-tooltip-place="left" className="cursor-not-allowed inline-block">
                      <button
                        type="button"
                        disabled
                        className="inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-400 cursor-not-allowed"
                      >
                        <Download className="w-4 h-4 mr-2" />
                        {item.actionText}
                      </button>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <div className="mt-8 text-center">
          <div className="bg-white border-2 border-dashed border-gray-300 rounded-lg p-8">
            <Archive className="w-12 h-12 text-gray-400 mx-auto mb-4" />
            <h3 className="text-lg font-medium text-gray-900 mb-2">Backup Files</h3>
            <p className="text-gray-600 mb-4">Generation and downloads for backup files are temporarily unavailable.</p>
            <span data-tooltip-id={COMING_SOON_TOOLTIP_ID} data-tooltip-content="Coming Soon" data-tooltip-place="top" className="cursor-not-allowed inline-block">
              <button
                type="button"
                disabled
                className="inline-flex items-center px-6 py-3 rounded-lg text-sm font-medium bg-gray-100 text-gray-400 cursor-not-allowed"
              >
                <Plus className="w-4 h-4 mr-2" />
                Generate Backup Files
              </button>
            </span>
          </div>
        </div>
      </div>

      <ReactTooltip id={COMING_SOON_TOOLTIP_ID} className="!bg-black !text-white !py-2 !px-4 !rounded" />
    </div>
  );
};

export default InnerFiles;
