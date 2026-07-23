import React, { useState, useRef, useLayoutEffect } from 'react';
import { createPortal } from 'react-dom';

// Portal-based tooltip: renders into document.body with position:fixed so it escapes any
// clipping ancestor (overflow-hidden tables, scroll containers, sticky headers, etc.).
// Auto-flips to the opposite side when the requested side would overflow the viewport.
export const Tooltip = ({
    children,
    message,
    position = 'top', // 'top' | 'bottom' | 'left' | 'right'
    className = ''
}) => {
    const [coords, setCoords] = useState(null);
    const [visible, setVisible] = useState(false);
    const [flipped, setFlipped] = useState(false);
    const triggerRef = useRef(null);
    const tooltipRef = useRef(null);

    const computeCoords = (pos) => {
        if (!triggerRef.current) return null;
        const rect = triggerRef.current.getBoundingClientRect();
        const offset = 8;
        switch (pos) {
            case 'bottom':
                return {
                    top: rect.bottom + offset,
                    left: rect.left + rect.width / 2,
                    transform: 'translateX(-50%)',
                };
            case 'left':
                return {
                    top: rect.top + rect.height / 2,
                    left: rect.left - offset,
                    transform: 'translate(-100%, -50%)',
                };
            case 'right':
                return {
                    top: rect.top + rect.height / 2,
                    left: rect.right + offset,
                    transform: 'translateY(-50%)',
                };
            case 'top':
            default:
                return {
                    top: rect.top - offset,
                    left: rect.left + rect.width / 2,
                    transform: 'translate(-50%, -100%)',
                };
        }
    };

    const handleEnter = () => {
        if (!message) return;
        setFlipped(false);
        setVisible(false);
        setCoords(computeCoords(position));
    };

    const handleLeave = () => {
        setCoords(null);
        setVisible(false);
        setFlipped(false);
    };

    // After the tooltip is rendered, measure it. If it overflows the viewport on the requested
    // side, flip to the opposite side. The `flipped` flag prevents an infinite re-flip loop.
    useLayoutEffect(() => {
        if (!coords || !tooltipRef.current) return;

        if (flipped) {
            setVisible(true);
            return;
        }

        const tip = tooltipRef.current.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const margin = 4;

        let flipTo = null;
        if (position === 'right' && tip.right > vw - margin) {
            flipTo = 'left';
        } else if (position === 'left' && tip.left < margin) {
            flipTo = 'right';
        } else if (position === 'top' && tip.top < margin) {
            flipTo = 'bottom';
        } else if (position === 'bottom' && tip.bottom > vh - margin) {
            flipTo = 'top';
        }

        if (flipTo) {
            setFlipped(true);
            setCoords(computeCoords(flipTo));
            // visibility stays false until the next layout pass, when `flipped === true` shows it
        } else {
            setVisible(true);
        }
    }, [coords, flipped, position]);

    return (
        <>
            <div
                ref={triggerRef}
                className="relative inline-block"
                onMouseEnter={handleEnter}
                onMouseLeave={handleLeave}
            >
                {children}
            </div>
            {coords && createPortal(
                <div
                    ref={tooltipRef}
                    className={`fixed z-[9999] px-3 py-2 text-sm text-white bg-gray-800 rounded-md shadow-lg whitespace-pre-wrap pointer-events-none ${className}`}
                    style={{
                        top: coords.top,
                        left: coords.left,
                        transform: coords.transform,
                        maxWidth: '30ch',
                        width: 'max-content',
                        wordWrap: 'break-word',
                        overflowWrap: 'break-word',
                        visibility: visible ? 'visible' : 'hidden',
                    }}
                >
                    {message}
                </div>,
                document.body
            )}
        </>
    );
};
