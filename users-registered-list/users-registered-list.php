<?php
/**
 * Plugin Name:       Users Registration Date
 * Plugin URI:        https://ovirium.com
 * Description:       New sortable "Registered" date column on the Users page in wp-admin area.
 * Author:            Slava Abakumov
 * Author URI:        https://ovirium.com
 * Version:           1.0.1
 * Requires at least: 3.3
 * Requires PHP:      5.6
 *
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 * *
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * *
 * You should have received a copy of the GNU General Public License
 * along with WPForms. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Add Reg column to users list in admin area
 *
 * @since 1.0.0
 *
 * @param array $column The list of columns.
 */
function url_modify_user_table( $column ) {

	$column['reg_date'] = __( 'Registered', 'users-registered-list' );

	return $column;
}

add_filter( 'manage_users_columns', 'url_modify_user_table' );

/**
 * Display an actual value in a table of users.
 *
 * @since 1.0.0
 *
 * @param string $val         Expected default value.
 * @param string $column_name Column name we should check.
 * @param int    $user_id     ID of a user we are checking.
 */
function url_modify_user_table_row( $val, $column_name, $user_id ) {

	$user = get_userdata( $user_id );

	if ( $column_name === 'reg_date' ) {
		$date_format = get_option( 'date_format', true );
		if ( empty( $date_format ) ) {
			$date_format = 'F j, Y';
		}

		$time_format = get_option( 'time_format', true );
		if ( empty( $time_format ) ) {
			$time_format = 'g:i a';
		}

		return date_i18n( $date_format . ' (' . $time_format . ')', strtotime( $user->user_registered ) );
	}

	return $val;
}

add_filter( 'manage_users_custom_column', 'url_modify_user_table_row', 10, 3 );

/**
 * Make the column sortable.
 *
 * @since 1.0.0
 *
 * @param array $sortable The list of sortable columns.
 */
function url_modify_user_table_sortable( $sortable ) {

	$sortable['reg_date'] = 'user_registered';

	return $sortable;
}

add_filter( 'manage_users_sortable_columns', 'url_modify_user_table_sortable' );
