<?php
/**
 * Tailwatch - Serialized-safe search and replace
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\SearchReplace
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\SearchReplace;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces text inside serialized data by REWRITING it, never by unserializing it.
 *
 * The round-trip approach — `unserialize()`, walk, `serialize()` — is correct whenever
 * the value it is handed is itself valid serialized data. The failure is what happens
 * when it is not, because then the only remaining option is a flat `str_replace` over
 * the raw text, and that silently breaks every `s:N:` length prefix inside it:
 *
 *     prefix a:1:{s:1:"u";s:17:"http://old.test/a";} suffix
 *     prefix a:1:{s:1:"u";s:17:"http://brand-new.test/a";} suffix
 *                           ^^ still says 17, the string is now 23
 *
 * The row still looks fine. It fails later, at read time, as `false` — and by then the
 * backup that could have restored it is a generation old. Serialized fragments living
 * inside a larger value are ordinary in WordPress: page-builder meta wrapped in JSON,
 * options that concatenate blobs, anything slash-escaped on its way through the DB.
 *
 * So this parses structurally and emits lengths computed from the NEW bytes, which
 * makes them right by construction. It never calls `unserialize()`, so a hostile `O:`
 * payload is text to be rewritten rather than a class to be instantiated —
 * `__PHP_Incomplete_Class` is not merely avoided here, it is unreachable.
 *
 * The fail-safe: anything that does not parse is returned EXACTLY as it arrived and
 * logged. This never corrupts and never drops.
 */
class SerializedReplacer {

	/**
	 * Longest run of nested containers we will descend.
	 *
	 * Serialized data is attacker-reachable in principle (any plugin can store it), and
	 * a deeply nested payload would otherwise recurse until the stack gives out. Real
	 * WordPress data does not approach this.
	 */
	const MAX_DEPTH = 64;

	/**
	 * Replace every occurrence of $from with $to inside $data, serialization intact.
	 *
	 * @param string $data             Value from the database.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @return string Rewritten value, or $data unchanged when nothing could be done safely.
	 */
	public static function replace( $data, $from, $to, $case_insensitive = false ) {
		if ( ! is_string( $data ) || '' === $data || ! is_string( $from ) || '' === $from ) {
			return $data;
		}

		// Nothing to do is by far the common case; a full parse per row would be waste.
		$hit = $case_insensitive ? ( false !== stripos( $data, $from ) ) : ( false !== strpos( $data, $from ) );
		if ( ! $hit ) {
			return $data;
		}

		// Whole value is serialized: rewrite it as a unit so container lengths and the
		// element counts around it stay consistent.
		$cursor = 0;
		$whole  = self::rewrite_value( $data, $cursor, $from, $to, $case_insensitive, 0 );
		if ( null !== $whole && $cursor === strlen( $data ) ) {
			return $whole;
		}

		// Otherwise the value is plain text that may CONTAIN serialized spans. Rewrite
		// each span structurally and the text between them literally.
		return self::rewrite_embedded( $data, $from, $to, $case_insensitive );
	}

	/**
	 * Expand a domain replacement into the forms a site actually stores.
	 *
	 * A site that has ever had both http and https, or that stores protocol-relative
	 * URLs, holds all three shapes — and replacing only the one the operator typed
	 * leaves the others pointing at the old host. Ordering matters: the longest, most
	 * specific needles run first so `http://old` cannot be half-consumed by a later
	 * `//old` pass.
	 *
	 * @param string $from Source URL or host.
	 * @param string $to   Target URL or host.
	 * @return array<array{0:string,1:string}> Ordered from/to pairs.
	 */
	public static function expand_url_pairs( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;

		if ( '' === $from || '' === $to ) {
			return array();
		}

		$from_host = preg_replace( '#^https?://#i', '', $from );
		$to_host   = preg_replace( '#^https?://#i', '', $to );

		// Not a host at all — treat it as a literal needle.
		if ( '' === $from_host || $from_host === $from && false === strpos( $from, '.' ) ) {
			return array( array( $from, $to ) );
		}

		$from_host = rtrim( (string) $from_host, '/' );
		$to_host   = rtrim( (string) $to_host, '/' );

		// Whether the TARGET is explicitly http or https decides what the scheme-bearing
		// forms become; a bare host keeps whatever scheme it replaces.
		$to_scheme = '';
		if ( preg_match( '#^(https?)://#i', $to, $m ) ) {
			$to_scheme = strtolower( $m[1] );
		}

		$https_to = ( '' !== $to_scheme ) ? $to_scheme . '://' . $to_host : 'https://' . $to_host;
		$http_to  = ( '' !== $to_scheme ) ? $to_scheme . '://' . $to_host : 'http://' . $to_host;

		return array(
			array( 'https://' . $from_host, $https_to ),
			array( 'http://' . $from_host, $http_to ),
			array( '//' . $from_host, '//' . $to_host ),
		);
	}

