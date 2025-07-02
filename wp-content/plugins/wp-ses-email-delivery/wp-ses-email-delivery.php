<?php
/**
 * Plugin Name: Email Responses Tracker
 * Plugin URI:  https://yourwebsite.com/email-responses-tracker
 * Description: Tracks and stores email responses including bounces, complaints, and delivery notifications.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://yourwebsite.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: email-responses-tracker
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

function create_email_responses_table() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'email_responses';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        message_id varchar(255) NOT NULL,
        notification_type varchar(50) NOT NULL,
        email_address varchar(255) NOT NULL,
        status varchar(50),
        bounce_type varchar(50),
        bounce_subtype varchar(50),
        complaint_type varchar(50),
        diagnostic_code text,
        timestamp datetime NOT NULL,
        raw_data longtext NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY message_id (message_id),
        KEY email_address (email_address)
    ) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'create_email_responses_table' );
