<?php
/**
 * Abstract Integration
 *
 * Schema, validation, and merge helpers for third-party integration settings
 * (currently the MaxMind GeoLite2 integration) stored in the plugin settings table.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Integration
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractIntegration {

	/**
	 * @var mixed Data-access model.
	 */
	protected $feature_controller;

	/**
	 * Allowed integration sections, their fields, types, and defaults.
	 *
	 * @var array
	 */
	protected $allowed_schema = array(
		'maxmind' => array(
			'required' => array( 'license_key' ),
			'optional' => array( 'is_connected', 'retry_attempts', 'previous_key', 'message', 'db_md5', 'db_date' ),
			'types'    => array(
				'license_key'    => 'string',
				'retry_attempts' => 'int',
				'message'        => 'string',
				'is_connected'   => 'bool',
				'previous_key'   => 'string',
				'db_md5'         => 'string',
				'db_date'        => 'string',
			),
			'defaults' => array(
				'is_connected'   => false,
				'retry_attempts' => 0,
				'message'        => '',
				'previous_key'   => '',
				'db_md5'         => '',
				'db_date'        => '',
			),
		),
	);

	abstract public function tailwatch_get_integration_data( $section = null );

	abstract public function tailwatch_update_integration_data( $post_data );

	abstract public function tailwatch_delete_integration_data( $post_data );

	/**
	 * Validate an integration payload against the schema.
	 *
	 * @param array $options Incoming section => fields map.
	 * @param bool  $partial When true, required fields need not be present.
	 * @param array $schema  Optional schema override.
	 * @return array{valid:bool,errors:array}
	 */
	public function tailwatch_validate_integration_data( array $options, bool $partial = true, array $schema = array() ) {
		$errors = array();

		if ( empty( $schema ) ) {
			$schema = $this->allowed_schema;
		}

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array(
				'valid'  => false,
				'errors' => array( 'payload' => 'Payload must be a non-empty object.' ),
			);
		}

		foreach ( $options as $section_key => $section_value ) {
			if ( ! isset( $schema[ $section_key ] ) ) {
				$errors[ $section_key ][] = "Unknown integration section: {$section_key}";
				continue;
			}

			if ( ! is_array( $section_value ) ) {
				$errors[ $section_key ][] = "Section {$section_key} must be an object";
				continue;
			}

			$required       = $schema[ $section_key ]['required'] ?? array();
			$optional       = $schema[ $section_key ]['optional'] ?? array();
			$allowed_fields = array_merge( $required, $optional );

			foreach ( $section_value as $field_key => $field_value ) {
				if ( ! in_array( $field_key, $allowed_fields, true ) ) {
					$errors[ $section_key ][] = "Unknown field '{$field_key}' (allowed: " . implode( ',', $allowed_fields ) . ')';
					continue;
				}
				if ( ! empty( $schema[ $section_key ]['types'][ $field_key ] ?? '' ) ) {
					$expected = $schema[ $section_key ]['types'][ $field_key ];
					$ok       = true;
					switch ( $expected ) {
						case 'string':
							$ok = is_string( $field_value );
							break;
						case 'int':
							$ok = is_int( $field_value ) || ctype_digit( (string) $field_value );
							break;
						case 'bool':
							$ok = is_bool( $field_value ) || in_array( $field_value, array( 0, 1, '0', '1' ), true );
							break;
						case 'array':
							$ok = is_array( $field_value );
							break;
					}
					if ( ! $ok ) {
						$errors[ $section_key ][] = "Field '{$field_key}' must be of type {$expected}";
					}
				}
			}

			if ( ! $partial ) {
				foreach ( $required as $rf ) {
					if ( ! array_key_exists( $rf, $section_value ) || '' === $section_value[ $rf ] || null === $section_value[ $rf ] ) {
						$errors[ $section_key ][] = "Missing required field '{$rf}'";
					}
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Deep-merge incoming section data over existing data and validate the result.
	 *
	 * @param array      $incoming Incoming section => fields map.
	 * @param array|null $existing Existing stored data.
	 * @param array      $schema   Optional schema override.
	 * @return array{valid:bool,errors:array,merged:array|null}
	 */
	public function tailwatch_merge_and_validate( array $incoming, ?array $existing = null, array $schema = array() ) {
		$existing = $existing ? $existing : array();
		$merged   = $existing;

		foreach ( $incoming as $section => $vals ) {
			if ( ! is_array( $vals ) ) {
				continue;
			}
			$is_first_time_section = ! isset( $merged[ $section ] ) || ! is_array( $merged[ $section ] );

			if ( $is_first_time_section ) {
				$section_defaults = array();
				$effective_schema = empty( $schema ) ? ( $this->allowed_schema[ $section ] ?? null ) : ( $schema[ $section ] ?? null );
				if ( is_array( $effective_schema ) && isset( $effective_schema['defaults'] ) && is_array( $effective_schema['defaults'] ) ) {
					$section_defaults = $effective_schema['defaults'];
				}
				$merged[ $section ] = array_replace( $section_defaults, $vals );
			} else {
				$merged[ $section ] = array_replace( $merged[ $section ], $vals );
			}
		}

		$validation = $this->tailwatch_validate_integration_data( $merged, false, $schema );
		if ( ! $validation['valid'] ) {
			return array(
				'valid'  => false,
				'errors' => $validation['errors'],
				'merged' => null,
			);
		}

		return array(
			'valid'  => true,
			'errors' => array(),
			'merged' => $merged,
		);
	}

	/**
	 * Default value for a schema field type.
	 *
	 * @param string $type Field type.
	 * @return mixed
	 */
	public function get_default_for_type( $type = 'string' ) {
		switch ( $type ) {
			case 'int':
				return 0;
			case 'bool':
				return false;
			case 'array':
				return array();
			case 'string':
				return '';
			default:
				return null;
		}
	}
}
