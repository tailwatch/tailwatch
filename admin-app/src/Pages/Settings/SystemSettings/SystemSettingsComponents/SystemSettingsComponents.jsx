
import { Server, Database, HardDrive, Shield, Globe, FileText,CheckCircle,XCircle,AlertTriangle,Info} from "lucide-react";

export const convertKeysToSnakeCase = (obj) => {
    if (obj === null || typeof obj !== 'object') {
        return obj;
    }

    if (Array.isArray(obj)) {
        return obj.map(convertKeysToSnakeCase);
    }

    const convertedObj = {};

    Object.keys(obj).forEach(key => {
        const snakeCaseKey = key.toLowerCase().replace(/\s+/g, '_');
        convertedObj[snakeCaseKey] = convertKeysToSnakeCase(obj[key]);
    });

    return convertedObj;
};

export   const StatusIndicator = ({ value, type = "boolean" }) => {
    let status = "unknown";
    let icon = <Info className="w-4 h-4" />;
    let colorClass = "text-gray-500 bg-gray-100";

    if (type === "boolean") {
      if (value === "Yes" || value === "Enabled" || value === true) {
        status = "success";
        icon = <CheckCircle className="w-4 h-4" />;
        colorClass = "text-green-600 bg-green-100";
      } else if (value === "No" || value === "Disabled" || value === false) {
        status = "error";
        icon = <XCircle className="w-4 h-4" />;
        colorClass = "text-red-600 bg-red-100";
      }
    } else if (type === "warning" && (value === "No" || value === "Disabled")) {
      status = "warning";
      icon = <AlertTriangle className="w-4 h-4" />;
      colorClass = "text-yellow-600 bg-yellow-100";
    }

    return (
      <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium ${colorClass}`}>
        {icon}
        {value}
      </span>
    );
  };

  export const InfoCard = ({ title, icon, children, className = "" }) => (
    <div className={`bg-white rounded-lg shadow-md border border-gray-300 p-6 ${className}`}>
      <div className="flex items-center gap-3 mb-4">
        <div className="p-2 bg-[#07C07E1A] rounded-lg">
          {icon}
        </div>
        <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
      </div>
      {children}
    </div>
  );

  export const InfoRow = ({ label, value, type = "text" }) => (
    <div className="flex justify-between items-center py-2 border-b border-gray-100 last:border-b-0">
      <span className="text-sm font-medium text-gray-600">{label}</span>
      <div className="text-sm text-gray-900">
        {type === "boolean" || type === "warning" ? (
          <StatusIndicator value={value} type={type} />
        ) : (
          <span className="font-mono">{value || "N/A"}</span>
        )}
      </div>
    </div>
  );

 export  const tabs = [    
     { id: 'php', label: 'PHP Config', icon: <FileText className="w-4 h-4" /> },
    { id: 'server', label: 'Server', icon: <Server className="w-4 h-4" /> },
    { id: 'database', label: 'Database', icon: <Database className="w-4 h-4" /> },
    { id: 'wordpress', label: 'WordPress', icon: <Globe className="w-4 h-4" /> },
    { id: 'filesystem', label: 'File System', icon: <HardDrive className="w-4 h-4" /> },
    { id: 'security', label: 'Security', icon: <Shield className="w-4 h-4" /> }
  ];