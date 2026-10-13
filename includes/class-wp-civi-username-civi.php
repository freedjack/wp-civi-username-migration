<?php
/**
 * CiviCRM lookups for username migration (UFMatch + Contact).
 *
 * Uses the CiviCRM API so WP and Civi can live in separate databases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Civi_Username_Civi {

	/**
	 * Whether CiviCRM is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! function_exists( 'civicrm_initialize' ) && ! function_exists( 'civi_wp' ) ) {
			return false;
		}

		try {
			if ( function_exists( 'civi_wp' ) ) {
				civi_wp()->initialize();
			} elseif ( function_exists( 'civicrm_initialize' ) ) {
				civicrm_initialize();
			}
		} catch ( Exception $e ) {
			return false;
		}

		return function_exists( 'civicrm_api3' ) || function_exists( 'civicrm_api4' );
	}

	/**
	 * Batch-fetch Civi contacts for a list of WP user IDs via UFMatch.
	 *
	 * Returns a map of wp_user_id => array{ contact_id, first_name, last_name, contact_type, display_name }.
	 * Missing matches are omitted from the map.
	 *
	 * @param int[] $wp_user_ids WordPress user IDs.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_contacts_for_users( array $wp_user_ids ) {
		$wp_user_ids = array_values( array_filter( array_map( 'intval', $wp_user_ids ) ) );
		if ( empty( $wp_user_ids ) ) {
			return array();
		}

		if ( ! $this->is_available() ) {
			return array();
		}

		$uf_to_contact = $this->get_uf_matches( $wp_user_ids );
		if ( empty( $uf_to_contact ) ) {
			return array();
		}

		$contact_ids = array_values( array_unique( array_map( 'intval', $uf_to_contact ) ) );
		$contacts    = $this->get_contacts( $contact_ids );

		$result = array();
		foreach ( $uf_to_contact as $uf_id => $contact_id ) {
			$contact_id = (int) $contact_id;
			if ( isset( $contacts[ $contact_id ] ) ) {
				$result[ (int) $uf_id ] = $contacts[ $contact_id ];
			} else {
				$result[ (int) $uf_id ] = array(
					'contact_id'   => $contact_id,
					'first_name'   => '',
					'last_name'    => '',
					'contact_type' => '',
					'display_name' => '',
				);
			}
		}

		return $result;
	}

	/**
	 * Fetch UFMatch rows for the given WP user IDs.
	 *
	 * @param int[] $wp_user_ids WP user IDs (uf_id).
	 * @return array<int, int> Map of uf_id => contact_id.
	 */
	private function get_uf_matches( array $wp_user_ids ) {
		$map = array();

		try {
			if ( function_exists( 'civicrm_api4' ) ) {
				$chunks = array_chunk( $wp_user_ids, 200 );
				foreach ( $chunks as $chunk ) {
					$rows = civicrm_api4(
						'UFMatch',
						'get',
						array(
							'select' => array( 'uf_id', 'contact_id' ),
							'where'  => array(
								array( 'uf_id', 'IN', $chunk ),
							),
							'limit'  => count( $chunk ) + 10,
							'checkPermissions' => false,
						)
					);
					foreach ( $rows as $row ) {
						if ( ! empty( $row['uf_id'] ) && ! empty( $row['contact_id'] ) ) {
							$map[ (int) $row['uf_id'] ] = (int) $row['contact_id'];
						}
					}
				}
				return $map;
			}

			// API3 fallback.
			$chunks = array_chunk( $wp_user_ids, 200 );
			foreach ( $chunks as $chunk ) {
				$result = civicrm_api3(
					'UFMatch',
					'get',
					array(
						'uf_id'       => array( 'IN' => $chunk ),
						'return'      => array( 'uf_id', 'contact_id' ),
						'options'     => array( 'limit' => count( $chunk ) + 10 ),
						'sequential'  => 1,
					)
				);
				if ( ! empty( $result['values'] ) ) {
					foreach ( $result['values'] as $row ) {
						if ( ! empty( $row['uf_id'] ) && ! empty( $row['contact_id'] ) ) {
							$map[ (int) $row['uf_id'] ] = (int) $row['contact_id'];
						}
					}
				}
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::warning( 'CiviCRM UFMatch lookup failed: ' . $e->getMessage() );
			}
		}

		return $map;
	}

	/**
	 * Fetch contact name fields for the given contact IDs.
	 *
	 * @param int[] $contact_ids Civi contact IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_contacts( array $contact_ids ) {
		$contacts = array();

		try {
			if ( function_exists( 'civicrm_api4' ) ) {
				$chunks = array_chunk( $contact_ids, 200 );
				foreach ( $chunks as $chunk ) {
					$rows = civicrm_api4(
						'Contact',
						'get',
						array(
							'select' => array( 'id', 'first_name', 'last_name', 'contact_type', 'display_name', 'is_deleted' ),
							'where'  => array(
								array( 'id', 'IN', $chunk ),
								array( 'is_deleted', '=', false ),
							),
							'limit'  => count( $chunk ) + 10,
							'checkPermissions' => false,
						)
					);
					foreach ( $rows as $row ) {
						$contacts[ (int) $row['id'] ] = array(
							'contact_id'   => (int) $row['id'],
							'first_name'   => isset( $row['first_name'] ) ? (string) $row['first_name'] : '',
							'last_name'    => isset( $row['last_name'] ) ? (string) $row['last_name'] : '',
							'contact_type' => isset( $row['contact_type'] ) ? (string) $row['contact_type'] : '',
							'display_name' => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
						);
					}
				}
				return $contacts;
			}

			$chunks = array_chunk( $contact_ids, 200 );
			foreach ( $chunks as $chunk ) {
				$result = civicrm_api3(
					'Contact',
					'get',
					array(
						'id'         => array( 'IN' => $chunk ),
						'is_deleted' => 0,
						'return'     => array( 'id', 'first_name', 'last_name', 'contact_type', 'display_name' ),
						'options'    => array( 'limit' => count( $chunk ) + 10 ),
						'sequential' => 1,
					)
				);
				if ( ! empty( $result['values'] ) ) {
					foreach ( $result['values'] as $row ) {
						$contacts[ (int) $row['id'] ] = array(
							'contact_id'   => (int) $row['id'],
							'first_name'   => isset( $row['first_name'] ) ? (string) $row['first_name'] : '',
							'last_name'    => isset( $row['last_name'] ) ? (string) $row['last_name'] : '',
							'contact_type' => isset( $row['contact_type'] ) ? (string) $row['contact_type'] : '',
							'display_name' => isset( $row['display_name'] ) ? (string) $row['display_name'] : '',
						);
					}
				}
			}
		} catch ( Exception $e ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::warning( 'CiviCRM Contact lookup failed: ' . $e->getMessage() );
			}
		}

		return $contacts;
	}

	/**
	 * Whether a contact has usable individual name data for username generation.
	 *
	 * @param array<string, mixed>|null $contact Contact row.
	 * @return bool
	 */
	public function has_usable_name( $contact ) {
		if ( empty( $contact ) || ! is_array( $contact ) ) {
			return false;
		}

		$type = isset( $contact['contact_type'] ) ? $contact['contact_type'] : '';
		if ( $type && 'Individual' !== $type ) {
			return false;
		}

		$first = isset( $contact['first_name'] ) ? trim( (string) $contact['first_name'] ) : '';
		$last  = isset( $contact['last_name'] ) ? trim( (string) $contact['last_name'] ) : '';

		if ( '' === $first || '' === $last ) {
			return false;
		}

		if ( WP_Civi_Username_Generator::looks_like_email( $first ) || WP_Civi_Username_Generator::looks_like_email( $last ) ) {
			return false;
		}

		return true;
	}
}