	/**
	 * Rewrite one serialized value at $offset, advancing it past what was consumed.
	 *
	 * Returns null when the bytes at $offset are not a well-formed value, which is the
	 * signal every caller uses to leave the input alone.
	 *
	 * @param string $s                Whole subject.
	 * @param int    $offset           Cursor, advanced in place.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @param int    $depth            Current nesting depth.
	 * @return string|null
	 */
	private static function rewrite_value( $s, &$offset, $from, $to, $case_insensitive, $depth ) {
		if ( $depth > self::MAX_DEPTH || $offset >= strlen( $s ) ) {
			return null;
		}

		$type = $s[ $offset ];

		switch ( $type ) {
			case 'N':
				return self::consume_literal( $s, $offset, 'N;' );

			case 'b':
				return self::rewrite_scalar( $s, $offset, 'b', '/^b:[01];/' );

			case 'i':
				return self::rewrite_scalar( $s, $offset, 'i', '/^i:-?\d+;/' );

			case 'd':
				// Covers INF, -INF and NAN as well as ordinary notation.
				return self::rewrite_scalar( $s, $offset, 'd', '/^d:(-?(\d+(\.\d+)?([eE][+-]?\d+)?|INF)|NAN);/' );

			case 'R':
			case 'r':
				// A back-reference is an index into the value graph. We never reorder or
				// drop elements, so indices stay valid and the token passes through.
				return self::rewrite_scalar( $s, $offset, $type, '/^[Rr]:-?\d+;/' );

			case 's':
				return self::rewrite_string( $s, $offset, $from, $to, $case_insensitive );

			case 'a':
				return self::rewrite_array( $s, $offset, $from, $to, $case_insensitive, $depth );

			case 'O':
				return self::rewrite_object( $s, $offset, $from, $to, $case_insensitive, $depth );

			case 'E':
				// PHP 8.1 enum: E:len:"Enum:Case";. The payload is a class and case name,
				// never site content, so it is copied verbatim rather than searched.
				return self::rewrite_sized_string( $s, $offset, 'E' );

			case 'C':
				// Custom (Serializable::serialize) payload is opaque — its length is in
				// bytes we cannot interpret. Copy it through untouched rather than guess.
				return self::rewrite_sized_string( $s, $offset, 'C' );
		}

		return null;
	}

	/**
	 * Consume an exact literal token.
	 *
	 * @param string $s       Subject.
	 * @param int    $offset  Cursor.
	 * @param string $literal Expected text.
	 * @return string|null
	 */
	private static function consume_literal( $s, &$offset, $literal ) {
		$len = strlen( $literal );
		if ( substr( $s, $offset, $len ) !== $literal ) {
			return null;
		}
		$offset += $len;
		return $literal;
	}

	/**
	 * Consume a scalar token matched by a pattern, unchanged.
	 *
	 * @param string $s       Subject.
	 * @param int    $offset  Cursor.
	 * @param string $type    Type letter, for clarity at call sites.
	 * @param string $pattern Anchored pattern.
	 * @return string|null
	 */
	private static function rewrite_scalar( $s, &$offset, $type, $pattern ) {
		unset( $type );
		if ( ! preg_match( $pattern, substr( $s, $offset ), $m ) ) {
			return null;
		}
		$offset += strlen( $m[0] );
		return $m[0];
	}

	/**
	 * Rewrite `s:LEN:"BYTES";`, recomputing LEN from the replaced bytes.
	 *
	 * LEN is a BYTE count, and the payload may contain quotes, semicolons and NUL —
	 * private and protected property names carry NULs by design. So the payload is
	 * sliced by length and never scanned for a delimiter.
	 *
	 * @param string $s                Subject.
	 * @param int    $offset           Cursor.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @return string|null
	 */
	private static function rewrite_string( $s, &$offset, $from, $to, $case_insensitive ) {
		if ( ! preg_match( '/^s:(\d+):"/', substr( $s, $offset ), $m ) ) {
			return null;
		}

		$header = $m[0];
		$len    = (int) $m[1];
		$start  = $offset + strlen( $header );

		// The closing `";` has to be exactly where the declared length says it is. If it
		// is not, this value was already corrupt before we touched it.
		if ( '";' !== substr( $s, $start + $len, 2 ) ) {
			return null;
		}

		$payload = substr( $s, $start, $len );
		$payload = $case_insensitive ? str_ireplace( $from, $to, $payload ) : str_replace( $from, $to, $payload );

		$offset = $start + $len + 2;

		return 's:' . strlen( $payload ) . ':"' . $payload . '";';
	}

