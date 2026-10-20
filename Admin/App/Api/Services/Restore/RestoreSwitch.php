<?php
/**
 * Tailwatch - Restore Switch
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Restore
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Restore;

use Tailwatch\Admin\App\Api\Services\TickBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a restore's prepared files in place, and can put the site's own back.
 *
 * Extraction writes the backup into a staging tree. This makes the site
 * match it: each restored folder is swapped in with two renames, the site's copy moved
 * aside rather than deleted, and anything a restored area holds that the backup does not
 * is moved aside too. Nothing is deleted until cleanup(), so every step can be undone.
 *
 * Operations are planned once and run in order from a saved index. Each one looks at
 * where things already are before moving anything, so a tick killed halfway through an
 * operation repeats it safely.
 */
class RestoreSwitch {

	const OP_SWAP  = 'swap';
	const OP_FILE  = 'file';
	const OP_ASIDE = 'aside';
	const OP_MERGE = 'merge';
	const OP_SWEEP = 'sweep';

	/**
	 * Left inside a folder while it is copied in across filesystems, so a resumed tick
	 * carries on copying instead of moving the half-copied folder aside.
	 */
	const COPY_MARKER = '.tailwatch-restore-copying';

	/**
	 * Suffix for a file written beside its destination before replacing it.
	 */
	const TEMP_SUFFIX = '.tailwatch-restore-tmp';

	/**
	 * Name prefixes of the folders this class creates, and the only ones cleanup() deletes.
	 */
	const STAGING_PREFIX = '.tailwatch-restore-';
	const ASIDE_PREFIX   = '.tailwatch-old-';

	/**
	 * Folder inside the aside tree recording which files a merge brought in, so rollback can
	 * tell a file that came from the backup from one the site gained after it.
	 */
	const JOURNAL_DIR = '.tailwatch-journal';

