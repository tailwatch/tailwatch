import React from 'react';

/**
 * Country flags rendered as Unicode regional-indicator emoji.
 *
 * The emoji is computed directly from the ISO 3166-1 alpha-2 code (e.g. "US" →
 * 🇺🇸) — no lookup table or per-country asset is stored. Each ASCII letter maps
 * to its regional-indicator symbol (U+1F1E6 + offset).
 *
 * macOS / iOS / Android / most Linux render these natively. Windows' system
 * emoji font (Segoe UI Emoji) omits flag glyphs, so a bundled flags-only
 * webfont — a subset of Google's Noto Color Emoji (SIL Open Font License 1.1,
 * GPL-compatible) — is applied first via the `.wptw-flag` class (see
 * Admin/View/Static/css/custom.css) so the flags render on Windows too.
 */

/**
 * Convert an ISO 3166-1 alpha-2 country code to its flag emoji.
 *
 * @param {string} code Two-letter country code (case-insensitive).
 * @returns {string} Flag emoji, or '' when the code is not two A–Z letters.
 */
export const isoToFlagEmoji = ( code ) => {
	if ( typeof code !== 'string' ) {
		return '';
	}
	const cc = code.trim().toUpperCase();
	if ( ! /^[A-Z]{2}$/.test( cc ) ) {
		return '';
	}
	return String.fromCodePoint(
		0x1f1e6 + cc.charCodeAt( 0 ) - 65,
		0x1f1e6 + cc.charCodeAt( 1 ) - 65
	);
};

const SIZE_MAP = { sm: '1rem', md: '1.25rem', lg: '1.5rem' };

/**
 * Renders a country flag emoji for the given ISO alpha-2 code.
 *
 * @param {object} props
 * @param {string} props.countryCode ISO 3166-1 alpha-2 code.
 * @param {('sm'|'md'|'lg'|string)} [props.size] Preset (sm/md/lg) or any CSS font-size.
 * @param {string} [props.className] Extra classes to append.
 */
export const CountryFlag = ( { countryCode, size = 'md', className = '' } ) => {
	const emoji = isoToFlagEmoji( countryCode );
	if ( ! emoji ) {
		return null;
	}
	return (
		<span
			className={ `wptw-flag ${ className }`.trim() }
			style={ { fontSize: SIZE_MAP[ size ] || size, lineHeight: 1 } }
			role="img"
			aria-label={ typeof countryCode === 'string' ? countryCode.toUpperCase() : '' }
		>
			{ emoji }
		</span>
	);
};

// Back-compat alias: existing call sites import { Flags }.
export const Flags = CountryFlag;

export default CountryFlag;
