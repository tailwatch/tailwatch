<?php
/**
 * Tailwatch - Base Controller
 *
 * Abstract base controller for API controllers.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Base
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Base;

use Tailwatch\Admin\App\Api\Traits\FeatureEnableTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseController {

	use FeatureEnableTrait;
}
