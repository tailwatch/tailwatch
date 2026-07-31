import { useEffect, useState } from "react";
import { getSystemSettings } from "./SystemSettingService/SystemSettingService";
import { Server, Database, HardDrive, Shield, Settings, Globe, FileText, XCircle } from "lucide-react";
import { convertKeysToSnakeCase, InfoCard, InfoRow, tabs } from './SystemSettingsComponents/SystemSettingsComponents'
import { SystemSettingSkeleton } from "../../../Components/Skeleton/LoaderSkeleton";

const SystemSettings = () => {
  const [systemData, setSystemData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('server');

  const handleGetSystemSettings = async () => {
    try {
      setLoading(true);
      const res = await getSystemSettings({});
      if (res?.data?.data?.data) {
        const convertedData = convertKeysToSnakeCase(res.data.data.data);
        setSystemData(convertedData);
      }
    } catch (error) {
      console.error("Error fetching system settings:", error);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {    
    handleGetSystemSettings();
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