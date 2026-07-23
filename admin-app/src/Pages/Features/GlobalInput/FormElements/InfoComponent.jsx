import React from 'react'
import { Crown, Sparkles, ArrowRight } from 'lucide-react'
import { safeUrl } from '../../../../Components/Utils/HelperFunctions/urlSafety'

// Splits the description into plain text and <span>...</span> chunks,
// then renders each <span> as a styled "Pro feature" pill so users can
// scan the included features at a glance.
const renderDescription = (text) => {
    if (!text) return null;
    const parts = text.split(/(<span>[\s\S]*?<\/span>)/g);
    return parts.map((part, index) => {
        const match = part.match(/^<span>([\s\S]*?)<\/span>$/);
        if (match) {
            return (
                <span
                    key={index}
                    className="inline-flex items-center gap-1 mx-0.5 my-0.5 rounded-md border border-amber-300/70 bg-white/80 px-2 py-0.5 text-[12px] font-semibold text-amber-900 shadow-sm backdrop-blur-sm align-baseline"
                >
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
                    {match[1]}
                </span>
            );
        }
        return <React.Fragment key={index}>{part}</React.Fragment>;
    });
};

const InfoComponent = ({ id, label, description, links = [] }) => {
    return (
        <div className="w-full mx-auto">
            <div className="relative overflow-hidden rounded-2xl border border-orange-300/70 bg-gradient-to-br from-amber-100 via-orange-100 to-rose-200 p-6 shadow-lg">
                {/* decorative accents */}
                <div className="pointer-events-none absolute -top-16 -right-16 h-40 w-40 rounded-full bg-amber-300/40 blur-3xl" aria-hidden="true" />
                <div className="pointer-events-none absolute -bottom-16 -left-16 h-40 w-40 rounded-full bg-rose-300/30 blur-3xl" aria-hidden="true" />
                <Sparkles className="pointer-events-none absolute top-4 right-4 h-4 w-4 text-amber-500/70" aria-hidden="true" />

                <div className="relative flex items-start gap-4">
                    <div className="flex-shrink-0">
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-amber-600 via-amber-300 to-orange-300 shadow-md">
                            <Crown className="h-6 w-6 text-white drop-shadow-sm" />
                        </div>
                    </div>

                    <div className="flex-1 min-w-0">
                        {label && (
                            <h3 className="text-lg font-bold tracking-tight text-gray-900">
                                {label}
                            </h3>
                        )}

                        {description && (
                            <p className="mt-2 text-sm leading-loose text-gray-800">
                                {renderDescription(description)}
                            </p>
                        )}

                        {links && links.length > 0 && (
                            <div className="mt-5 flex flex-wrap gap-2">
                                {links.map((link, index) => (
                                    <a
                                        key={index}
                                        href={safeUrl(link.url)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="group inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-orange-600 via-orange-400 to-amber-400 px-5 py-2.5 text-sm font-semibold !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline shadow-md ring-1 ring-orange-500/20 transition-all duration-200 hover:from-orange-400 hover:via-orange-500 hover:to-amber-500 hover:shadow-lg hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-orange-300/70 focus:ring-offset-2"
                                    >
                                        <Crown className="h-4 w-4" />
                                        <span>{link.text}</span>
                                        <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" />
                                    </a>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}

export default InfoComponent
