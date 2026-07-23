<?php

namespace Tailwatch\Admin\App\Api\Controllers\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractIntegration {
	protected $feature_controller;
	protected $allowedSchema = array(
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

	abstract public function wptw_get_integration_data();

	abstract public function wptw_update_integration_data( $postData );

	abstract public function wptw_delete_integration_data( $postData );

	public function wptw_validate_integration_data( array $options, bool $partial = true, array $schema = array() ) {
		$errors = array();

		if ( empty( $schema ) ) {
			$schema = $this->allowedSchema;
		}

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array(
				'valid'  => false,
				'errors' => array( 'payload' => 'Payload must be a non-empty object.' ),
			);
		}

		foreach ( $options as $sectionKey => $sectionValue ) {
			if ( ! isset( $schema[ $sectionKey ] ) ) {
				$errors[ $sectionKey ][] = "Unknown integration section: {$sectionKey}";
				continue;
			}

			if ( ! is_array( $sectionValue ) ) {
				$errors[ $sectionKey ][] = "Section {$sectionKey} must be an object";
				continue;
			}

			$required      = $schema[ $sectionKey ]['required'] ?? array();
			$optional      = $schema[ $sectionKey ]['optional'] ?? array();
			$allowedFields = array_merge( $required, $optional );

			// If partial update: only check incoming fields are allowed and types; do NOT enforce required presence
			foreach ( $sectionValue as $fieldKey => $fieldValue ) {
				if ( ! in_array( $fieldKey, $allowedFields, true ) ) {
					$errors[ $sectionKey ][] = "Unknown field '{$fieldKey}' (allowed: " . implode( ',', $allowedFields ) . ')';
					continue;
				}
				// type checks if schema defines types
				if ( ! empty( $schema[ $sectionKey ]['types'][ $fieldKey ] ?? '' ) ) {
					$expected = $schema[ $sectionKey ]['types'][ $fieldKey ];
					$ok       = true;
					switch ( $expected ) {
						case 'string':
							$ok = is_string( $fieldValue );
							break;
						case 'int':
							$ok = is_int( $fieldValue ) || ctype_digit( (string) $fieldValue );
							break;
						case 'bool':
							$ok = is_bool( $fieldValue ) || in_array( $fieldValue, array( 0, 1, '0', '1' ), true );
							break;
						case 'array':
							$ok = is_array( $fieldValue );
							break;
					}
					if ( ! $ok ) {
						$errors[ $sectionKey ][] = "Field '{$fieldKey}' must be of type {$expected}";
					}
				}
			}

			// If not partial, enforce required presence
			if ( ! $partial ) {
				foreach ( $required as $rf ) {
					if ( ! array_key_exists( $rf, $sectionValue ) || $sectionValue[ $rf ] === '' || $sectionValue[ $rf ] === null ) {
						$errors[ $sectionKey ][] = "Missing required field '{$rf}'";
					}
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	public function wptw_merge_and_validate( array $incoming, ?array $existing = null, array $schema = array() ) {
		$existing = $existing ?: array();
		// deep merge: existing keys preserved unless replaced by incoming
		$merged = $existing;

		foreach ( $incoming as $section => $vals ) {
			if ( ! is_array( $vals ) ) {
				continue;
			}
			$isFirstTimeSection = ! isset( $merged[ $section ] ) || ! is_array( $merged[ $section ] );

			if ( $isFirstTimeSection ) {
				// Apply defaults only on first creation of the section
				$sectionDefaults = array();
				$effectiveSchema = empty( $schema ) ? ( $this->allowedSchema[ $section ] ?? null ) : ( $schema[ $section ] ?? null );
				if ( is_array( $effectiveSchema ) && isset( $effectiveSchema['defaults'] ) && is_array( $effectiveSchema['defaults'] ) ) {
					$sectionDefaults = $effectiveSchema['defaults'];
				}
				$merged[ $section ] = array_replace( $sectionDefaults, $vals );
			} else {
				// Existing section: do not override existing backend fields with defaults
				$merged[ $section ] = array_replace( $merged[ $section ], $vals );
			}
		}

		// Validate merged result fully (not partial)
		$validation = $this->wptw_validate_integration_data( $merged, false, $schema );
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
