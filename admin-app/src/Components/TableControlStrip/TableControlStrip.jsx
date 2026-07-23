import React, { useEffect, useRef, useState } from "react";
import { Search, History, ChevronDown } from "lucide-react";
import DeleteButton from "../Buttons/DeleteButton";
import ShowCanvasButton from "../ShowCanvasButton/ShowCanvas";
import PopUpModal from "../Modal/PopUpModal";
import LogFilters from "../LogFilters/LogFilters";
import { Info } from "lucide-react";
import { X } from "lucide-react";
import { TriangleAlert } from "lucide-react";
import { Check } from "lucide-react";

const TableControlStrip = ({
  deleteButton = true,
  disbaled,
  showInterceptorAction = false,
  showIntegrityDropdown = false,
  onintegrityStatusChange,
  file_status,
  filterTitle = false,
  selectedFilesCount,
  handleDeleteAll,
  handleBulkDelete,
  handleAddToBlacklist,
  handleAddToWhitelist,  
  handleRemoveWhiteList,
  isDeleting,
  searchTerm,
  setSearchTerm,
  hasEligibleFiles,
  showDeleteButton = true,
  showControls = true,
  showCanvas = false,
  featureId,
  CheckStatus,
  isDisabled,
  showBulkActions = false,
  showBruteForceActions = false,
  userBasedFilter = false,
  handleBulkPause,
  handleBulkResume,
  handleBulkRun,
  handleUnblock,
  handleDelete,
  showPriorityDropdown = false,
  showStatusDropdown = false,
  priority,
  status,
  onPriorityChange,
  onStatusChange,
  // Plugin bulk actions props
  showPluginBulkActions = false,
  handleBulkActivate,
  handleBulkDeactivate,
  // Add Button props (for Updates)
  showAddButton = false,
  onAddButtonClick,
  onButtonDisable,
  addButtonLabel = '',
  showPluginModalNames = [],

  // View History props (for Updates)
  showViewHistory = false,
  onViewHistoryClick,

  // Filter dropdown props (e.g. for User-based brute-force filters)
  showFilterDropdown = false,
  filterLabel = 'Filter',
  selectedFilter,
  filterOptions = [],
  handleFilterSelect,

  // Dynamic log filters (Filter button + modal, driven by wptw_logs_filter_options)
  showLogFilter = false,
  logFilterOptions = {},
  logSelectedFilters = {},
  onApplyLogFilters,
  onResetLogFilters,
}) => {
  const [pluginModalAction, setPluginModalAction] = useState(null);
  const [isPluginModalOpen, setIsPluginModalOpen] = useState(false);
  const [selectedPlugins, setSelectedPlugins] = useState([]);
  const [filterDropdownOpen, setFilterDropdownOpen] = useState(false);
  const filterDropdownRef = useRef(null);

  // Close embedded Filter dropdown when clicking outside.
  useEffect(() => {
    if (!filterDropdownOpen) return;
    const handleClickOutside = (event) => {
      if (filterDropdownRef.current && !filterDropdownRef.current.contains(event.target)) {
        setFilterDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [filterDropdownOpen]);

  const sortPluginsByDependency = (plugins) => {
    if (!plugins || plugins.length === 0) return [];

    const pluginMap = new Map();
    plugins.forEach((plugin) => {
      pluginMap.set(plugin.plugin, plugin);
    });

    const dependencyGraph = new Map();
    const inDegree = new Map();

    plugins.forEach((plugin) => {
      dependencyGraph.set(plugin.plugin, []);
      inDegree.set(plugin.plugin, 0);
    });

    // Build graph: if A requires B, then B -> A (B must come before A)
    plugins.forEach((plugin) => {
      const requires = plugin.dependency_info?.requires || [];

      requires.forEach((requiredPlugin) => {
        const requiredPluginId = requiredPlugin.plugin;

        if (pluginMap.has(requiredPluginId)) {
          if (!dependencyGraph.has(requiredPluginId)) {
            dependencyGraph.set(requiredPluginId, []);
            inDegree.set(requiredPluginId, 0);
          }

          dependencyGraph.get(requiredPluginId).push(plugin.plugin);
          inDegree.set(plugin.plugin, (inDegree.get(plugin.plugin) || 0) + 1);
        }
      });
    });

    // Topological sort using Kahn's algorithm
    const sorted = [];
    const queue = [];

    inDegree.forEach((degree, pluginId) => {
      if (degree === 0) {
        queue.push(pluginId);
      }
    });

    while (queue.length > 0) {
      const current = queue.shift();
      sorted.push(pluginMap.get(current));

      const dependents = dependencyGraph.get(current) || [];
      dependents.forEach((dependent) => {
        inDegree.set(dependent, inDegree.get(dependent) - 1);
        if (inDegree.get(dependent) === 0) {
          queue.push(dependent);
        }
      });
    }

    // Handle circular dependencies
    if (sorted.length < plugins.length) {
      plugins.forEach((plugin) => {
        if (!sorted.find((p) => p.plugin === plugin.plugin)) {
          sorted.push(plugin);
        }
      });
    }

    return sorted;
  };

  // ✅ SORT PLUGINS BY DEPENDENCY
  const sortedPlugins = React.useMemo(() => {
    const sorted = sortPluginsByDependency(showPluginModalNames);
    // For deactivation and deletion, reverse the order (children first, parents last)
    if (pluginModalAction === "deactivate" || pluginModalAction === "delete") {
      return [...sorted].reverse();
    }
    return sorted;
  }, [showPluginModalNames, pluginModalAction]);

  // Determine allowed and blocked plugins based on action
  const allowedPlugins = sortedPlugins.filter((plugin) => {
    if (pluginModalAction === "activate")
      return !plugin.is_active && plugin.can_activate;
    if (pluginModalAction === "deactivate")
      return plugin.is_active && plugin.can_deactivate;
    if (pluginModalAction === "delete") return plugin.can_delete;
    return false;
  });

  const blockedPlugins = sortedPlugins.filter((plugin) => {
    if (pluginModalAction === "activate")
      return plugin.is_active || !plugin.can_activate;
    if (pluginModalAction === "deactivate")
      return !plugin.is_active || !plugin.can_deactivate;
    if (pluginModalAction === "delete") return !plugin.can_delete;
    return false;
  });

  // Preselect allowed plugins when modal opens
  // ✅ CORRECT (No Infinite Loop)
  useEffect(() => {
    if (isPluginModalOpen) {
      const preselected = sortedPlugins.filter((plugin) => {
        if (pluginModalAction === "activate")
          return !plugin.is_active && plugin.can_activate;
        if (pluginModalAction === "deactivate")
          return plugin.is_active && plugin.can_deactivate;
        if (pluginModalAction === "delete") return plugin.can_delete;
        return false;
      });
      setSelectedPlugins(preselected.map((p) => p.slug));
    }
  }, [isPluginModalOpen, pluginModalAction]);
  const handleBulkAction = (event) => {
    const action = event.target.value;
    if (action === "delete") {
      handleBulkDelete();
    } else if (action === "pause") {
      handleBulkPause();
    } else if (action === "resume") {
      handleBulkResume();
    } else if (action === "run") {
      handleBulkRun();
    }
    event.target.value = "";
  };

  const handleBruteForceAction = (event) => {
    const action = event.target.value;
    if (action === "addToBlacklist") {
      handleAddToBlacklist();
    } else if (action === "addToWhitelist") {
      handleAddToWhitelist();
    } else if (action === "unblock") {
      handleUnblock();
    } else if (action === "delete") {
      handleDelete();
    } else if (action === "removeFromWhitelist") {
      handleRemoveWhiteList();
    }
    event.target.value = "";
  };

  const handleInterceptorAction = (event) => {
    const action = event.target.value;
    if (action === "unblock") {
      handleUnblock();
    } else if (action === "delete") {
      handleDelete();
    }
    event.target.value = "";
  };

  const handlePluginBulkAction = (event) => {
    const action = event.target.value;
    if (!action) return;

    // Open modal instead of executing action immediately
    setPluginModalAction(action);
    setIsPluginModalOpen(true);

    // Reset select
    event.target.value = "";
  };

  const handleConfirmPluginAction = (allowedPlugins) => {
    // Extract only the plugin slugs from allowed plugins (ALREADY SORTED)
    const allowedPluginSlugs = allowedPlugins.map((p) => p.plugin);

    if (pluginModalAction === "activate") {
      handleBulkActivate(allowedPluginSlugs);
    }

    if (pluginModalAction === "deactivate") {
      handleBulkDeactivate(allowedPluginSlugs);
    }

    if (pluginModalAction === "delete") {
      handleBulkDelete(allowedPluginSlugs);
    }

    setIsPluginModalOpen(false);
    setPluginModalAction(null);
  };

  const handleCancelPluginAction = () => {
    setIsPluginModalOpen(false);
    setPluginModalAction(null);
  };

  return (
    <div className="bg-gray-300 p-2 sm:p-3 rounded-xl shadow-sm border border-gray-300 mb-3 sm:mb-4">
      <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
        {/* Left Side Controls */}
        <div className="flex flex-wrap items-center gap-2 sm:gap-3 md:gap-4">
          {showControls ? (
            <>
              {deleteButton && (
                <div className="flex items-center">
                  <DeleteButton
                    defaultText={isDeleting ? "Deleting..." : "Delete All"}
                    onClick={handleDeleteAll}
                    isDisabled={disbaled}
                    className={`text-xs sm:text-sm rounded-lg text-white flex items-center transition-all whitespace-nowrap ${isDeleting ? "bg-gray-400 cursor-not-allowed" : " hover:from-red-600 hover:to-red-700 shadow-sm hover:shadow"}`}
                  />
                </div>
              )}
              {showDeleteButton ? (
                <DeleteButton
                  defaultText={
                    isDeleting
                      ? "Deleting..."
                      : `Delete (${selectedFilesCount})`
                  }
                  onClick={handleBulkDelete}
                  isDisabled={selectedFilesCount === 0 || isDeleting}
                  className={`text-xs sm:text-sm rounded-lg text-white flex items-center transition-all whitespace-nowrap ${selectedFilesCount === 0 || isDeleting ? "bg-gray-400 cursor-not-allowed" : " hover:from-red-600 hover:to-red-700 shadow-sm hover:shadow"}`}
                />
              ) : null}
            </>
          ) : null}

          {showLogFilter && (
            <LogFilters
              filterOptions={logFilterOptions}
              selectedFilters={logSelectedFilters}
              onApply={onApplyLogFilters}
              onReset={onResetLogFilters}
            />
          )}

          {/* Priority Dropdown */}
          {showPriorityDropdown && (
            <select
              value={priority || ""}
              onChange={(e) => onPriorityChange(e.target.value || null)}
              className="!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980] !outline-none"
            >
              <option value="">All Priorities</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </select>
          )}

          {/* Status Dropdown */}
          {showStatusDropdown && (
            <select
              value={status || ""}
              onChange={(e) => onStatusChange(e.target.value || null)}
              className="!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980] !outline-none"
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="dismissed">Dismissed</option>
              <option value="resolved">Resolved</option>
            </select>
          )}

          {showBulkActions && (
            <select
              onChange={handleBulkAction}
              disabled={selectedFilesCount === 0}
              className={`!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 ${selectedFilesCount === 0 ? "!opacity-50 !cursor-not-allowed" : ""}`}
            >
              <option value="">Bulk Actions</option>
              <option value="delete">Delete</option>
              <option value="pause">Pause</option>
              <option value="resume">Resume</option>
              <option value="run">Run</option>
            </select>
          )}

          {showInterceptorAction && (
            <select
              onChange={handleInterceptorAction}
              disabled={selectedFilesCount === 0}
              className={`!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 ${selectedFilesCount === 0 ? "!opacity-50 !cursor-not-allowed" : ""}`}
            >
              <option value="">Interceptor Actions</option>
              <option value="unblock">Unblock</option>
              <option value="delete">Delete</option>
            </select>
          )}

          {filterTitle && (
            <p className="!text-sm sm:!text-base md:!text-lg !whitespace-nowrap">
              Filter:
            </p>
          )}

          {showIntegrityDropdown && (
            <select
              value={file_status || ""}
              onChange={(e) => onintegrityStatusChange(e.target.value || null)}
              className="!border !border-gray-400 !rounded !px-2 !py-1 !text-xs sm:!text-sm !bg-white focus:!border-[#2271b1] focus:!ring-1 focus:!ring-[#2271b1] !outline-none"
            >
              <option value="all">All Files</option>
              <option value="new">New Files</option>
              <option value="modified">Modified Files</option>
              <option value="deleted">Deleted Files</option>
            </select>
          )}

          {showBruteForceActions && (
            <select
              onChange={handleBruteForceAction}
              disabled={selectedFilesCount === 0}
              className={`!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 ${selectedFilesCount === 0 ? "!opacity-50 !cursor-not-allowed" : ""}`}
            >
              <option value="">Bulk Actions</option>
              {userBasedFilter ? (
                <option value="removeFromWhitelist">
                  Remove from WhiteLIst
                </option>
              ) : (
                <option value="addToBlacklist">Add To BlackList</option>
              )}
              <option value="addToWhitelist">Add To WhiteLIst</option>
              <option value="unblock">Unblock</option>
              <option value="delete">Delete</option>
            </select>
          )}

          {showPluginBulkActions && (
            <select
              onChange={handlePluginBulkAction}
              disabled={selectedFilesCount === 0}
              className={`!bg-white !py-1.5 sm:!py-2 !rounded-lg !shadow-sm !border !border-gray-200 !text-xs sm:!text-sm !font-medium !text-gray-700 ${selectedFilesCount === 0 ? "!opacity-50 !cursor-not-allowed" : ""}`}
            >
              <option value="">Bulk Actions</option>
              <option value="activate">Activate</option>
              <option value="deactivate">Deactivate</option>
              <option value="delete">Delete</option>
            </select>
          )}

          {showViewHistory && (
            <button
              onClick={onViewHistoryClick}
              className="bg-[#007980] hover:bg-[#006670] text-xs sm:text-sm text-white px-3 sm:px-4 py-1.5 sm:py-2 rounded-lg transition-colors duration-200 flex items-center gap-2 whitespace-nowrap"
            >
              <History className="w-4 h-4" />
              <span>View History</span>
            </button>
          )}
          {showAddButton && (
            <button
              onClick={onButtonDisable ? undefined : onAddButtonClick}
              disabled={onButtonDisable}
              className={`text-xs sm:text-sm text-white px-3 sm:px-4 py-1.5 sm:py-2 rounded-lg transition-colors duration-200 flex items-center gap-2 whitespace-nowrap ${onButtonDisable ? "bg-gray-400 cursor-not-allowed opacity-60" : "bg-[#007980] hover:bg-[#006670]"}`}
            >
              <span>{addButtonLabel}</span>
            </button>
          )}
          {showCanvas && (
            <ShowCanvasButton
              featureId={featureId}
              onClose={CheckStatus}
              tableStrip={true}
              isDisabled={isDisabled}
            />
          )}

          {showFilterDropdown && (
            <div className="!relative" ref={filterDropdownRef}>
              <button
                type="button"
                onClick={() => setFilterDropdownOpen((prev) => !prev)}
                className="!inline-flex !items-center !gap-2 !px-3 !py-1.5 sm:!py-2 !bg-white !border !border-gray-200 !rounded-lg !shadow-sm !text-xs sm:!text-sm !font-medium !text-gray-700 hover:!bg-gray-50 focus:!outline-none focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980]"
              >
                <span className="!whitespace-nowrap">
                  {filterLabel}: {selectedFilter}
                </span>
                <ChevronDown className={`!w-4 !h-4 !text-gray-400 !transition-transform !duration-200 ${filterDropdownOpen ? '!rotate-180' : ''}`} />
              </button>
              {filterDropdownOpen && (
                <div className="!absolute !left-0 !mt-1 !w-48 !bg-white !border !border-gray-200 !rounded-md !shadow-lg !z-20">
                  <div className="!py-1">
                    {filterOptions.map((option) => (
                      <button
                        key={option}
                        type="button"
                        onClick={() => {
                          handleFilterSelect && handleFilterSelect(option);
                          setFilterDropdownOpen(false);
                        }}
                        className={`!w-full !text-left !px-4 !py-2 !text-sm hover:!bg-[#07C07E1A] ${
                          selectedFilter === option
                            ? '!bg-[#07C07E1A] !text-[#007980] !font-medium'
                            : '!text-gray-700'
                        }`}
                      >
                        {option}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Right Side Controls */}
        <div className="flex flex-wrap items-center gap-2 sm:gap-3">
          {isPluginModalOpen &&
            (() => {
              const getBlockedReason = (plugin) => {
                if (pluginModalAction === "activate") {
                  if (plugin.is_active) {
                    return "Plugin is already active";
                  }
                  return (
                    plugin.activation_blocked_reason ||
                    "Cannot activate this plugin"
                  );
                }

                if (pluginModalAction === "deactivate") {
                  if (!plugin.is_active) {
                    return "Plugin is already inactive";
                  }
                  return (
                    plugin.deactivation_blocked_reason ||
                    "Cannot deactivate this plugin"
                  );
                }

                if (pluginModalAction === "delete") {
                  if (plugin.is_active) {
                    return "Cannot delete active plugin. Please deactivate it first.";
                  }
                  return (
                    plugin.deletion_blocked_reason ||
                    "Cannot delete this plugin"
                  );
                }

                return "Action not allowed";
              };

              return (
                <PopUpModal
                  title={`Confirm ${pluginModalAction}`}
                  onClose={handleCancelPluginAction}
                  onSave={() => handleConfirmPluginAction(allowedPlugins)}
                  saveButtonText={`Confirm (${allowedPlugins.length})`}
                  cancelButtonText="Cancel"
                  disabled={allowedPlugins.length === 0}
                  width="w-full max-w-2xl"
                  height="max-h-[90vh]"
                  showExpandIcon={true}
                >
                  <div className="space-y-6">
                    {allowedPlugins.length > 0 && (
                      <div>
                        <h4 className="text-sm font-semibold text-green-700 mb-3 flex items-center gap-1">
                          <Info width={15} /> Plugins Ready for{" "}
                          {pluginModalAction} ({allowedPlugins.length})
                        </h4>

                        <ul className="space-y-2 bg-green-50 border border-green-200 p-4 rounded">
                          {allowedPlugins.map((plugin, index) => (
                            <li
                              key={index}
                              className="rounded font-medium text-gray-800"
                            >
                              <div className="flex items-start gap-2">
                                <Check
                                  className="text-green-600 flex-shrink-0 mt-0.5"
                                  width={15}
                                />
                                <div className="flex-1">
                                  <div className="flex items-center gap-2">
                                    <span>{plugin.name}</span>
                                  </div>

                                  {plugin.dependency_info?.is_required_by && (
                                    <div className="mt-1 text-xs text-blue-700 bg-blue-50 px-2 py-1 rounded border border-blue-200">
                                      Parent Plugin: Required by{" "}
                                      {plugin.dependency_info.required_by_active
                                        ?.length || 0}{" "}
                                      plugin(s)
                                    </div>
                                  )}

                                  {plugin.dependency_info?.requires?.length >
                                    0 && (
                                      <div className="mt-1 text-xs text-orange-700 bg-orange-50 px-2 py-1 rounded border border-orange-200">
                                        Depends on:{" "}
                                        {plugin.dependency_info.requires
                                          .map((req) => req.name)
                                          .join(", ")}
                                      </div>
                                    )}
                                </div>
                              </div>
                            </li>
                          ))}
                        </ul>

                        <div className="mt-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-800">
                          <strong> Note:</strong> Plugins are listed in
                          dependency order.
                          {pluginModalAction === "activate" &&
                            " Parent plugins will be processed first."}
                          {pluginModalAction === "deactivate" &&
                            " Child plugins will be processed first, then parents."}
                          {pluginModalAction === "delete" &&
                            " Child plugins will be deleted first, then parents."}
                        </div>
                      </div>
                    )}

                    {blockedPlugins.length > 0 && (
                      <div>
                        <h4 className="text-sm font-semibold text-red-700 mb-3 flex items-center gap-1">
                          <TriangleAlert className="text-red-600" width={15} />
                          Cannot be {pluginModalAction}d (
                          {blockedPlugins.length})
                        </h4>

                        <ul className="space-y-2 bg-red-50 border border-red-200 p-3 rounded">
                          {blockedPlugins.map((plugin, index) => (
                            <li
                              key={index}
                              className="font-medium text-gray-800"
                            >
                              <X
                                className="text-red-600 inline-block mr-2"
                                width={15}
                              />
                              {plugin.name}
                              <div className="text-xs text-red-700 mt-1 pl-6">
                                {getBlockedReason(plugin)}
                              </div>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </div>
                </PopUpModal>
              );
            })()}

          <div className="relative !w-full sm:!w-auto">
            <input
              type="text"
              placeholder="Search..."
              disabled={isDisabled}
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className={`!w-full sm:!w-48 md:!w-56 lg:!w-64 !pl-9 sm:!pl-10 !pr-3 sm:!pr-4 !py-1.5 !bg-white !border !border-gray-200 !rounded-lg focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980] !shadow-sm !outline-none !text-sm ${isDisabled ? "!opacity-50 !cursor-not-allowed !bg-gray-100" : ""
                }`}
            />
            <div className="absolute inset-y-0 left-0 !pl-3 flex items-center pointer-events-none">
              <Search className="!text-[#007980] !w-4 !h-4 sm:!w-5 sm:!h-5" />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default TableControlStrip;
