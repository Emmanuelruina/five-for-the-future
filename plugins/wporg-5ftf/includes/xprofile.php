<?php
namespace WordPressDotOrg\FiveForTheFuture\XProfile;

use WordPressDotOrg\FiveForTheFuture\Contributor;
use WPDB;

/*
 * The IDs of the xprofile fields we need. Better to use the numerical IDs than the field labels,
 * because those are more likely to change.
 */
const FIELD_IDS = array(
	'sponsored'      => 24,
	'hours_per_week' => 29,
	'team_names'     => 30,
);

defined( 'WPINC' ) || die();

/**
 * Get the xprofile `hours_per_week` and `team_names` for all contributors, regardless of sponsorship status.
 *
 * The "Sponsored" field is not retrieved because it's usually not needed, and including it would significantly
 * hurt performance.
 */
function get_all_xprofile_contributor_hours_teams(): array {
	global $wpdb;

	// This might need a `LIMIT` in the future as more users save values, but it's performant as of August 2022.
	// `LIMIT`ing it would require batch processing, which would add a significant amount of complexity.
	// A better alternative might be to add a cron job to delete rows from `bpmain_bp_xprofile_data` where
	// `hours_per_week` is < 1, or `teams_names` is a (serialized) empty array. BuddyPress saves those as
	// values rather than deleting them, and that significantly increases the number of rows returned.
	$users = $wpdb->get_results( $wpdb->prepare( '
		SELECT user_id, GROUP_CONCAT( field_id ) AS field_ids, GROUP_CONCAT( value ) AS field_values
		FROM `bpmain_bp_xprofile_data`
		WHERE field_id IN ( %d, %d )
		GROUP BY user_id',
		FIELD_IDS['hours_per_week'],
		FIELD_IDS['team_names']
	) );

	$field_names = array_flip( FIELD_IDS );

	foreach ( $users as $user_index => & $user ) {
		$fields = explode( ',', $user->field_ids );
		$values = explode( ',', $user->field_values );

		foreach ( $fields as $field_index => $id ) {
			/*
			 * BuddyPress validates the team name(s) the user provides before saving them in the database, so
			 * it should be safe to unserialize, and to assume that they're valid.
			 *
			 * The database stores team _names_ rather than _IDs_, though, so if a team is ever renamed, this
			 * data will be distorted.
			 */
			$user->{$field_names[ $id ]} = maybe_unserialize( $values[ $field_index ] );
		}
		unset( $user->field_ids, $user->field_values ); // Remove the concatenated data now that it's exploded.

		$user->user_id        = absint( $user->user_id );
		$user->hours_per_week = absint( $user->hours_per_week ?? 0 );
		$user->team_names     = (array) ( $user->team_names ?? array() );

		if ( 0 >= $user->hours_per_week || empty( $user->team_names ) ) {
			unset( $users[ $user_index ] );
		}
	}

	return $users;
}

/**
 *
 * Reconfigures xprofile data to be in indexed array.
 *
 * @return array
 */
function get_all_xprofile_contributors_indexed(): array {
	$all_data = get_all_xprofile_contributor_hours_teams();

	$newdata = array();
	foreach ( $all_data as $contributor ) {
		$newdata[ $contributor->user_id ] = [
			'hours_per_week' => $contributor->hours_per_week,
			'team_names' => $contributor->team_names,
		];
	}

	return $newdata;
}

/**
 * Pull relevant data from profiles.wordpress.org.
 *
 * Note that this does not unserialize anything, it just pulls the raw values from the database table. If you
 * want unserialized data, use `prepare_xprofile_contribution_data()`.
 *
 * @global WPDB $wpdb
 *
 * @param array $user_ids
 *
 * @return array
 */
function get_xprofile_contribution_data( array $user_ids ) {
	global $wpdb;

	if ( empty( $user_ids ) ) {
		return array();
	}

	$sql = $wpdb->prepare( '
		SELECT user_id, field_id, value
		FROM bpmain_bp_xprofile_data
		WHERE user_id IN ( %1$s )
		AND field_id IN ( %2$s )',
		implode( ', ', array_map( 'absint', $user_ids ) ),
		implode( ', ', array_map( 'absint', array_values( FIELD_IDS ) ) )
	);

	return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- prepare called above.
}

/**
 * Reindex `bpmain_bp_xprofile_data` rows by user ID, normalize it, and format it.
 *
 * This makes the data much easier to work with in many cases.
 *
 * @param array $raw_data
 *
 * @return array
 */
