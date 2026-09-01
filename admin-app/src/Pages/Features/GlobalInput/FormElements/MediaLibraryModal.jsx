import React, { useState, useEffect, useRef, useCallback } from 'react';
import { toast } from 'react-toastify';
import { X, Upload, Search, Check, Trash2, Image as ImageIcon, FileText, Film, Music, ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { getWpMedia, uploadWpMedia, deleteWpMedia } from './MediaServices';

const PER_PAGE = 24;
const MAX_UPLOAD_BYTES = 2 * 1024 * 1024; // 2MB — backend limit

const formatBytes = (bytes) => {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

const FileTypeIcon = ({ type, className }) => {
  switch (type) {
    case 'video': return <Film className={className} />;
    case 'audio': return <Music className={className} />;
    case 'image': return <ImageIcon className={className} />;
    default: return <FileText className={className} />;
  }
};

const MediaLibraryModal = ({ open, onClose, onSelect, post_id }) => {
  const [activeTab, setActiveTab] = useState('library'); // 'library' | 'upload'
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, total_pages: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [selected, setSelected] = useState(null);

  // Upload state
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadFile, setUploadFile] = useState(null);
  const [dragActive, setDragActive] = useState(false);
  const fileInputRef = useRef(null);

  // Reset on open
  useEffect(() => {
    if (open) {
      setActiveTab('library');
      setSelected(null);
      setSearchInput('');
      setSearch('');
      setPage(1);
      setError(null);
    }
  }, [open]);

  // Debounce search
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [searchInput]);

  const fetchMedia = useCallback(async () => {
    setLoading(true);
    setError(null);
    const query = {};
    if (search) query.s = search;
    query.orderby = 'date';
    query.order = 'DESC';

    const res = await getWpMedia({ page, limit: PER_PAGE, query });
    if (res.success) {
      setItems(res.items);
      setPagination(res.pagination || { page, total: res.items.length });
    } else {
      setError(res.error || 'Failed to load media');
      setItems([]);
    }
    setLoading(false);
  }, [page, search]);

  useEffect(() => {
    if (open && activeTab === 'library') fetchMedia();
  }, [open, activeTab, fetchMedia]);

  // ESC to close
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  const handleSelectClick = () => {
    if (!selected) return;
    // Pass back in the same shape FileInputComponent / useGlobalInput expects.
    onSelect({
      id: selected.id,
      url: selected.url,
      name: selected.title || selected.filename || '',
      type: selected.mime || selected.type,
      size: selected.filesizeInBytes || selected.fileLength,
    });
    onClose();
  };

  const handleFiles = async (files) => {
    if (!files || files.length === 0) return;
    const file = files[0];
    if (file.size > MAX_UPLOAD_BYTES) {
      toast.error('File is too large. Maximum allowed size is 2 MB.');
      return;
    }
    setUploadFile(file);
    setUploading(true);
    setUploadProgress(0);

    const res = await uploadWpMedia({
      file,
      post_id,
      onProgress: (p) => setUploadProgress(p),
    });

    setUploading(false);
    setUploadFile(null);
    setUploadProgress(0);

    if (res.success) {
      toast.success('File uploaded successfully');
      // Auto-switch to library and pre-select the new attachment.
      setActiveTab('library');
      setPage(1);
      setSearch('');
      setSearchInput('');
      // Pre-select the newly uploaded item once the library reloads.
      setSelected(res.attachment);
    } else {
      const code = res.code;
      if (code === 413) toast.error('File is too large (server limit).');
      else if (code === 403) toast.error('You do not have permission to upload here.');
      else if (code === 400) toast.error(res.error || 'Unsupported file or no file selected.');
      else toast.error(res.error || 'Upload failed');
    }
  };

  const handleDelete = async (item, e) => {
    e.stopPropagation();
    if (!window.confirm(`Permanently delete "${item.title || item.filename}"?`)) return;
    const res = await deleteWpMedia({ attachment_id: item.id, force_delete: true });
    if (res.success) {
      toast.success(res.message || 'Attachment deleted');
      // Refresh + clear selection if it was this item
      if (selected?.id === item.id) setSelected(null);
      fetchMedia();
    } else {
      toast.error(res.error || 'Delete failed');
    }
  };

  const onDragOver = (e) => { e.preventDefault(); setDragActive(true); };
  const onDragLeave = (e) => { e.preventDefault(); setDragActive(false); };
  const onDrop = (e) => {
    e.preventDefault();
    setDragActive(false);
    handleFiles(e.dataTransfer?.files);
  };

  return (
    <div className="!fixed !inset-0 !z-[1100] !flex !items-center !justify-center !p-4 !bg-black !bg-opacity-60" onClick={onClose}>
      <div
        className="!relative !flex !flex-col !w-full !max-w-5xl !max-h-[90vh] !bg-white !rounded-xl !shadow-2xl !overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="!flex !items-center !justify-between !px-5 !py-4 !border-b !border-gray-200">
          <h2 className="!text-lg !font-semibold !text-gray-900">Media Library</h2>
          <button
            type="button"
            onClick={onClose}
            className="!p-1 !rounded !text-gray-400 hover:!text-gray-700 hover:!bg-gray-100 !transition-colors"
            aria-label="Close"
          >
            <X className="!w-5 !h-5" />
          </button>
        </div>

        {/* Tabs + Search row */}
        <div className="!flex !items-center !justify-between !gap-3 !px-5 !pt-4 !pb-3 !border-b !border-gray-200">
          <div className="!flex !gap-1 !rounded-lg !bg-gray-100 !p-1">
            <button
              type="button"
              onClick={() => setActiveTab('library')}
              className={`!inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-sm !font-medium !rounded-md !transition-colors ${
                activeTab === 'library' ? '!bg-white !text-[#007980] !shadow-sm' : '!text-gray-600 hover:!text-gray-900'
              }`}
            >
              <ImageIcon className="!w-4 !h-4" /> Library
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('upload')}
              className={`!inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-sm !font-medium !rounded-md !transition-colors ${
                activeTab === 'upload' ? '!bg-white !text-[#007980] !shadow-sm' : '!text-gray-600 hover:!text-gray-900'
              }`}
            >
              <Upload className="!w-4 !h-4" /> Upload Files
            </button>
          </div>

          {activeTab === 'library' && (
            <div className="!relative !w-72 !max-w-full">
              <Search className="!absolute !left-2.5 !top-1/2 !-translate-y-1/2 !w-4 !h-4 !text-gray-400" />
              <input
                type="text"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Search media..."
                className="!w-full !pl-9 !pr-3 !py-1.5 !text-sm !border !border-gray-300 !rounded-md !bg-white focus:!outline-none focus:!ring-2 focus:!ring-[#007980]/40 focus:!border-[#007980]"
              />
            </div>
          )}
        </div>

        {/* Body */}
        <div className="!flex-1 !overflow-y-auto !p-5 !bg-gray-50">
          {activeTab === 'library' && (
            <>
              {loading && (
                <div className="!flex !items-center !justify-center !h-64 !text-gray-500">
                  <Loader2 className="!w-6 !h-6 !animate-spin !mr-2" />
                  <span className="!text-sm">Loading media...</span>
                </div>
              )}

              {!loading && error && (
                <div className="!flex !flex-col !items-center !justify-center !h-64 !text-red-600">
                  <p className="!text-sm !mb-2">{error}</p>
                  <button onClick={fetchMedia} className="!text-sm !text-[#007980] hover:!underline">Retry</button>
                </div>
              )}

              {!loading && !error && items.length === 0 && (
                <div className="!flex !flex-col !items-center !justify-center !h-64 !text-gray-500">
                  <ImageIcon className="!w-12 !h-12 !text-gray-300 !mb-3" />
                  <p className="!text-sm">{search ? 'No media found for your search.' : 'No media uploaded yet.'}</p>
                  <button
                    onClick={() => setActiveTab('upload')}
                    className="!mt-3 !inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-sm !font-medium !text-white !bg-[#007980] hover:!bg-[#006670] !rounded-md !transition-colors"
                  >
                    <Upload className="!w-4 !h-4" /> Upload your first file
                  </button>
                </div>
              )}

              {!loading && !error && items.length > 0 && (
                <>
                  <div className="!grid !grid-cols-2 sm:!grid-cols-3 md:!grid-cols-4 lg:!grid-cols-6 !gap-3">
                    {items.map((item) => {
                      const isSelected = selected?.id === item.id;
                      const isImage = (item.type === 'image') || (item.mime || '').startsWith('image/');
                      const thumbUrl = item?.sizes?.thumbnail?.url || item?.sizes?.medium?.url || item.url;
                      return (
                        <button
                          key={item.id}
                          type="button"
                          onClick={() => setSelected(item)}
                          className={`!group !relative !aspect-square !rounded-lg !overflow-hidden !border-2 !transition-all !bg-white ${
                            isSelected ? '!border-[#007980] !shadow-md !ring-2 !ring-[#007980]/30' : '!border-gray-200 hover:!border-gray-400'
                          }`}
                          title={item.title || item.filename || ''}
                        >
                          {isImage && thumbUrl ? (
                            <img
                              src={thumbUrl}
                              alt={item.title || ''}
                              className="!w-full !h-full !object-cover"
                              loading="lazy"
                            />
                          ) : (
                            <div className="!flex !flex-col !items-center !justify-center !w-full !h-full !text-gray-400 !p-2">
                              <FileTypeIcon type={item.type} className="!w-10 !h-10 !mb-1" />
                              <p className="!text-[10px] !font-medium !text-gray-600 !uppercase !truncate !max-w-full">{item.subtype || (item.mime || '').split('/')[1] || 'file'}</p>
                            </div>
                          )}

                          {/* Selected check overlay */}
                          {isSelected && (
                            <div className="!absolute !top-1.5 !left-1.5 !w-6 !h-6 !rounded-full !bg-[#007980] !flex !items-center !justify-center !shadow-md">
                              <Check className="!w-4 !h-4 !text-white" />
                            </div>
                          )}

                          {/* Delete icon on hover */}
                          <div
                            onClick={(e) => handleDelete(item, e)}
                            className="!absolute !top-1.5 !right-1.5 !w-6 !h-6 !rounded-full !bg-white/90 !text-red-600 !flex !items-center !justify-center !shadow-md !opacity-0 group-hover:!opacity-100 hover:!bg-red-50 !transition-opacity"
                            title="Delete attachment"
                          >
                            <Trash2 className="!w-3.5 !h-3.5" />
                          </div>

                          {/* Filename strip */}
                          <div className="!absolute !bottom-0 !inset-x-0 !bg-gradient-to-t !from-black/70 !to-transparent !px-1.5 !py-1 !text-left">
                            <p className="!text-[10px] !text-white !truncate">{item.title || item.filename}</p>
                          </div>
                        </button>
                      );
                    })}
                  </div>

                  {/* Pagination */}
                  {pagination.total_pages > 1 && (
                    <div className="!mt-5 !flex !items-center !justify-center !gap-3">
                      <button
                        type="button"
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                        disabled={!pagination.has_prev}
                        className="!inline-flex !items-center !gap-1 !px-3 !py-1.5 !text-sm !font-medium !border !border-gray-300 !rounded-md !bg-white !text-gray-700 hover:!bg-gray-50 disabled:!opacity-50 disabled:!cursor-not-allowed"
                      >
                        <ChevronLeft className="!w-4 !h-4" /> Prev
                      </button>
                      <span className="!text-sm !text-gray-600">
                        Page <span className="!font-semibold">{pagination.page || page}</span> of <span className="!font-semibold">{pagination.total_pages}</span>
                        <span className="!text-gray-400"> · {pagination.total} items</span>
                      </span>
                      <button
                        type="button"
                        onClick={() => setPage((p) => p + 1)}
                        disabled={!pagination.has_next}
                        className="!inline-flex !items-center !gap-1 !px-3 !py-1.5 !text-sm !font-medium !border !border-gray-300 !rounded-md !bg-white !text-gray-700 hover:!bg-gray-50 disabled:!opacity-50 disabled:!cursor-not-allowed"
                      >
                        Next <ChevronRight className="!w-4 !h-4" />
                      </button>
                    </div>
                  )}
                </>
              )}
            </>
          )}

          {activeTab === 'upload' && (
            <div className="!h-full !flex !items-center !justify-center">
              <div className="!w-full !max-w-md">
                <div
                  onDragOver={onDragOver}
                  onDragLeave={onDragLeave}
                  onDrop={onDrop}
                  onClick={() => !uploading && fileInputRef.current?.click()}
                  className={`!relative !rounded-xl !border-2 !border-dashed !p-8 !text-center !transition-colors !cursor-pointer ${
                    dragActive ? '!border-[#007980] !bg-[#007980]/5' : '!border-gray-300 !bg-white hover:!border-[#007980] hover:!bg-gray-50'
                  } ${uploading ? '!cursor-wait !opacity-80' : ''}`}
                >
                  <input
                    ref={fileInputRef}
                    type="file"
                    className="!hidden"
                    onChange={(e) => handleFiles(e.target.files)}
                    disabled={uploading}
                  />

                  <Upload className={`!w-12 !h-12 !mx-auto !mb-3 ${dragActive ? '!text-[#007980]' : '!text-gray-400'}`} />
                  <p className="!text-sm !font-semibold !text-gray-800 !mb-1">
                    {dragActive ? 'Drop file to upload' : 'Drop file here or click to browse'}
                  </p>
                  <p className="!text-xs !text-gray-500">Maximum file size: 2 MB</p>

                  {uploading && uploadFile && (
                    <div className="!mt-5 !text-left">
                      <div className="!flex !items-center !justify-between !text-xs !text-gray-600 !mb-1">
                        <span className="!truncate !max-w-[70%]">{uploadFile.name}</span>
                        <span className="!font-semibold">{uploadProgress}%</span>
                      </div>
                      <div className="!w-full !h-2 !bg-gray-200 !rounded-full !overflow-hidden">
                        <div
                          className="!h-full !bg-[#007980] !transition-all"
                          style={{ width: `${uploadProgress}%` }}
                        />
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="!flex !items-center !justify-between !gap-3 !px-5 !py-3 !border-t !border-gray-200 !bg-white">
          <div className="!text-sm !text-gray-600 !truncate !max-w-[60%]">
            {selected ? (
              <span><span className="!text-gray-400">Selected:</span> <span className="!font-medium !text-gray-800">{selected.title || selected.filename}</span> <span className="!text-gray-400">({formatBytes(selected.filesizeInBytes)})</span></span>
            ) : (
              <span className="!text-gray-400">No file selected</span>
            )}
          </div>
          <div className="!flex !items-center !gap-2">
            <button
              type="button"
              onClick={onClose}
              className="!px-4 !py-1.5 !text-sm !font-medium !text-gray-700 !bg-gray-100 hover:!bg-gray-200 !rounded-md !transition-colors"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleSelectClick}
              disabled={!selected}
              className="!inline-flex !items-center !gap-1.5 !px-4 !py-1.5 !text-sm !font-semibold !text-white !bg-[#007980] hover:!bg-[#006670] !rounded-md !transition-colors disabled:!bg-gray-300 disabled:!cursor-not-allowed"
            >
              <Check className="!w-4 !h-4" /> Use this file
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default MediaLibraryModal;
