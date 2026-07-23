import React, { useState, useEffect, useRef, useCallback } from "react";
import axios from "axios";
import { Search, ChevronDown, X, Loader2 } from "lucide-react";
/* global wptw_ajax */

const fetchUsers = async ({ page, limit, search }) => {
    const formData = new FormData();
    formData.append("action", "wptw_global_ajax_handler");
    formData.append("action_type", "wptw_get_user_status");
    formData.append("nonce", wptw_ajax.nonce);
    formData.append("data", JSON.stringify({ page, limit, search }));
    const response = await axios.post(wptw_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
    });
    const d = response.data?.data;
    return {
        users: d?.data || [],
        totalPages: d?.pagination?.total_pages || 1,
    };
};

const UserSearchDropdown = ({ value, onChange, excludeIds = [], placeholder = "— Select a user —" }) => {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState("");
    const [users, setUsers] = useState([]);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [selected, setSelected] = useState(null);

    const dropdownRef = useRef(null);
    const triggerRef = useRef(null);
    const listRef = useRef(null);
    const searchTimeout = useRef(null);
    const LIMIT = 10;

    // Close on outside click
    useEffect(() => {
        const handler = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    const loadUsers = useCallback(async (pageNum, searchVal, replace = false) => {
        if (pageNum === 1) setLoading(true);
        else setLoadingMore(true);
        try {
            const { users: fetched, totalPages: tp } = await fetchUsers({ page: pageNum, limit: LIMIT, search: searchVal });
            const filtered = fetched.filter((u) => !excludeIds.includes(u.id));
            setUsers((prev) => replace ? filtered : [...prev, ...filtered]);
            setTotalPages(tp);
        } catch (e) {
            // silent
        } finally {
            setLoading(false);
            setLoadingMore(false);
        }
    }, [excludeIds.join(",")]);  // eslint-disable-line

    // Load on open + scroll into view
    useEffect(() => {
        if (open) {
            setPage(1);
            setSearch("");
            loadUsers(1, "", true);
            setTimeout(() => {
                triggerRef.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
            }, 50);
        }
    }, [open]);  // eslint-disable-line

    // Load on search (debounced)
    useEffect(() => {
        if (!open) return;
        clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            setPage(1);
            loadUsers(1, search, true);
        }, 300);
        return () => clearTimeout(searchTimeout.current);
    }, [search]);  // eslint-disable-line

    // Infinite scroll
    const handleScroll = () => {
        const el = listRef.current;
        if (!el || loadingMore || page >= totalPages) return;
        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 40) {
            const nextPage = page + 1;
            setPage(nextPage);
            loadUsers(nextPage, search, false);
        }
    };

    const handleSelect = (user) => {
        setSelected(user);
        onChange(String(user.id));
        setOpen(false);
    };

    const handleClear = (e) => {
        e.stopPropagation();
        setSelected(null);
        onChange("");
    };

    return (
        <div ref={dropdownRef} className="relative w-full">
            {/* Trigger */}
            <button
                ref={triggerRef}
                type="button"
                onClick={() => setOpen((p) => !p)}
                className="w-full flex items-center justify-between border border-gray-300 rounded-lg px-3 py-2 bg-white text-sm focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980] transition-colors"
            >
                {selected ? (
                    <span className="flex items-center gap-2 text-gray-800 truncate">
                        <span className="font-medium">{selected.username}</span>
                        <span className="text-gray-400 text-xs truncate">({selected.email})</span>
                    </span>
                ) : (
                    <span className="text-gray-400">{placeholder}</span>
                )}
                <span className="flex items-center gap-1 ml-2 shrink-0">
                    {selected && (
                        <X className="w-3.5 h-3.5 text-gray-400 hover:text-red-500" onClick={handleClear} />
                    )}
                    <ChevronDown className={`w-4 h-4 text-gray-500 transition-transform ${open ? "rotate-180" : ""}`} />
                </span>
            </button>

            {/* Dropdown panel */}
            {open && (
                <div className="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg">
                    {/* Search */}
                    <div className="p-2 !border-b !border-gray-100">
                        <div className="relative">
                            <Search className="!absolute !left-2.5 !top-1/2 -translate-y-1/2 !w-3.5 !h-3.5 !text-gray-400" />
                            <input
                                autoFocus
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search users..."
                                className="!w-full !pl-8 !pr-3 !py-1.5 !text-sm !border !border-gray-200 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"
                            />
                        </div>
                    </div>

                    {/* List */}
                    <ul
                        ref={listRef}
                        onScroll={handleScroll}
                        className="max-h-52 overflow-y-auto divide-y divide-gray-50"
                    >
                        {loading ? (
                            <li className="flex items-center justify-center py-6 gap-2 text-sm text-gray-500">
                                <Loader2 className="w-4 h-4 animate-spin" /> Loading...
                            </li>
                        ) : users.length === 0 ? (
                            <li className="py-6 text-center text-sm text-gray-400">No users found</li>
                        ) : (
                            users.map((u) => (
                                <li
                                    key={u.id}
                                    onClick={() => handleSelect(u)}
                                    className={`flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors ${value === String(u.id) ? "bg-teal-50" : ""}`}
                                >
                                    <div className="w-7 h-7 rounded-full bg-[#007980] text-white text-xs font-semibold flex items-center justify-center shrink-0">
                                        {u.username?.[0]?.toUpperCase() || "U"}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-gray-800 truncate">{u.username}</p>
                                        <p className="text-xs text-gray-400 truncate">{u.email}</p>
                                    </div>
                                    {value === String(u.id) && (
                                        <span className="text-xs text-[#007980] font-medium shrink-0">Selected</span>
                                    )}
                                </li>
                            ))
                        )}
                        {loadingMore && (
                            <li className="flex items-center justify-center py-3 gap-2 text-xs text-gray-400">
                                <Loader2 className="w-3.5 h-3.5 animate-spin" /> Loading more...
                            </li>
                        )}
                    </ul>

                    {!loading && users.length > 0 && page < totalPages && (
                        <div className="px-3 py-2 border-t border-gray-100 text-center">
                            <p className="text-xs text-gray-400">Scroll down to load more</p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default UserSearchDropdown;
