import React, { useState } from 'react';
import { Image as ImageIcon, FileText, Upload, X, Trash2 } from 'lucide-react';
import MediaLibraryModal from './MediaLibraryModal';

const isImageUrl = (url) => {
    if (!url) return false;
    const imageExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.svg'];
    const urlLower = url.toLowerCase();
    return imageExtensions.some(ext => urlLower.includes(ext));
};

const FileInputComponent = ({ id, label, description, selectedValue, handleFileChange }) => {
    const [imageLoading, setImageLoading] = useState(false);
    const [imageError, setImageError] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);

    const handleImageLoad = () => { setImageLoading(false); setImageError(false); };
    const handleImageError = () => { setImageLoading(false); setImageError(true); };
    const handleImageLoadStart = () => { setImageLoading(true); setImageError(false); };

    const handleMediaSelect = (attachment) => {
        // Mirror the shape useGlobalInput.handleFileChange expects
        // (it checks for `file.url && file.id`):
        handleFileChange({
            target: {
                files: [{
                    name: attachment.name,
                    url: attachment.url,
                    id: attachment.id,
                    type: attachment.type,
                    size: attachment.size,
                }],
            },
        });
    };

    // Clears the selection — passes an empty files list which causes
    // useGlobalInput.handleFileChange to reset value to "" and selected to false.
    const handleRemove = (e) => {
        e?.stopPropagation?.();
        setImageLoading(false);
        setImageError(false);
        handleFileChange({ target: { files: [] } });
    };

    const showPreview = selectedValue && isImageUrl(selectedValue);

    return (
        <div className="!p-4">
            <label htmlFor={id} className="form-label !text-lg !text-black !font-semibold !pr-[10px]">{label}</label>

            <div className="!flex !items-center !gap-3 !mt-1 !flex-wrap">
                <button
                    type="button"
                    onClick={() => setModalOpen(true)}
                    className="!inline-flex !items-center !gap-1.5 !bg-[#007980] hover:!bg-opacity-80 !text-white !py-2 !px-3 !rounded !text-sm !transition-colors"
                >
                    <Upload className="!w-4 !h-4" /> {selectedValue ? 'Replace File' : 'Choose File'}
                </button>
                {selectedValue && (
                    <button
                        type="button"
                        onClick={handleRemove}
                        className="!inline-flex !items-center !gap-1.5 !bg-white !border !border-red-200 !text-red-600 hover:!bg-red-50 hover:!border-red-300 !py-2 !px-3 !rounded !text-sm !transition-colors"
                    >
                        <Trash2 className="!w-4 !h-4" /> Remove
                    </button>
                )}
                {selectedValue && (
                    <span className="!text-gray-700 !text-sm !truncate !max-w-md">
                        Selected: {selectedValue}
                    </span>
                )}
            </div>

            {description && <p className="!text-gray-700 !text-sm !mt-2">{description}</p>}

            {/* Image preview */}
            {showPreview && (
                <div className="!mt-4">
                    <div className="!relative !inline-block !group">
                        {imageLoading && (
                            <div className="!flex !items-center !justify-center !w-48 !h-48 !bg-gray-100 !border-2 !border-dashed !border-gray-300 !rounded-lg">
                                <div className="!animate-spin !rounded-full !h-8 !w-8 !border-b-2 !border-blue-500"></div>
                                <span className="!ml-2 !text-gray-500 !text-sm">Loading...</span>
                            </div>
                        )}
                        {!imageError && (
                            <img
                                src={selectedValue}
                                alt="Selected file preview"
                                className={`!max-w-48 !max-h-48 !object-contain !border !rounded-lg !shadow-sm ${imageLoading ? '!opacity-0 !absolute' : '!opacity-100'}`}
                                onLoadStart={handleImageLoadStart}
                                onLoad={handleImageLoad}
                                onError={handleImageError}
                            />
                        )}
                        {imageError && (
                            <div className="!flex !items-center !justify-center !w-48 !h-48 !bg-gray-100 !border-2 !border-dashed !border-gray-300 !rounded-lg">
                                <div className="!text-center">
                                    <div className="!text-red-500 !text-2xl !mb-2">⚠️</div>
                                    <span className="!text-gray-500 !text-sm">Failed to load image</span>
                                </div>
                            </div>
                        )}
                        {!imageLoading && (
                            <button
                                type="button"
                                onClick={handleRemove}
                                title="Remove image"
                                aria-label="Remove image"
                                className="!absolute !top-1.5 !right-1.5 !w-7 !h-7 !rounded-full !bg-white/95 !text-red-600 !border !border-gray-200 !shadow-md !flex !items-center !justify-center !opacity-0 group-hover:!opacity-100 focus:!opacity-100 hover:!bg-red-50 hover:!border-red-300 !transition-all !cursor-pointer"
                            >
                                <X className="!w-4 !h-4" />
                            </button>
                        )}
                    </div>
                </div>
            )}

            {selectedValue && !showPreview && (
                <div className="!mt-3 !inline-flex !items-center !gap-2 !px-3 !py-2 !bg-gray-50 !border !border-gray-200 !rounded-md">
                    <FileText className="!w-4 !h-4 !text-gray-500" />
                    <span className="!text-sm !text-gray-700 !truncate !max-w-xs">{selectedValue}</span>
                    <button
                        type="button"
                        onClick={handleRemove}
                        title="Remove file"
                        aria-label="Remove file"
                        className="!w-6 !h-6 !rounded-full !flex !items-center !justify-center !text-gray-400 hover:!bg-red-50 hover:!text-red-600 !transition-colors !border-0 !bg-transparent !cursor-pointer !ml-1"
                    >
                        <X className="!w-3.5 !h-3.5" />
                    </button>
                </div>
            )}

            <MediaLibraryModal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                onSelect={handleMediaSelect}
            />
        </div>
    );
};

export default FileInputComponent;
