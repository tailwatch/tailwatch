import { useEffect, useRef, useState } from 'react';
import { IoClose, IoExpand, IoContract } from 'react-icons/io5';
import { Eye, AlertCircle, RefreshCcw, Monitor, Smartphone, Loader2 } from 'lucide-react';
import { previewBlockPage } from '../IpManagementServices/IpManagementServices';

const BlockPagePreviewModal = ({ onClose, payload = {} }) => {
    const [showModal, setShowModal] = useState(false);
    const [isExpanded, setIsExpanded] = useState(false);
    const [device, setDevice] = useState('desktop');
    const [loading, setLoading] = useState(true);
    const [html, setHtml] = useState('');
    const [error, setError] = useState(null);
    const fetchedRef = useRef(false);

    const load = async () => {
        setLoading(true);
        setError(null);
        const result = await previewBlockPage(payload);
        if (result.success) {
            setHtml(result.html || '');
        } else {
            setError(result.message);
        }
        setLoading(false);
    };

    useEffect(() => {
        setShowModal(true);
        if (!fetchedRef.current) {
            fetchedRef.current = true;
            load();
        }
    }, []);

    const handleClose = () => {
        setShowModal(false);
        setTimeout(() => onClose?.(), 250);
    };

    const handleToggleExpand = () => setIsExpanded((p) => !p);

    return (
        <div className="!fixed !top-0 !left-0 !w-full !h-full !bg-gray-900/80 !backdrop-blur-sm !flex !justify-center !items-center !z-[1100] !p-4">
            <div
                className={`!bg-white !rounded-xl !shadow-2xl !transform !transition-all !duration-300 !ease-in-out
                    ${showModal ? '!scale-100 !opacity-100' : '!scale-90 !opacity-0'}
                    !flex !flex-col`}
                style={{
                    boxShadow: '0 0 25px 4px rgba(0, 121, 128, 0.25), 0 0 40px 6px rgba(0, 121, 128, 0.12)',
                    border: '2px solid rgba(0, 121, 128, 0.25)',
                    width: isExpanded ? '95%' : '100%',
                    maxWidth: isExpanded ? '95%' : '1100px',
                    height: isExpanded ? '95vh' : '90vh',
                }}
            >
                {/* Header */}
                <div className="!flex !items-center !justify-between !px-5 !py-3 !border-b !border-gray-200">
                    <div className="!flex !items-center !gap-3 !min-w-0">
                        <div className="!w-10 !h-10 !rounded-full !bg-[#007980]/10 !flex !items-center !justify-center !flex-shrink-0">
                            <Eye className="!w-5 !h-5 !text-[#007980]" />
                        </div>
                        <div className="!min-w-0">
                            <h3 className="!text-base !font-semibold !text-gray-900 !truncate">Block Page Preview</h3>
                            <p className="!text-xs !text-gray-500 !truncate">How blocked visitors will see this page</p>
                        </div>
                    </div>
                    <div className="!flex !items-center !gap-2 !flex-shrink-0">
                        {/* Device toggle */}
                        <div className="!inline-flex !items-center !p-0.5 !bg-gray-100 !rounded-lg !border !border-gray-200">
                            <button
                                onClick={() => setDevice('desktop')}
                                className={`!inline-flex !items-center !gap-1.5 !px-2.5 !py-1 !rounded-md !text-xs !font-semibold !transition-all !border-0 !cursor-pointer
                                    ${device === 'desktop' ? '!bg-white !text-[#007980] !shadow-sm' : '!bg-transparent !text-gray-500 hover:!text-gray-700'}`}
                                title="Desktop view"
                            >
                                <Monitor className="!w-3.5 !h-3.5" />
                                <span className="!hidden sm:!inline">Desktop</span>
                            </button>
                            <button
                                onClick={() => setDevice('mobile')}
                                className={`!inline-flex !items-center !gap-1.5 !px-2.5 !py-1 !rounded-md !text-xs !font-semibold !transition-all !border-0 !cursor-pointer
                                    ${device === 'mobile' ? '!bg-white !text-[#007980] !shadow-sm' : '!bg-transparent !text-gray-500 hover:!text-gray-700'}`}
                                title="Mobile view"
                            >
                                <Smartphone className="!w-3.5 !h-3.5" />
                                <span className="!hidden sm:!inline">Mobile</span>
                            </button>
                        </div>

                        <button
                            onClick={load}
                            disabled={loading}
                            title="Reload preview"
                            className="!w-8 !h-8 !rounded-full !flex !items-center !justify-center !text-gray-500 hover:!bg-gray-100 hover:!text-gray-800 !transition-colors !border-0 !bg-transparent !cursor-pointer disabled:!opacity-50 disabled:!cursor-not-allowed"
                        >
                            <RefreshCcw className={`!w-4 !h-4 ${loading ? '!animate-spin' : ''}`} />
                        </button>

                        <button
                            onClick={handleToggleExpand}
                            title={isExpanded ? 'Collapse' : 'Expand'}
                            className="!w-8 !h-8 !rounded-full !flex !items-center !justify-center !text-gray-500 hover:!bg-gray-100 hover:!text-gray-800 !transition-colors !border-0 !bg-transparent !cursor-pointer"
                        >
                            {isExpanded ? <IoContract size={18} /> : <IoExpand size={18} />}
                        </button>

                        <button
                            onClick={handleClose}
                            title="Close"
                            className="!w-8 !h-8 !rounded-full !flex !items-center !justify-center !text-gray-500 hover:!bg-gray-100 hover:!text-gray-800 !transition-colors !border-0 !bg-transparent !cursor-pointer"
                        >
                            <IoClose size={22} />
                        </button>
                    </div>
                </div>

                {/* Body */}
                <div className="!flex-1 !overflow-hidden !bg-gray-100 !p-3 sm:!p-5 !flex !justify-center !items-stretch">
                    <div
                        className="!bg-white !rounded-lg !shadow-md !border !border-gray-200 !overflow-hidden !relative !transition-all !duration-300 !w-full !h-full"
                        style={{ maxWidth: device === 'mobile' ? '420px' : '100%' }}
                    >
                        {loading && (
                            <div className="!absolute !inset-0 !flex !flex-col !items-center !justify-center !bg-white/95 !z-10">
                                <Loader2 className="!w-9 !h-9 !text-[#007980] !animate-spin !mb-3" />
                                <p className="!text-sm !text-gray-600 !font-medium">Loading preview…</p>
                            </div>
                        )}

                        {!loading && error && (
                            <div className="!flex !flex-col !items-center !justify-center !h-full !text-center !px-6">
                                <div className="!w-14 !h-14 !rounded-full !bg-red-50 !flex !items-center !justify-center !mb-3">
                                    <AlertCircle className="!w-7 !h-7 !text-red-500" />
                                </div>
                                <h4 className="!text-base !font-semibold !text-gray-800 !mb-1">Couldn't load preview</h4>
                                <p className="!text-sm !text-gray-500 !max-w-sm !mb-4">{error}</p>
                                <button
                                    onClick={load}
                                    className="!inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-sm !font-semibold !text-white !bg-[#007980] !rounded-md hover:!bg-[#005f64] !transition-colors !border-0 !cursor-pointer"
                                >
                                    <RefreshCcw className="!w-3.5 !h-3.5" />
                                    Try again
                                </button>
                            </div>
                        )}

                        {!loading && !error && (
                            <iframe
                                title="block-page-preview"
                                srcDoc={html}
                                sandbox="allow-same-origin"
                                className="!w-full !h-full !border-0 !bg-white"
                            />
                        )}
                    </div>
                </div>

                {/* Footer */}
                <div className="!px-5 !py-3 !border-t !border-gray-200 !flex !items-center !justify-end !gap-2">
                    <button
                        onClick={handleClose}
                        className="!px-4 !py-2 !text-sm !font-semibold !text-white !bg-[#007980] !rounded-lg hover:!bg-[#005f64] !transition-colors !border-0 !cursor-pointer"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
};

export default BlockPagePreviewModal;