	/**
	 * Extensions WordPress never needs under uploads.
	 */
	const EXECUTABLE_EXTENSIONS = array( 'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'pgif', 'shtml', 'cgi', 'pl' );

	/**
	 * Entries handled between deadline checks.
	 */
	const CHECK_EVERY = 100;

	/**
	 * Plan the switch.
	 *
	 * In a replaced area, a link or a folder the packer could not read is left where it is
	 * when the backup lacks it: no backup holds either, so the absence says nothing about
	 * whether the site still needs it. In a merged area an unreadable folder is left as it is
	 * too, since it cannot be swept.
	 *
	 * @param array $layout {
	 *     @type string   $site       Live site root.
	 *     @type string   $staging    Staging root holding the prepared files.
	 *     @type string   $old        Root the site's own copies are moved aside to.
	 *     @type string[] $whole      Relative folders swapped whole.
	 *     @type string[] $replace    Relative containers whose children are replaced exactly.
	 *     @type string[] $merge      Relative containers merged file by file.
	 *     @type string[] $keep       Absolute paths never moved or replaced.
	 *     @type string[] $keep_names File names never moved aside from a container.
	 * }
	 * @return array{ops:array,kept:int,unbacked:string[]}
	 */
	public static function plan( array $layout ) {
		$site       = self::norm( $layout['site'] );
		$staging    = self::norm( $layout['staging'] );
		$whole      = self::rels( isset( $layout['whole'] ) ? $layout['whole'] : array() );
		$replace    = self::rels( isset( $layout['replace'] ) ? $layout['replace'] : array() );
		$merge      = self::rels( isset( $layout['merge'] ) ? $layout['merge'] : array() );
		$keep       = self::keep_list( $layout );
		$keep_names = array_map( 'strtolower', (array) ( isset( $layout['keep_names'] ) ? $layout['keep_names'] : array() ) );
		$areas      = array_merge( $whole, $replace, $merge );

		$ops      = array();
		$kept     = 0;
		$unbacked = array();

		foreach ( $whole as $rel ) {
			if ( ! is_dir( $staging . '/' . $rel ) ) {
				continue;
			}
			if ( self::is_kept( $site . '/' . $rel, $keep ) ) {
				++$kept;
				continue;
			}
			$ops[] = self::op( self::OP_SWAP, $rel );
		}

		foreach ( array_merge( $replace, $merge ) as $rel ) {
			$staged_dir = $staging . '/' . $rel;
			if ( ! is_dir( $staged_dir ) ) {
				// The backup did not carry this area, so nothing here is measured against it.
				continue;
			}

			$is_merge     = in_array( $rel, $merge, true );
			$staged_names = self::children( $staged_dir );
			$live_names   = is_dir( $site . '/' . $rel ) ? self::children( $site . '/' . $rel ) : array();

			if ( $is_merge ) {
				// Before the merge, while every file the backup has is still in staging.
				foreach ( $live_names as $name ) {
					$crel = $rel . '/' . $name;
					if ( in_array( $crel, $areas, true ) || self::is_kept( $site . '/' . $crel, $keep ) ) {
						continue;
					}
					if ( is_dir( $site . '/' . $crel ) && ! is_link( $site . '/' . $crel ) ) {
						if ( self::is_unbacked( $site . '/' . $crel ) ) {
							$unbacked[] = $crel;
						} else {
							$ops[] = self::op( self::OP_SWEEP, $crel );
						}
					} elseif ( self::is_executable( $name ) && ! self::has_name( $staged_names, $name ) ) {
						$ops[] = self::op( self::OP_ASIDE, $crel );
					}
				}
			}

			foreach ( $staged_names as $name ) {
				$crel = $rel . '/' . $name;
				if ( in_array( $crel, $areas, true ) ) {
					continue;
				}
				if ( self::is_kept( $site . '/' . $crel, $keep ) ) {
					++$kept;
					continue;
				}
				if ( is_dir( $staging . '/' . $crel ) ) {
					$ops[] = self::op( $is_merge ? self::OP_MERGE : self::OP_SWAP, $crel );
				} else {
					$ops[] = self::op( self::OP_FILE, $crel );
				}
			}

			if ( ! $is_merge ) {
				foreach ( $live_names as $name ) {
					$crel = $rel . '/' . $name;
					if ( in_array( $crel, $areas, true ) || self::has_name( $staged_names, $name ) ) {
						continue;
					}
					if ( in_array( strtolower( $name ), $keep_names, true ) || self::is_kept( $site . '/' . $crel, $keep ) ) {
						continue;
					}
					if ( self::is_unbacked( $site . '/' . $crel ) ) {
						$unbacked[] = $crel;
						continue;
					}
					$ops[] = self::op( self::OP_ASIDE, $crel );
				}
			}
		}

		// The site root holds more than WordPress, so its files are written over and
		// nothing there is ever moved aside for being absent from the backup.
		foreach ( self::children( $staging ) as $name ) {
			if ( in_array( $name, $areas, true ) ) {
				continue;
			}
			if ( self::is_kept( $site . '/' . $name, $keep ) ) {
				++$kept;
				continue;
			}
			$ops[] = self::op( is_dir( $staging . '/' . $name ) ? self::OP_SWAP : self::OP_FILE, $name );
		}

		return array(
			'ops'      => $ops,
			'kept'     => $kept,
			'unbacked' => $unbacked,
		);
	}

	/**
	 * Run planned operations from $index until they are all done, the deadline, or a failure.
	 *
	 * @param array $layout   Layout given to plan().
	 * @param array $ops      Planned operations.
	 * @param int   $index    First operation to run.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return array{index:int,done:bool,errors:array}
	 */
	public static function run( array $layout, array $ops, $index, $deadline ) {
		$total = count( $ops );
		$start = max( 0, (int) $index );
		$out   = array(
			'index'  => $start,
			'done'   => false,
			'errors' => array(),
		);

		for ( $i = $start; $i < $total; $i++ ) {
			if ( $i > $start && TickBudget::spent( $deadline ) ) {
				$out['index'] = $i;
				return $out;
			}

			$result = self::apply( $layout, $ops[ $i ], $deadline );

			if ( true !== $result ) {
				$out['index'] = $i;
				if ( 'partial' !== $result ) {
					$out['errors'][] = (string) $result;
				}
				return $out;
			}
		}

		$out['index'] = $total;
		$out['done']  = true;
		return $out;
	}

	/**
	 * Put the site's own copies back for operations up to and including $upto.
	 *
	 * Walks backwards, so the site is unwound in the reverse of the order it was changed.
	 * An operation that never started leaves nothing to undo and is passed over.
	 *
	 * @param array $layout Layout given to plan().
	 * @param array $ops    Planned operations.
	 * @param int   $upto   Last operation that may have run.
	 * @return array Errors; empty when everything was put back.
	 */
	public static function rollback( array $layout, array $ops, $upto ) {
		$errors = array();

		for ( $i = min( (int) $upto, count( $ops ) - 1 ); $i >= 0; $i-- ) {
			$op    = $ops[ $i ];
			$paths = self::paths( $layout, $op['rel'] );
			$from  = self::where_aside( $paths );

			switch ( $op['type'] ) {
				case self::OP_ASIDE:
					if ( '' !== $from && ! self::present( $paths['live'] ) && ! self::move( $from, $paths['live'] ) ) {
						$errors[] = self::could_not( $paths['live'] );
					}
					break;

				case self::OP_SWAP:
				case self::OP_FILE:
					// The live item is the restored one when the site's copy went aside, or when
					// there was no site copy and staging has been emptied into it.
					if ( self::present( $paths['live'] ) && ( '' !== $from || ! self::present( $paths['staged'] ) ) ) {
						if ( ! self::present( $paths['staged'] ) ) {
							wp_mkdir_p( dirname( $paths['staged'] ) );
							if ( ! self::move( $paths['live'], $paths['staged'] ) ) {
								self::remove_tree( $paths['live'], 0 );
							}
						} else {
							self::remove_tree( $paths['live'], 0 );
						}
					}
					if ( '' !== $from && ! self::present( $paths['live'] ) && ! self::move( $from, $paths['live'] ) ) {
						$errors[] = self::could_not( $paths['live'] );
					}
					break;

				case self::OP_MERGE:
					foreach ( self::journal_entries( $layout, $paths ) as $relf ) {
						$live_f   = $paths['live'] . '/' . $relf;
						$staged_f = $paths['staged'] . '/' . $relf;
						$aside_f  = $paths['old'] . '/' . $relf;
						// Staging emptied of it means the live file is the backup's copy.
						if ( ! self::present( $staged_f ) && self::present( $live_f ) ) {
							wp_mkdir_p( dirname( $staged_f ) );
							if ( ! self::move_or_copy( $live_f, $staged_f ) ) {
								wp_delete_file( $live_f );
							}
						}
						if ( self::present( $aside_f ) && ! self::present( $live_f ) ) {
							wp_mkdir_p( dirname( $live_f ) );
							if ( ! self::move_or_copy( $aside_f, $live_f ) ) {
								$errors[] = self::could_not( $live_f );
							}
						}
					}
					break;

				case self::OP_SWEEP:
					if ( '' === $from ) {
						break;
					}
					foreach ( self::files_under( $from ) as $relf ) {
						$live_f = $paths['live'] . '/' . $relf;
						if ( ! self::present( $live_f ) ) {
							wp_mkdir_p( dirname( $live_f ) );
							if ( ! self::move_or_copy( $from . '/' . $relf, $live_f ) ) {
								$errors[] = self::could_not( $live_f );
							}
						}
					}
					break;
			}
		}

		return $errors;
	}

	/**
	 * Delete the staging and aside trees, in deadline-bounded passes.
	 *
	 * @param array $layout   Layout given to plan().
	 * @param array $ops      Planned operations, for the copies kept beside their originals.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string True once everything is gone, 'partial' when stopped by the deadline,
	 *                     else why not.
	 */
	public static function cleanup( array $layout, array $ops, $deadline ) {
		foreach ( $ops as $op ) {
			$paths = self::paths( $layout, $op['rel'] );
			if ( self::present( $paths['beside'] ) ) {
				$removed = self::remove_tree( $paths['beside'], $deadline );
				if ( true !== $removed ) {
					return $removed;
				}
			}
			$marker = $paths['live'] . '/' . self::COPY_MARKER;
			if ( is_file( $marker ) ) {
				wp_delete_file( $marker );
			}
		}

		foreach ( array( self::norm( $layout['old'] ), self::norm( $layout['staging'] ) ) as $root ) {
			$name = wp_basename( $root );
			if ( 0 !== strpos( $name, self::STAGING_PREFIX ) && 0 !== strpos( $name, self::ASIDE_PREFIX ) ) {
				continue;
			}
			$removed = self::remove_tree( $root, $deadline );
			if ( true !== $removed ) {
				return $removed;
			}
		}

		return true;
	}

	/**
	 * Apply one operation.
	 *
	 * @param array $layout   Layout.
	 * @param array $op       Operation.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string True when finished, 'partial' when stopped by the deadline, else an error.
	 */
	private static function apply( array $layout, array $op, $deadline ) {
		$paths = self::paths( $layout, $op['rel'] );

		switch ( $op['type'] ) {
			case self::OP_ASIDE:
				return self::move_aside( $paths ) ? true : self::could_not( $paths['live'] );

			case self::OP_SWAP:
				return self::put_in_place( $paths, true, $deadline );

			case self::OP_FILE:
				return self::put_in_place( $paths, false, $deadline );

			case self::OP_MERGE:
				return self::merge_in( $layout, $paths, $deadline );

			case self::OP_SWEEP:
				return self::sweep( $paths, $deadline );
		}

		return self::could_not( $paths['live'] );
	}

	/**
	 * Move the site's item aside and the staged one into its place.
	 *
	 * @param array $paths    Paths for the operation.
	 * @param bool  $is_dir   Whether the staged item is a folder.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string
	 */
	private static function put_in_place( array $paths, $is_dir, $deadline ) {
		$marker  = $paths['live'] . '/' . self::COPY_MARKER;
		$copying = $is_dir && is_file( $marker );

		if ( ! self::present( $paths['staged'] ) ) {
			if ( $copying ) {
				wp_delete_file( $marker );
			}
			return true;
		}

		if ( ! $copying ) {
			if ( self::present( $paths['live'] ) ) {
				if ( $is_dir && is_dir( $paths['live'] ) && ! is_link( $paths['live'] ) ) {
					$stuck = self::carry_unbacked( $paths['live'], $paths['staged'] );
					if ( '' !== $stuck ) {
						return self::could_not( $stuck );
					}
				}
				if ( ! self::move_aside( $paths ) ) {
					return self::could_not( $paths['live'] );
				}
			}

			wp_mkdir_p( dirname( $paths['live'] ) );
			if ( self::move( $paths['staged'], $paths['live'] ) ) {
				return true;
			}

			if ( ! $is_dir ) {
				// Another filesystem: copy beside the destination, then rename over it.
				$temp = $paths['live'] . self::TEMP_SUFFIX;
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
				if ( @copy( $paths['staged'], $temp ) && self::move( $temp, $paths['live'] ) ) {
					wp_delete_file( $paths['staged'] );
					return true;
				}
				self::discard( $temp );
				return self::could_not( $paths['live'] );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a one-byte marker in a folder this restore is creating.
			if ( ! wp_mkdir_p( $paths['live'] ) || false === file_put_contents( $marker, '1' ) ) {
				return self::could_not( $paths['live'] );
			}
		}

		$copied = self::copy_tree( $paths['staged'], $paths['live'], $deadline );
		if ( true !== $copied ) {
			return $copied;
		}

		// Staging first, marker last: a tick killed in between finds staging gone and only
		// has the marker left to remove.
		self::remove_tree( $paths['staged'], 0 );
		wp_delete_file( $marker );

		return true;
	}

	/**
	 * Move staged files into a live folder one by one, the site's versions moved aside.
	 *
	 * @param array $layout   Layout.
	 * @param array $paths    Paths for the operation.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string
	 */
	private static function merge_in( array $layout, array $paths, $deadline ) {
		if ( ! self::present( $paths['staged'] ) ) {
			return true;
		}

		$journal = self::journal_file( $layout, $paths );
		$count   = 0;
		foreach ( self::files_under( $paths['staged'] ) as $relf ) {
			if ( ++$count > 1 && 0 === $count % self::CHECK_EVERY && TickBudget::spent( $deadline ) ) {
				return 'partial';
			}

			$live_f   = $paths['live'] . '/' . $relf;
			$aside_f  = $paths['old'] . '/' . $relf;
			$staged_f = $paths['staged'] . '/' . $relf;

			// Recorded before anything moves, so a rollback knows this file came from staging.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append-only journal inside this restore's aside folder.
			if ( false === file_put_contents( $journal, $relf . "\n", FILE_APPEND ) ) {
				return self::could_not( $live_f );
			}

			if ( self::present( $live_f ) && ! self::present( $aside_f ) ) {
				wp_mkdir_p( dirname( $aside_f ) );
				// Across filesystems the site's version is copied aside and then replaced below.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
				if ( ! self::move( $live_f, $aside_f ) && ! @copy( $live_f, $aside_f ) ) {
					return self::could_not( $live_f );
				}
			}

			wp_mkdir_p( dirname( $live_f ) );
			if ( ! self::place_file( $staged_f, $live_f ) ) {
				return self::could_not( $live_f );
			}
		}

		self::remove_tree( $paths['staged'], 0 );
		return true;
	}

	/**
	 * Move aside the executable files in a live folder that the backup does not have.
	 *
	 * @param array $paths    Paths for the operation.
	 * @param int   $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string
	 */
	private static function sweep( array $paths, $deadline ) {
		if ( ! is_dir( $paths['live'] ) ) {
			return true;
		}

		$count = 0;
		foreach ( self::files_under( $paths['live'] ) as $relf ) {
			if ( ++$count > 1 && 0 === $count % self::CHECK_EVERY && TickBudget::spent( $deadline ) ) {
				return 'partial';
			}

			if ( ! self::is_executable( $relf ) || self::present( $paths['staged'] . '/' . $relf ) ) {
				continue;
			}

			$aside_f = $paths['old'] . '/' . $relf;
			wp_mkdir_p( dirname( $aside_f ) );
			if ( ! self::move_or_copy( $paths['live'] . '/' . $relf, $aside_f ) ) {
				return self::could_not( $paths['live'] . '/' . $relf );
			}
		}

		return true;
	}

	/**
	 * Move the site's item out of the way, into the aside tree or, across filesystems, beside itself.
	 *
	 * @param array $paths Paths for the operation.
	 * @return bool
	 */
	private static function move_aside( array $paths ) {
		if ( ! self::present( $paths['live'] ) ) {
			return true;
		}

		if ( ! self::present( $paths['old'] ) ) {
			wp_mkdir_p( dirname( $paths['old'] ) );
			if ( self::move( $paths['live'], $paths['old'] ) ) {
				return true;
			}
		}

		return ! self::present( $paths['beside'] ) && self::move( $paths['live'], $paths['beside'] );
	}

	/**
	 * Carry what the backup could not contain from the site's folder into the staged one.
	 *
	 * The packer never follows a symlinked folder nested inside an area and cannot read an
	 * unreadable one, so neither is in the backup. Swapping the folder would lose them, so
	 * when one cannot be moved, what was already carried goes back and the swap stops.
	 *
	 * @param string $live_dir   Site folder about to be moved aside.
	 * @param string $staged_dir Staged folder about to replace it.
	 * @return string What could not be carried, or '' when nothing is left behind.
	 */
	private static function carry_unbacked( $live_dir, $staged_dir ) {
		$found = array();
		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $live_dir, \FilesystemIterator::SKIP_DOTS ),
					static function ( $current ) use ( &$found ) {
						if ( self::is_unbacked( $current->getPathname() ) ) {
							$found[] = $current->getPathname();
							return false;
						}
						return true;
					}
				),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			iterator_count( $items );
		} catch ( \UnexpectedValueException $e ) {
			return $live_dir;
		}