function prepare_xprofile_contribution_data( array $raw_data ) {
	$prepared_data    = array();
	$field_keys_by_id = array_flip( FIELD_IDS );

	foreach ( $raw_data as $datum ) {
		$user_id                              = $datum['user_id'];
		$prepared_data[ $user_id ]['user_id'] = $user_id;
		$field_key                            = $field_keys_by_id[ (int) $datum['field_id'] ];
		$field_value                          = maybe_unserialize( $datum['value'] );

		if ( ! isset( $prepared_data[ $user_id ]['sponsored'] ) ) {
			$prepared_data[ $user_id ]['sponsored'] = false;
		}

		if ( 'sponsored' === $field_key ) {
			$prepared_data[ $user_id ]['sponsored'] = 'Yes' === $field_value;
		} else {
			$prepared_data[ $user_id ][ $field_key ] = $field_value;
		}
	}

	return $prepared_data;
}

/**
 * Aggregate the raw xprofile data for all contributors linked to a given pledge.
 *
 * @param int $pledge_id
 *
 * @return array
 */
function get_aggregate_contributor_data_for_pledge( $pledge_id ) {
	$contributor_posts = Contributor\get_pledge_contributors( $pledge_id, 'publish' );

	// All of their contributors might have declined the invitation and had their posts deleted.
	if ( ! $contributor_posts ) {
		return array(
			'contributors' => 0,
			'hours'        => 0,
			'teams'        => array(),
		);
	}

	$contributor_users = Contributor\get_contributor_user_objects( $contributor_posts );
	$user_ids          = wp_list_pluck( $contributor_users, 'ID' );

	$data = get_xprofile_contribution_data( $user_ids );

	$initial = array(
		'contributors' => count( $user_ids ),
		'hours'        => 0,
		'teams'        => array(),
	);

	$aggregate_data = array_reduce( $data, function ( $carry, $item ) {
		switch ( $item['field_id'] ) {
			case FIELD_IDS['hours_per_week']:
				$carry['hours'] += absint( $item['value'] );
				break;

			case FIELD_IDS['team_names']:
				$value          = (array) maybe_unserialize( $item['value'] );
				$carry['teams'] = array_merge( $carry['teams'], $value );
				break;
		}

		return $carry;
	}, $initial );

	$aggregate_data['teams'] = array_map(
		function ( $team ) {
			// Fix for renamed team.
			if ( 'Theme Review Team' === $team ) {
				$team = 'Themes Team';
			}

			return $team;
		},
		$aggregate_data['teams']
	);
	$aggregate_data['teams'] = array_unique( $aggregate_data['teams'] );
	sort( $aggregate_data['teams'] );

	return $aggregate_data;
}

/**
 * Fetch the profile data for a specific user.
 *
 * @param int $user_id
 *
 * @return array
 */
function get_contributor_user_data( $user_id ) {
	$formatted_data = array();
	$raw_data       = get_xprofile_contribution_data( array( $user_id ) );

	$defaults = array(
		'hours_per_week' => 0,
		'team_names'     => array(),
	);

	foreach ( $raw_data as $datum ) {
		$key = array_search( $datum['field_id'], FIELD_IDS );

		switch ( $key ) {
			case 'hours_per_week':
				$formatted_data[ $key ] = absint( $datum['value'] );
				break;

			case 'team_names':
				$formatted_data[ $key ] = maybe_unserialize( $datum['value'] );
		}
	}

	$formatted_data = array_merge( $defaults, $formatted_data );

	return $formatted_data;
}

/**
 * Reset the 5ftF data on a user's profile.
 *
 * This deletes directly from the database and object cache -- rather than using something like
 * `BP_XProfile_Field::delete()` -- because w.org/5 runs on a different network than profiles.w.org.
 */
function reset_contribution_data( $user_id ): void {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( '
		DELETE FROM `bpmain_bp_xprofile_data`
		WHERE
			user_id = %d AND
			field_id IN ( %d, %d, %d )',
		$user_id,
		FIELD_IDS['sponsored'],
		FIELD_IDS['hours_per_week'],
		FIELD_IDS['team_names'],
	) );

	wp_cache_add_global_groups( 'bp_xprofile_data' );
	wp_cache_delete_multiple(
		array(
			$user_id . ':' . FIELD_IDS['sponsored'],
			$user_id . ':' . FIELD_IDS['hours_per_week'],
			$user_id . ':' . FIELD_IDS['team_names'],
		),
		'bp_xprofile_data'
	);
}
