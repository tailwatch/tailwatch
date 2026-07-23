<?php
/**
 * Pro Plugin Verify Controller (Free)
 *
 * Single extension hook the free plugin uses to ask "is an extension
 * add-on loaded?" via the `wptw_is_premium_plugin_active` filter. The
 * free plugin itself never inspects the filesystem for any add-on
 * package. The filter default is `false`, so the answer is always
 * `false` when no add-on is hooking in.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Verification
 */

namespace Tailwatch\Admin\App\Api\Controllers\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPluginVerifyController
 *
 */
class ProPluginVerifyController {

	/**
	 * Whether an add-on listener is hooked to the extension filter.
	 *
	 * The free plugin defines `wptw_is_premium_plugin_active` as an
	 * extension point with a default of `false`. Any add-on that wants
	 * to identify itself hooks `__return_true`. No filesystem or
	 * `is_plugin_active()` inspection is performed here.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) apply_filters( 'wptw_is_premium_plugin_active', false );
	}
}