	/**
	 * Copy a `X:LEN:"BYTES";` token through without searching its payload.
	 *
	 * @param string $s      Subject.
	 * @param int    $offset Cursor.
	 * @param string $type   Type letter.
	 * @return string|null
	 */
	private static function rewrite_sized_string( $s, &$offset, $type ) {
		if ( 'C' === $type ) {
			// C:LEN:"NAME":DATALEN:{DATA}
			if ( ! preg_match( '/^C:(\d+):"/', substr( $s, $offset ), $m ) ) {
				return null;
			}
			$name_len   = (int) $m[1];
			$name_start = $offset + strlen( $m[0] );
			if ( '":' !== substr( $s, $name_start + $name_len, 2 ) ) {
				return null;
			}
			$after = $name_start + $name_len + 2;
			if ( ! preg_match( '/^(\d+):\{/', substr( $s, $after ), $d ) ) {
				return null;
			}
			$data_len   = (int) $d[1];
			$data_start = $after + strlen( $d[0] );
			if ( '}' !== substr( $s, $data_start + $data_len, 1 ) ) {
				return null;
			}
			$end    = $data_start + $data_len + 1;
			$token  = substr( $s, $offset, $end - $offset );
			$offset = $end;
			return $token;
		}

		// E:LEN:"Enum:Case";
		if ( ! preg_match( '/^E:(\d+):"/', substr( $s, $offset ), $m ) ) {
			return null;
		}
		$len   = (int) $m[1];
		$start = $offset + strlen( $m[0] );
		if ( '";' !== substr( $s, $start + $len, 2 ) ) {
			return null;
		}
		$end    = $start + $len + 2;
		$token  = substr( $s, $offset, $end - $offset );
		$offset = $end;
		return $token;
	}

	/**
	 * Rewrite `a:COUNT:{ key value ... }`.
	 *
	 * COUNT is an element count, not a byte length, so it survives replacement unchanged
	 * — only the strings inside move.
	 *
	 * @param string $s                Subject.
	 * @param int    $offset           Cursor.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @param int    $depth            Nesting depth.
	 * @return string|null
	 */
	private static function rewrite_array( $s, &$offset, $from, $to, $case_insensitive, $depth ) {
		if ( ! preg_match( '/^a:(\d+):\{/', substr( $s, $offset ), $m ) ) {
			return null;
		}

		$count   = (int) $m[1];
		$offset += strlen( $m[0] );
		$out     = $m[0];

		for ( $i = 0; $i < $count; $i++ ) {
			// Keys are only ever int or string, but they are parsed by the same routine
			// so a string key gets its length recomputed like any other.
			$key = self::rewrite_value( $s, $offset, $from, $to, $case_insensitive, $depth + 1 );
			if ( null === $key ) {
				return null;
			}
			$value = self::rewrite_value( $s, $offset, $from, $to, $case_insensitive, $depth + 1 );
			if ( null === $value ) {
				return null;
			}
			$out .= $key . $value;
		}

		if ( '}' !== substr( $s, $offset, 1 ) ) {
			return null;
		}
		++$offset;

		return $out . '}';
	}

