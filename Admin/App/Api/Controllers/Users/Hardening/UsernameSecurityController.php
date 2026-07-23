<?php
namespace Tailwatch\Admin\App\Api\Controllers\Users\Hardening;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UsernameSecurityController {

	/**
	 * Get list of blocked usernames.
	 *
	 * @return string[]
	 */
	public static function get_blocked_usernames() {
		return array(
			'admin',
			'administrator',
			'root',
			'test',
			'user',
			'demo',
			'webmaster',
			'support',
			'contact',
			'info',
			'help',
			'service',
			'guest',
			'public',
			'system',
			'operator',
			'superuser',
			'mod',
			'moderator',
			'manager',
			'owner',
			'master',
		);
	}
}