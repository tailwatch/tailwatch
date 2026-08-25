import { useEffect, useState } from "react";
import { getSystemSettings, getPhpSettings, updatePhpSettings, removePhpSettings } from "./SystemSettingService/SystemSettingService";
import { Server, Database, HardDrive, Shield, Settings, Globe, FileText, XCircle, Edit, Save, Info, RotateCcw } from "lucide-react";
import { convertKeysToSnakeCase, InfoCard, InfoRow, tabs } from './SystemSettingsComponents/SystemSettingsComponents'
import { toast } from "react-toastify";
import { SystemSettingSkeleton } from "../../../Components/Skeleton/LoaderSkeleton";

const SystemSettings = () => {
  const [systemData, setSystemData] = useState(null);
  const [phpSettings, setPhpSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('php');
  const [isPhpEditMode, setIsPhpEditMode] = useState(false);
  const [editablePhpSettings, setEditablePhpSettings] = useState({});
  const [recomendedSettings, setRecomendedSettings] = useState({});
  const [isSaving, setIsSaving] = useState(false);
  const [isRemoving, setIsRemoving] = useState(false);

  const handleGetSystemSettings = async () => {
    try {
      setLoading(true);
      const res = await getSystemSettings({});
      if (res?.data?.data?.data) {
        const convertedData = convertKeysToSnakeCase(res.data.data.data);
        setSystemData(convertedData);
      }
    } catch (error) {
      // Non-fatal: the error view below offers a retry.
    } finally {
      setLoading(false);
    }
  };

  const handleGetPhpSettings = async () => {
    try {
      const res = await getPhpSettings();
      if (res?.data?.data) {
        setPhpSettings(res.data.data.all_current_values);
        setEditablePhpSettings(res.data.data.all_current_values);
        setRecomendedSettings(res.data.data.recommended_settings);
      }
    } catch (error) {
      // Non-fatal.
    }
  };

  const handlePhpEditToggle = () => {
    setIsPhpEditMode(!isPhpEditMode);
    if (!isPhpEditMode) {
      setEditablePhpSettings({ ...phpSettings });
    }
  };

  const handlePhpSettingChange = (key, value) => {
    setEditablePhpSettings(prev => ({
      ...prev,
      [key]: value
    }));
  };

  const handleMemoryFieldChange = (key, value) => {
    let cleanValue = value.replace(/[^0-9M]/g, '');
    let numericPart = cleanValue.replace(/M/g, '');

    if (numericPart === '0') {
      return;
    }

    if (numericPart === '') {
      setEditablePhpSettings(prev => ({
        ...prev,
        [key]: ''
      }));
      return;
    }

    const finalValue = numericPart + 'M';

    setEditablePhpSettings(prev => ({
      ...prev,
      [key]: finalValue
    }));
  };

  const getNumericValueForDisplay = (value) => {
    if (!value) return '';
    return value.replace(/M/g, '');
  };

  const handlePhpSave = async () => {
    try {
      const memoryFields = ['memory_limit', 'upload_max_filesize', 'post_max_size'];
      const hasEmptyMemoryFields = memoryFields.some(field => !editablePhpSettings[field] || editablePhpSettings[field] === '');

      if (hasEmptyMemoryFields) {
        toast.error("Please fill all memory fields (Memory Limit, Upload Max Filesize, Post Max Size)");
        return;
      }

      setIsSaving(true);
      const res = await updatePhpSettings({ editablePhpSettings });

      if (res?.data?.data?.code === 200) {
        await handleGetPhpSettings();
        toast.success(res?.data?.data?.message || "PHP Settings updated successfully");
        setIsPhpEditMode(false);
      } else {
        toast.error(res?.data?.data?.message || "Something went wrong, please try again");
      }
    } catch (error) {
      toast.error("Failed to save settings, please try again");
    } finally {
      setIsSaving(false);
    }
  };

  const handlePhpRemove = async () => {
    // eslint-disable-next-line no-alert
    if (!window.confirm("Reset PHP settings? This removes the overrides Tailwatch applied and reverts to your server defaults.")) {
      return;
    }

    try {
      setIsRemoving(true);
      const res = await removePhpSettings();
      const code = res?.data?.data?.code;

      if (code === 200) {
        await handleGetPhpSettings();
        setIsPhpEditMode(false);
        toast.success(res?.data?.data?.message || "PHP Settings reset to server defaults");
      } else if (code === 404) {
        toast.info(res?.data?.data?.message || "No saved overrides to reset");
      } else {
        toast.error(res?.data?.data?.message || "Something went wrong, please try again");
      }
    } catch (error) {
      toast.error("Failed to reset settings, please try again");
    } finally {
      setIsRemoving(false);
    }
  };

  useEffect(() => {
    handleGetSystemSettings();
    handleGetPhpSettings();
  }, []);


  if (loading) {
    return (
      <SystemSettingSkeleton/>
    );
  }

  if (!systemData) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-center">
          <XCircle className="w-16 h-16 text-red-500 mx-auto mb-4" />
          <h2 className="text-xl font-semibold text-gray-900 mb-2">Failed to Load System Settings</h2>
          <p className="text-gray-600 mb-4">Unable to retrieve system information.</p>
          <button
            onClick={handleGetSystemSettings}
            className="px-4 py-2 bg-[#007980] text-white rounded-lg hover:bg-blue-700 transition-colors"
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="">
      <div className="mx-auto px-2 py-8">
        <div className="mb-8">
          <nav className="flex space-x-8 overflow-x-auto">
            {tabs.map((tab) => (
              <button key={tab.id} onClick={() => setActiveTab(tab.id)} className={`flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg transition-colors whitespace-nowrap ${activeTab === tab.id ? 'bg-[#07C07E1A] text-[#007980]' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-100'}`} >
                {tab.icon}
                {tab.label}
              </button>
            ))}
          </nav>
        </div>

        {/* Server Tab */}
        {activeTab === 'server' && (
          <div className="gap-6">
            <InfoCard title="Server Information" icon={<Server className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="Server Name" value={systemData["server_information"]?.["server_name"]} />
              <InfoRow label="Server Software" value={systemData["server_information"]?.["server_software"]} />
              <InfoRow label="Operating System" value={systemData["server_information"]?.["operating_system"]} />
              <InfoRow label="Server Architecture" value={systemData["server_information"]?.["server_architecture"]} />
              <InfoRow label="Server Address" value={systemData["server_information"]?.["server_address"]} />
              <InfoRow label="Server Port" value={systemData["server_information"]?.["server_port"]} />
              <InfoRow label="Document Root" value={systemData["server_information"]?.["document_root"]} />
              <InfoRow label="Current User" value={systemData["server_information"]?.["current_user"]} />
              <InfoRow label="HTTPS Enabled" value={systemData["server_information"]?.["https_enabled"]} type="boolean" />
              <InfoRow label="User Agent" value={systemData["server_information"]?.["user_agent"]} />
              <InfoRow label="Server Load Average" value={systemData["server_information"]?.["server_load_average"]} />
            </InfoCard>
          </div>
        )}

        {/* Database Tab */}
        {activeTab === 'database' && (
          <div className="gap-6">
            <InfoCard title="Database Configuration" icon={<Database className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="Database Name" value={systemData["database_information"]?.["database_name"]} />
              <InfoRow label="Database Host" value={systemData["database_information"]?.["database_host"]} />
              <InfoRow label="Database Version" value={systemData["database_information"]?.["database_version"]} />
              <InfoRow label="Database Server Info" value={systemData["database_information"]?.["database_server_info"]} />
              <InfoRow label="Database Charset" value={systemData["database_information"]?.["database_charset"]} />
              <InfoRow label="Database Collate" value={systemData["database_information"]?.["database_collate"] || "Not Set"} />
              <InfoRow label="Table Prefix" value={systemData["database_information"]?.["table_prefix"]} />
              <InfoRow label="Table Count" value={systemData["database_information"]?.["table_count"]} />
              <InfoRow label="Total Size" value={systemData["database_information"]?.["total_size"]} />
            </InfoCard>
          </div>
        )}

        {/* PHP Configuration Tab */}
        {activeTab === 'php' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div className="bg-white rounded-lg shadow-md border border-gray-300 p-6">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-3">
                  <div className="p-2 bg-[#07C07E1A] rounded-lg">
                    <FileText className="w-5 h-5 text-[#007980]" />
                  </div>
                  <h3 className="text-lg font-semibold text-gray-900">PHP Configuration</h3>
                </div>
                <div className="flex items-center gap-2">
                  {isPhpEditMode ? (
                    <button
                      onClick={handlePhpSave}
                      disabled={isSaving}
                      className={`flex items-center gap-2 px-3 py-2 text-white rounded-lg transition-colors text-sm ${
                        isSaving
                          ? 'bg-gray-400 cursor-not-allowed'
                          : 'bg-[#007980] hover:bg-[#006570]'
                      }`}
                    >
                      {isSaving ? (
                        <div className="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
                      ) : (
                        <Save className="w-4 h-4" />
                      )}
                      {isSaving ? 'Saving...' : 'Save'}
                    </button>
                  ) : (
                    <button
                      onClick={handlePhpRemove}
                      disabled={isRemoving}
                      className={`flex items-center gap-2 px-3 py-2 rounded-lg transition-colors text-sm border ${
                        isRemoving
                          ? 'border-gray-200 text-gray-300 cursor-not-allowed'
                          : 'border-gray-300 text-gray-600 hover:text-[#007980] hover:border-[#007980]'
                      }`}
                    >
                      {isRemoving ? (
                        <div className="animate-spin rounded-full h-4 w-4 border-2 border-gray-400 border-t-transparent"></div>
                      ) : (
                        <RotateCcw className="w-4 h-4" />
                      )}
                      {isRemoving ? 'Resetting...' : 'Reset'}
                    </button>
                  )}
                  <button
                    onClick={handlePhpEditToggle}
                    disabled={isSaving || isRemoving}
                    className={`p-2 rounded-lg transition-colors ${
                      isSaving || isRemoving
                        ? 'text-gray-300 cursor-not-allowed'
                        : 'text-gray-500 hover:text-[#007980] hover:bg-gray-100'
                    }`}
                  >
                    <Edit className="w-4 h-4" />
                  </button>
                </div>
              </div>

              {isPhpEditMode ? (
                <div className="space-y-4">
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Memory Limit</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.memory_limit}</span>
                    </div>
                    <div className="relative">
                      <input
                        type="number"
                        min="1"
                        max="10000"
                        value={getNumericValueForDisplay(editablePhpSettings?.memory_limit)}
                        onChange={(e) => handleMemoryFieldChange('memory_limit', e.target.value)}
                        disabled={isSaving}
                        className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 pr-6 ${
                          isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                        }`}
                      />
                      <span className="absolute right-2 top-1/2 transform -translate-y-1/2 text-sm text-gray-500 pointer-events-none">M</span>
                    </div>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max Execution Time</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_execution_time}</span>
                    </div>
                    <input
                      type="text"
                      value={editablePhpSettings?.max_execution_time || ''}
                      onChange={(e) => handlePhpSettingChange('max_execution_time', e.target.value)}
                      disabled={isSaving}
                      className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 ${
                        isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                      }`}
                    />
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max Input Time</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_input_time}</span>
                    </div>
                    <input
                      type="text"
                      value={editablePhpSettings?.max_input_time || ''}
                      onChange={(e) => handlePhpSettingChange('max_input_time', e.target.value)}
                      disabled={isSaving}
                      className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 ${
                        isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                      }`}
                    />
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max File Uploads</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_file_uploads}</span>
                    </div>
                    <input
                      type="text"
                      value={editablePhpSettings?.max_file_uploads || ''}
                      onChange={(e) => handlePhpSettingChange('max_file_uploads', e.target.value)}
                      disabled={isSaving}
                      className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 ${
                        isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                      }`}
                    />
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Upload Max Filesize</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.upload_max_filesize}</span>
                    </div>
                    <div className="relative">
                      <input
                        type="number"
                        min="1"
                        value={getNumericValueForDisplay(editablePhpSettings?.upload_max_filesize)}
                        onChange={(e) => handleMemoryFieldChange('upload_max_filesize', e.target.value)}
                        disabled={isSaving}
                        className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 pr-6 ${
                          isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                        }`}
                      />
                      <span className="absolute right-2 top-1/2 transform -translate-y-1/2 text-sm text-gray-500 pointer-events-none">M</span>
                    </div>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100 last:border-b-0">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Post Max Size</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.post_max_size}</span>
                    </div>
                    <div className="relative">
                      <input
                        type="number"
                        min="1"
                        value={getNumericValueForDisplay(editablePhpSettings?.post_max_size)}
                        onChange={(e) => handleMemoryFieldChange('post_max_size', e.target.value)}
                        disabled={isSaving}
                        className={`text-sm text-gray-900 font-mono border rounded px-2 py-1 w-24 pr-6 ${
                          isSaving ? 'border-gray-200 bg-gray-50 cursor-not-allowed' : 'border-gray-300'
                        }`}
                      />
                      <span className="absolute right-2 top-1/2 transform -translate-y-1/2 text-sm text-gray-500 pointer-events-none">M</span>
                    </div>
                  </div>
                </div>
              ) : (
                <div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Memory Limit</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.memory_limit}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.memory_limit || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max Execution Time</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_execution_time}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.max_execution_time || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max Input Time</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_input_time}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.max_input_time || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Max File Uploads</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.max_file_uploads}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.max_file_uploads || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Upload Max Filesize</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.upload_max_filesize}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.upload_max_filesize || 'N/A'}</span>
                  </div>
                  <div className="flex justify-between items-center py-2 border-b border-gray-100 last:border-b-0">
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-gray-600">Post Max Size</span>
                      <span className="text-xs text-gray-600">Recommended: {recomendedSettings?.post_max_size}</span>
                    </div>
                    <span className="text-sm text-gray-900 font-mono">{phpSettings?.post_max_size || 'N/A'}</span>
                  </div>
                </div>
              )}

              <div className="mt-5 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                <Info className="w-4 h-4 text-amber-600 mt-0.5 flex-shrink-0" />
                <p className="text-xs leading-relaxed text-amber-800">
                  <span className="font-semibold">Note:</span> Some PHP settings (such as upload and post sizes) are controlled by your hosting provider or server configuration and cannot be changed by a plugin at runtime. If a value does not update after saving, apply the recommended value through your php.ini, hosting control panel, or by contacting your hosting provider.
                </p>
              </div>
            </div>


            <InfoCard title="PHP Settings" icon={<Settings className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="PHP Version" value={systemData["php_configuration"]?.["php_version"]} />
              <InfoRow label="PHP SAPI" value={systemData["php_configuration"]?.["php_sapi"]} />
              <InfoRow label="Max Input Vars" value={systemData["php_configuration"]?.["max_input_vars"]} />
              <InfoRow label="Allow URL Fopen" value={systemData["php_configuration"]?.["allow_url_fopen"]} type="boolean" />
              <InfoRow label="Allow URL Include" value={systemData["php_configuration"]?.["allow_url_include"]} type="boolean" />
              <InfoRow label="Display Errors" value={systemData["php_configuration"]?.["display_errors"]} type="boolean" />
              <InfoRow label="Log Errors" value={systemData["php_configuration"]?.["log_errors"]} type="boolean" />
              <InfoRow label="Error Reporting" value={systemData["php_configuration"]?.["error_reporting"]} />
              <InfoRow label="Session Save Path" value={systemData["php_configuration"]?.["session_save_path"]} />
              <InfoRow label="Temp Directory" value={systemData["php_configuration"]?.["temp_directory"]} />
              <InfoRow label="Loaded Extensions" value={systemData["php_configuration"]?.["loaded_extensions_count"]} />
              <InfoRow label="Disabled Functions" value={systemData["php_configuration"]?.["disabled_functions_count"]} />
            </InfoCard>
          </div>
        )}

        {/* WordPress Tab */}
        {activeTab === 'wordpress' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <InfoCard title="WordPress Configuration" icon={<Globe className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="WordPress Version" value={systemData["wordpress_information"]?.["wordpress_version"]} />
              <InfoRow label="Site URL" value={systemData["wordpress_information"]?.["site_url"]} />
              <InfoRow label="Home URL" value={systemData["wordpress_information"]?.["home_url"]} />
              <InfoRow label="Admin URL" value={systemData["wordpress_information"]?.["admin_url"]} />
              <InfoRow label="Content URL" value={systemData["wordpress_information"]?.["content_url"]} />
              <InfoRow label="Uploads URL" value={systemData["wordpress_information"]?.["uploads_url"]} />
              <InfoRow label="Active Theme" value={systemData["wordpress_information"]?.["active_theme"]} />
              <InfoRow label="Multisite" value={systemData["wordpress_information"]?.["wordpress_multisite"]} type="boolean" />
            </InfoCard>

            <InfoCard title="WordPress Settings" icon={<Settings className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="Total Users" value={systemData["wordpress_information"]?.["total_users"]} />
              <InfoRow label="Total Plugins" value={systemData["wordpress_information"]?.["total_plugins"]} />
              <InfoRow label="Active Plugins" value={systemData["wordpress_information"]?.["active_plugins"]} />
              <InfoRow label="Wordpress Memory Limit" value={systemData["wordpress_information"]?.["wordpress_memory_limit"]} />
              <InfoRow label="Wordpress Max Memory Limit" value={systemData["wordpress_information"]?.["wordpress_max_memory_limit"]} />
              <InfoRow label="Cron Disabled" value={systemData["wordpress_information"]?.["wordpress_cron_disabled"]} />
              <InfoRow label="Debug Mode" value={systemData["wordpress_information"]?.["wordpress_debug"]} />
              <InfoRow label="Debug Display" value={systemData["wordpress_information"]?.["wordpress_debug_display"]} />
              <InfoRow label="Debug Log" value={systemData["wordpress_information"]?.["wordpress_debug_log"]} />
            </InfoCard>
          </div>
        )}

        {/* File System Tab */}
        {activeTab === 'filesystem' && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <InfoCard title="File System Status" icon={<HardDrive className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="WordPress Root Writable" value={systemData["file_system_information"]?.["wordpress_root_writable"]} type="boolean" />
              <InfoRow label="WP Content Writable" value={systemData["file_system_information"]?.["wp_content_writable"]} type="boolean" />
              <InfoRow label="Uploads Writable" value={systemData["file_system_information"]?.["uploads_writable"]} type="boolean" />
              <InfoRow label="Htaccess Exists" value={systemData["file_system_information"]?.["htaccess_exists"]} type="boolean" />
              <InfoRow label="Htaccess Writable" value={systemData["file_system_information"]?.["htaccess_writable"]} type="boolean" />
              <InfoRow label="WP Config Exists" value={systemData["file_system_information"]?.["wp_config_exists"]} type="boolean" />
              <InfoRow label="Temp Directory Writable" value={systemData["file_system_information"]?.["temp_directory_writable"]} type="boolean" />
            </InfoCard>

            <InfoCard title="Directory Size" icon={<FileText className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="Total Site Size" value={systemData["file_system_information"]?.["total_site_size"]} />
              <InfoRow label="WordPress Core Size" value={systemData["file_system_information"]?.["wordpress_core_size"]} />
              <InfoRow label="WP Admin Size" value={systemData["file_system_information"]?.["wp_admin_size"]} />
              <InfoRow label="WP Includes Size" value={systemData["file_system_information"]?.["wp_includes_size"]} />
              <InfoRow label="WP Content Size" value={systemData["file_system_information"]?.["wp_content_size"]} />
              <InfoRow label="Plugins Size" value={systemData["file_system_information"]?.["plugins_size"]} />
              <InfoRow label="Themes Size" value={systemData["file_system_information"]?.["themes_size"]} />
              <InfoRow label="Uploads Size" value={systemData["file_system_information"]?.["uploads_size"]} />
            </InfoCard>

            <InfoCard title="Directory Information" icon={<FileText className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="Temp Directory" value={systemData["file_system_information"]?.["temp_directory"]} />
              <InfoRow label="WP Directory Permissions" value={systemData["wordpress_information"]?.["wordpress_directory_permissions"]} />
              <InfoRow label="WP File Permissions" value={systemData["wordpress_information"]?.["wordpress_file_permissions"]} />
            </InfoCard>
          </div>
        )}

        {/* Security Tab */}
        {activeTab === 'security' && (
          <div className="gap-6">
            <InfoCard title="Security Settings" icon={<Shield className="w-5 h-5 text-[#007980]" />}>
              <InfoRow label="SSL Enabled" value={systemData["security_information"]?.["ssl_enabled"]} type="boolean" />
              <InfoRow label="Force SSL Admin" value={systemData["security_information"]?.["force_ssl_admin"]} type="boolean" />
              <InfoRow label="File Editing Disabled" value={systemData["security_information"]?.["file_editing_disabled"]} type="boolean" />
              <InfoRow label="Plugin Installation Disabled" value={systemData["security_information"]?.["plugin_installation_disabled"]} type="boolean" />
              <InfoRow label="Automatic Updates Disabled" value={systemData["security_information"]?.["automatic_updates_disabled"]} type="boolean" />
              <InfoRow label="Script Debug Enabled" value={systemData["security_information"]?.["script_debug_enabled"]} type="boolean" />
              <InfoRow label="WP Debug Enabled" value={systemData["security_information"]?.["wp_debug_enabled"]} type="boolean" />
              <InfoRow label="Htaccess Permissions" value={systemData["security_information"]?.["htaccess_permissions"]} />
              <InfoRow label="WP Config Permissions" value={systemData["security_information"]?.["wp_config_permissions"]} />
              <InfoRow label="WP Content Permissions" value={systemData["security_information"]?.["wp_content_permissions"]} />
            </InfoCard>
          </div>
        )}
      </div>
    </div>
  );
};

export default SystemSettings;