	/**
	 * Rewrite `O:LEN:"NAME":COUNT:{ ... }`.
	 *
	 * The class NAME is copied verbatim — it is never site content, and rewriting it
	 * would rename a class. Property names are ordinary `s:` values and go through the
	 * same path, which is what makes private (`\0Class\0prop`) and protected
	 * (`\0*\0prop`) properties work here: they are sliced by length, so their embedded
	 * NULs are irrelevant.
	 *
	 * @param string $s                Subject.
	 * @param int    $offset           Cursor.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @param int    $depth            Nesting depth.
	 * @return string|null
	 */
	private static function rewrite_object( $s, &$offset, $from, $to, $case_insensitive, $depth ) {
		if ( ! preg_match( '/^O:(\d+):"/', substr( $s, $offset ), $m ) ) {
			return null;
		}

		$name_len   = (int) $m[1];
		$name_start = $offset + strlen( $m[0] );
		$name       = substr( $s, $name_start, $name_len );

		if ( '":' !== substr( $s, $name_start + $name_len, 2 ) ) {
			return null;
		}

		$after = $name_start + $name_len + 2;
		if ( ! preg_match( '/^(\d+):\{/', substr( $s, $after ), $c ) ) {
			return null;
		}

		$count  = (int) $c[1];
		$offset = $after + strlen( $c[0] );
		$out    = 'O:' . $name_len . ':"' . $name . '":' . $count . ':{';

		for ( $i = 0; $i < $count; $i++ ) {
			$key = self::rewrite_value( $s, $offset, $from, $to, $case_insensitive, $depth + 1 );
			if ( null === $key ) {
				return null;
			}
			$value = self::rewrite_value( $s, $offset, $from, $to, $case_insensitive, $depth + 1 );
			if ( null === $value ) {
				return null;
			}
			$out .= $key . $value;
		}

		if ( '}' !== substr( $s, $offset, 1 ) ) {
			return null;
		}
		++$offset;

		return $out . '}';
	}

	/**
	 * Rewrite serialized spans found inside an otherwise plain value.
	 *
	 * This is the case the round-trip approach cannot reach: a serialized fragment with
	 * a prefix or suffix around it, where `unserialize()` on the whole value fails and
	 * the only fallback left is a flat replacement that breaks every length prefix.
	 *
	 * Candidates are anchored to a serialization boundary — start of string, `{`, `;`
	 * or `:` — because `s:\d+:"` also matches inside ordinary prose (the `s:2:"` in
	 * `class:2:"x`), and a false positive here would be read as a corrupt span.
	 *
	 * If a boundary-anchored candidate will not parse, the ENTIRE value is returned
	 * byte-identical and the fact is logged. That fail-safe is the whole point: text that
	 * looks like serialized data but does not parse is exactly the text where a blind
	 * replacement turns a readable row into an unreadable one. Doing less work is always
	 * recoverable; corrupting a row is not.
	 *
	 * @param string $s                Subject.
	 * @param string $from             Needle.
	 * @param string $to               Replacement.
	 * @param bool   $case_insensitive Match case-insensitively.
	 * @return string
	 */
	private static function rewrite_embedded( $s, $from, $to, $case_insensitive ) {
		$plain = $case_insensitive ? str_ireplace( $from, $to, $s ) : str_replace( $from, $to, $s );

		// Only container and string openers can begin a span whose length we could get
		// wrong; a bare scalar carries no length.
		if ( ! preg_match_all( '/(?:a:\d+:\{|O:\d+:"|s:\d+:")/', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			return $plain;
		}

		$out    = '';
		$copied = 0;

		foreach ( $m[0] as $hit ) {
			$start = (int) $hit[1];
			if ( $start < $copied ) {
				// Already inside a span that was consumed.
				continue;
			}
			if ( ! self::at_boundary( $s, $start ) ) {
				continue;
			}

			$cursor    = $start;
			$rewritten = self::rewrite_value( $s, $cursor, $from, $to, $case_insensitive, 0 );

			if ( null === $rewritten ) {
				Log::warning(
					'Search-replace left a value untouched: it contains serialized data that would not parse.',
					array(
						'feature' => 'search_replace',
						'action'  => 'serialized_span_unparsed',
						'origin'  => 'system',
					)
				);
				return $s;
			}

			$between = substr( $s, $copied, $start - $copied );
			$out    .= $case_insensitive ? str_ireplace( $from, $to, $between ) : str_replace( $from, $to, $between );
			$out    .= $rewritten;
			$copied  = $cursor;
		}

		$tail = substr( $s, $copied );
		$out .= $case_insensitive ? str_ireplace( $from, $to, $tail ) : str_replace( $from, $to, $tail );

		return $out;
	}

	/**
	 * Whether $offset sits where a serialized value may legitimately begin.
	 *
	 * Inside serialized data every value follows the start of the string, an opening
	 * brace, a semicolon, or the colon of a container header. Requiring one of those
	 * keeps `s:\d+:"` from matching the tail of an ordinary word.
	 *
	 * @param string $s      Subject.
	 * @param int    $offset Candidate position.
	 * @return bool
	 */
	private static function at_boundary( $s, $offset ) {
		if ( 0 === $offset ) {
			return true;
		}
		$prev = $s[ $offset - 1 ];
		return '{' === $prev || ';' === $prev || ':' === $prev;
	}
}
