<?php
namespace Tailwatch\Admin\App\Api\Logging\Processors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UserProcessor implements LogProcessorInterface {

	/**
	 * Process log record and add user context.
	 *
	 * Uses get_userdata() to verify user exists before logging user information.
	 *
	 * @param array $record Log record array.
	 *
	 * @return array Modified log record with user context.
	 */
	public function process( array $record ) {
		$user_id = get_current_user_id();

		$record['extra']['user_id'] = absint( $user_id );

		if ( $user_id > 0 ) {
			// Use get_userdata() which returns false if user doesn't exist.
			$user = get_userdata( $user_id );
			if ( $user && $user instanceof \WP_User ) {
				$record['extra']['user_login'] = sanitize_user( $user->user_login );
				$record['extra']['user_email'] = sanitize_email( $user->user_email );
				$record['extra']['user_roles'] = is_array( $user->roles ) ? array_map( 'sanitize_text_field', $user->roles ) : array();
			}
		}

		return $record;
	}
}