		$root  = strlen( self::norm( $live_dir ) ) + 1;
		$moved = array();
		foreach ( $found as $path ) {
			$rel    = substr( self::norm( $path ), $root );
			$target = self::norm( $staged_dir ) . '/' . $rel;
			if ( '' === $rel || self::present( $target ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $target ) );
			if ( ! self::move( $path, $target ) ) {
				foreach ( array_reverse( $moved ) as $pair ) {
					self::move( $pair[1], $pair[0] );
				}
				return $path;
			}
			$moved[] = array( $path, $target );
		}

		return '';
	}

	/**
	 * Copy a staged tree into a live folder, deleting each staged file once it has landed.
	 *
	 * @param string $from     Staged folder.
	 * @param string $to       Live folder.
	 * @param int    $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string
	 */
	private static function copy_tree( $from, $to, $deadline ) {
		$count = 0;
		foreach ( self::files_under( $from ) as $relf ) {
			if ( ++$count > 1 && 0 === $count % self::CHECK_EVERY && TickBudget::spent( $deadline ) ) {
				return 'partial';
			}

			$target = $to . '/' . $relf;
			wp_mkdir_p( dirname( $target ) );
			if ( ! self::place_file( $from . '/' . $relf, $target ) ) {
				return self::could_not( $target );
			}
		}

		return true;
	}

	/**
	 * Move one file into place without leaving the destination absent: rename, else copy beside and rename.
	 *
	 * @param string $source File to move.
	 * @param string $target Destination.
	 * @return bool
	 */
	private static function place_file( $source, $target ) {
		if ( self::move( $source, $target ) ) {
			return true;
		}

		$temp = $target . self::TEMP_SUFFIX;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
		if ( @copy( $source, $temp ) && self::move( $temp, $target ) ) {
			wp_delete_file( $source );
			return true;
		}

		self::discard( $temp );
		return false;
	}

	/**
	 * Delete a tree this class created, in deadline-bounded passes, never following links.
	 *
	 * @param string $path     Folder or file.
	 * @param int    $deadline Unix timestamp to stop at (0 = no limit).
	 * @return true|string True once gone, 'partial' when stopped by the deadline, else why not.
	 */
	public static function remove_tree( $path, $deadline ) {
		$path = self::norm( $path );

		if ( ! self::present( $path ) ) {
			return true;
		}

		if ( is_link( $path ) || ! is_dir( $path ) ) {
			self::delete_entry( $path );
			return self::present( $path ) ? self::could_not( $path ) : true;
		}

		// The directory iterator does not descend into a linked folder, so a link is removed
		// as a link and whatever it points at is never touched.
		$count = 0;
		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				if ( ++$count > 1 && 0 === $count % self::CHECK_EVERY && TickBudget::spent( $deadline ) ) {
					return 'partial';
				}
				self::delete_entry( $item->getPathname() );
			}
		} catch ( \UnexpectedValueException $e ) {
			return self::could_not( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a folder this restore created; checked below.
		@rmdir( $path );

		// The pass reached the end, so what is left is stuck rather than out of time.
		return self::present( $path ) ? self::could_not( $path ) : true;
	}

	/**
	 * Where the site's copy of an item went, or '' when it was never moved aside.
	 *
	 * @param array $paths Paths for the operation.
	 * @return string
	 */
	private static function where_aside( array $paths ) {
		if ( self::present( $paths['old'] ) ) {
			return $paths['old'];
		}
		return self::present( $paths['beside'] ) ? $paths['beside'] : '';
	}

	/**
	 * The four places an operation's item can be.
	 *
	 * @param array  $layout Layout.
	 * @param string $rel    Item path relative to the site root.
	 * @return array{live:string,staged:string,old:string,beside:string}
	 */
	private static function paths( array $layout, $rel ) {
		$live = self::norm( $layout['site'] ) . '/' . $rel;

		return array(
			'live'   => $live,
			'staged' => self::norm( $layout['staging'] ) . '/' . $rel,
			'old'    => self::norm( $layout['old'] ) . '/' . $rel,
			'beside' => dirname( $live ) . '/' . wp_basename( self::norm( $layout['old'] ) ) . '-' . wp_basename( $live ),
		);
	}

	/**
	 * Relative paths of every file under a folder, not descending into links or unreadable folders.
	 *
	 * @param string $dir Folder.
	 * @return array<string>
	 */
	private static function files_under( $dir ) {
		$dir = self::norm( $dir );
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) {
					return ! $current->isDir() || ( ! $current->isLink() && $current->isReadable() );
				}
			)
		);

		$files = array();
		$root  = strlen( $dir ) + 1;
		foreach ( $items as $item ) {
			if ( $item->isFile() || $item->isLink() ) {
				$files[] = substr( self::norm( $item->getPathname() ), $root );
			}
		}

		return $files;
	}

	/**
	 * Names in a folder, without our own staging and aside folders.
	 *
	 * @param string $dir Folder.
	 * @return array<string>
	 */
	private static function children( $dir ) {
		$names = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( '' === $name || '.' === $name || '..' === $name ) {
				continue;
			}
			if ( 0 === strpos( $name, self::STAGING_PREFIX ) || 0 === strpos( $name, self::ASIDE_PREFIX ) ) {
				continue;
			}
			$names[] = $name;
		}
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Whether a name is in a list, ignoring case where the filesystem does.
	 *
	 * @param array  $names Names.
	 * @param string $name  Name to find.
	 * @return bool
	 */
	private static function has_name( array $names, $name ) {
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			return in_array( $name, $names, true );
		}
		return in_array( strtolower( $name ), array_map( 'strtolower', $names ), true );
	}

	/**
	 * Whether a path is a kept path, sits inside one, or contains one.
	 *
	 * Checked as given and as resolved, so a symlinked plugins folder still protects the
	 * plugin running this restore.
	 *
	 * @param string $path Absolute path.
	 * @param array  $keep Normalised kept paths.
	 * @return bool
	 */
	private static function is_kept( $path, array $keep ) {
		$forms = array( self::norm( $path ) );
		$real  = realpath( $path );
		if ( false !== $real ) {
			$forms[] = self::norm( $real );
		}

		$fold = ( '\\' === DIRECTORY_SEPARATOR );
		foreach ( $forms as $form ) {
			$a = $fold ? strtolower( $form ) : $form;
			foreach ( $keep as $kept ) {
				$b = $fold ? strtolower( $kept ) : $kept;
				if ( $a === $b || 0 === strpos( $a, $b . '/' ) || 0 === strpos( $b, $a . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Kept paths, in the given and the resolved form.
	 *
	 * @param array $layout Layout.
	 * @return array<string>
	 */
	private static function keep_list( array $layout ) {
		$keep = array();
		foreach ( (array) ( isset( $layout['keep'] ) ? $layout['keep'] : array() ) as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}
			$keep[] = self::norm( $path );
			$real   = realpath( $path );
			if ( false !== $real ) {
				$keep[] = self::norm( $real );
			}
		}
		return array_values( array_unique( $keep ) );
	}

	/**
	 * Whether no backup holds this: a link, which points outside what is packed, or a
	 * folder the packer cannot read.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_unbacked( $path ) {
		return is_link( $path ) || ( is_dir( $path ) && ! is_readable( $path ) );
	}

	/**
	 * Whether a file name has an extension WordPress never needs in uploads.
	 *
	 * @param string $name File name or relative path.
	 * @return bool
	 */
	private static function is_executable( $name ) {
		$extension = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::EXECUTABLE_EXTENSIONS, true );
	}

	/**
	 * An operation.
	 *
	 * @param string $type Operation type.
	 * @param string $rel  Path relative to the site root.
	 * @return array{type:string,rel:string}
	 */
	private static function op( $type, $rel ) {
		return array(
			'type' => $type,
			'rel'  => $rel,
		);
	}

	/**
	 * Relative paths, normalised and without empty entries.
	 *
	 * @param array $rels Paths.
	 * @return array<string>
	 */
	private static function rels( array $rels ) {
		$out = array();
		foreach ( $rels as $rel ) {
			$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
			if ( '' !== $rel ) {
				$out[] = $rel;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function norm( $path ) {
		return rtrim( str_replace( '\\', '/', (string) $path ), '/' );
	}

	/**
	 * Whether anything, including a dangling link, is at a path.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function present( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * The journal a merge keeps for one folder.
	 *
	 * @param array $layout Layout.
	 * @param array $paths  Paths for the operation.
	 * @return string
	 */
	private static function journal_file( array $layout, array $paths ) {
		$dir = self::norm( $layout['old'] ) . '/' . self::JOURNAL_DIR;
		wp_mkdir_p( $dir );
		return $dir . '/' . md5( $paths['live'] ) . '.txt';
	}

	/**
	 * Files a merge recorded bringing in, each once.
	 *
	 * @param array $layout Layout.
	 * @param array $paths  Paths for the operation.
	 * @return array<string>
	 */
	private static function journal_entries( array $layout, array $paths ) {
		$file = self::norm( $layout['old'] ) . '/' . self::JOURNAL_DIR . '/' . md5( $paths['live'] ) . '.txt';
		if ( ! is_file( $file ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this restore's own journal on local disk.
		$lines = explode( "\n", (string) file_get_contents( $file ) );
		return array_values( array_unique( array_filter( $lines, 'strlen' ) ) );
	}

	/**
	 * Rename, or across filesystems copy and delete the source.
	 *
	 * @param string $from Source file.
	 * @param string $to   Destination.
	 * @return bool
	 */
	private static function move_or_copy( $from, $to ) {
		if ( self::move( $from, $to ) ) {
			return true;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result is checked.
		if ( is_file( $from ) && @copy( $from, $to ) ) {
			wp_delete_file( $from );
			return true;
		}
		return false;
	}

	/**
	 * Rename, quietly.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @return bool
	 */
	private static function move( $from, $to ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- renames within the site are the whole mechanism; every caller checks the result.
		return @rename( $from, $to );
	}

	/**
	 * Delete one file, link or empty folder.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function delete_entry( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- an emptied folder this restore created.
			@rmdir( $path );
			return;
		}
		if ( is_link( $path ) && is_dir( $path ) && '\\' === DIRECTORY_SEPARATOR ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- a directory link on Windows is removed with rmdir; the target is untouched.
			@rmdir( $path );
			return;
		}
		wp_delete_file( $path );
	}

	/**
	 * Remove a temporary file if one was left.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function discard( $path ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * The error for a path that could not be moved.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function could_not( $path ) {
		/* translators: %s: a file or folder path on the site. */
		return sprintf( __( 'Could not move %s during the restore.', 'tailwatch' ), $path );
	}
}
