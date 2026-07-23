<?php
/**
 * Hook Controllers
 *
 * Centralized manager for registering and handling WordPress hooks.
 * Prevents duplicate hook registration and provides a unified interface.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Hooks
 */

namespace Tailwatch\Admin\App\Api\Controllers\Hooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class HookControllers
 *
 * Wraps WordPress hook functions (add_action, add_filter, etc.) with
 * additional logic for duplicate prevention.
 *
 */
class HookControllers {

	/**
	 * Track registered hooks to prevent duplicates.
	 *
	 * @var array
	 */
	private static $registered_hooks = array();

	/**
	 * Add an action hook.
	 *
	 * @param string   $hook_name         The name of the action to add the callback to.
	 * @param callable $callback_function The callback to be run when the action is called.
	 * @param int      $priority          Optional. Used to specify the order in which the functions
	 *                                    associated with a particular action are executed. Default 10.
	 * @param int      $accepted_args     Optional. The number of arguments the function accepts. Default 1.
	 *
	 * @return void
	 */
	public function add_action_hook( string $hook_name, callable $callback_function, int $priority = 10, int $accepted_args = 1 ): void {
		$hook_key = $this->generate_hook_key( $hook_name, $callback_function, $priority );

		if ( ! isset( self::$registered_hooks[ $hook_key ] ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
			add_action( $hook_name, $callback_function, $priority, $accepted_args );
			self::$registered_hooks[ $hook_key ] = true;
		}
	}

	/**
	 * Remove an action hook.
	 *
	 * @param string   $hook_name         The action hook to which the function to be removed is hooked.
	 * @param callable $callback_function The callback to be removed.
	 * @param int      $priority          Optional. The priority of the function. Default 10.
	 *
	 * @return void
	 */
	public function remove_action_hook( string $hook_name, callable $callback_function, int $priority = 10 ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
		remove_action( $hook_name, $callback_function, $priority );
	}

	/**
	 * Execute an action hook.
	 *
	 * @param string $hook_name The name of the action to be executed.
	 * @param mixed  ...$args   Optional. Additional arguments which are passed on to the functions hooked to the action.
	 *
	 * @return void
	 */
	public function do_action_hook( string $hook_name, ...$args ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
		do_action( $hook_name, ...$args );
	}

	/**
	 * Add a filter hook.
	 *
	 * @param string   $hook_name         The name of the filter to add the callback to.
	 * @param callable $callback_function The callback to be run when the filter is applied.
	 * @param int      $priority          Optional. Used to specify the order in which the functions
	 *                                    associated with a particular filter are executed. Default 10.
	 * @param int      $accepted_args     Optional. The number of arguments the function accepts. Default 1.
	 *
	 * @return void
	 */
	public function add_filter_hook( string $hook_name, callable $callback_function, int $priority = 10, int $accepted_args = 1 ): void {
		$hook_key = $this->generate_hook_key( $hook_name, $callback_function, $priority );

		if ( ! isset( self::$registered_hooks[ $hook_key ] ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
			add_filter( $hook_name, $callback_function, $priority, $accepted_args );
			self::$registered_hooks[ $hook_key ] = true;
		}
	}

	/**
	 * Remove a filter hook.
	 *
	 * @param string   $hook_name         The filter hook to which the function to be removed is hooked.
	 * @param callable $callback_function The callback to be removed.
	 * @param int      $priority          Optional. The priority of the function. Default 10.
	 *
	 * @return void
	 */
	public function remove_filter_hook( string $hook_name, callable $callback_function, int $priority = 10 ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
		remove_filter( $hook_name, $callback_function, $priority );
	}

	/**
	 * Execute a filter hook.
	 *
	 * @param string $hook_name The name of the filter hook.
	 * @param mixed  $value     The value to filter.
	 * @param mixed  ...$args   Optional. Additional arguments which are passed on to the functions hooked to the filter.
	 *
	 * @return mixed The filtered value.
	 */
	public function apply_filter_hook( string $hook_name, $value, ...$args ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name passed as parameter from calling code
		return apply_filters( $hook_name, $value, ...$args );
	}

	/**
	 * Generate unique hook key.
	 *
	 * @param string   $hook_name         The name of the hook.
	 * @param callable $callback_function The callback function.
	 * @param int      $priority          The priority of the hook.
	 *
	 * @return string Unique key for the hook registration.
	 */
	private function generate_hook_key( string $hook_name, callable $callback_function, int $priority ): string {
		if ( is_array( $callback_function ) ) {
			$class_name  = is_object( $callback_function[0] ) ? get_class( $callback_function[0] ) : $callback_function[0];
			$method_name = $callback_function[1];
			return $hook_name . '::' . $class_name . '::' . $method_name . '::' . $priority;
		}

		if ( is_string( $callback_function ) ) {
			return $hook_name . '::' . $callback_function . '::' . $priority;
		}

		// For closures and other callables - use object hash
		$closure_id = is_object( $callback_function ) ? spl_object_hash( $callback_function ) : 'unknown';
		return $hook_name . '::closure::' . $closure_id . '::' . $priority;
	}
}
