<?php
/**
 * Plugin Name: Email Responses Tracker
 * Plugin URI:  https://wanzul.net
 * Description: Tracks and stores email responses including bounces, complaints, and delivery notifications.
 * Version:     1.0.0
 * Author:      Wan Zulkarnain
 * Author URI:  https://wanzul.net
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

class SES_Email_Tracker {

	public function __construct() {
		// Register admin menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Register styles and scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	// Add admin menu item
	public function add_admin_menu() {
		add_menu_page(
			'SES Email Tracker',
			'Email Logs',
			'manage_options',
			'ses-email-tracker',
			array( $this, 'render_admin_page' ),
			'dashicons-email-alt',
			30
		);
	}

	// Enqueue admin CSS and JS
	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'toplevel_page_ses-email-tracker' ) {
			return;
		}

		wp_enqueue_style(
			'ses-email-tracker-css',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/admin.css' )
		);

		wp_enqueue_script(
			'ses-email-tracker-js',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array( 'jquery' ),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/admin.js' ),
			true
		);
	}

	// Render the admin page
	public function render_admin_page() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'email_responses';

		// Handle filters
		$per_page     = isset( $_GET['per_page'] ) ? intval( $_GET['per_page'] ) : 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		// Base query
		$query       = "SELECT * FROM $table_name";
		$count_query = "SELECT COUNT(*) FROM $table_name";

		// Add where clauses based on filters
		$where        = array();
		$query_params = array();

		if ( ! empty( $_GET['notification_type'] ) ) {
			$where[]        = 'notification_type = %s';
			$query_params[] = sanitize_text_field( $_GET['notification_type'] );
		}

		if ( ! empty( $_GET['email'] ) ) {
			$where[]        = 'email_address LIKE %s';
			$query_params[] = '%' . $wpdb->esc_like( sanitize_email( $_GET['email'] ) ) . '%';
		}

		if ( ! empty( $_GET['date_from'] ) ) {
			$where[]        = 'created_at >= %s';
			$query_params[] = sanitize_text_field( $_GET['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $_GET['date_to'] ) ) {
			$where[]        = 'created_at <= %s';
			$query_params[] = sanitize_text_field( $_GET['date_to'] ) . ' 23:59:59';
		}

		// Complete queries
		if ( ! empty( $where ) ) {
			$where_clause = ' WHERE ' . implode( ' AND ', $where );
			$query       .= $where_clause;
			$count_query .= $where_clause;
		}

		// Order and limit
		$query         .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$query_params[] = $per_page;
		$query_params[] = $offset;

		// Prepare and run queries
		if ( ! empty( $query_params ) ) {
			$query       = $wpdb->prepare( $query, $query_params );
			$count_query = $wpdb->prepare( $count_query, array_slice( $query_params, 0, -2 ) );
		}

		$items       = $wpdb->get_results( $query );
		$total_items = $wpdb->get_var( $count_query );
		$total_pages = ceil( $total_items / $per_page );

		// Get unique notification types for filter dropdown
		$notification_types = $wpdb->get_col(
			"SELECT DISTINCT notification_type FROM $table_name ORDER BY notification_type"
		);

		// Display the page
		?>
		<div class="wrap">
			<h1>SES Email Tracker</h1>
			
			<div class="ses-filters">
				<form method="get" action="<?php echo admin_url( 'admin.php' ); ?>">
					<input type="hidden" name="page" value="ses-email-tracker">
					
					<div class="filter-row">
						<div class="filter-group">
							<label for="notification_type">Notification Type:</label>
							<select name="notification_type" id="notification_type">
								<option value="">All Types</option>
								<?php foreach ( $notification_types as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, $_GET['notification_type'] ?? '' ); ?>>
										<?php echo esc_html( ucfirst( $type ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						
						<div class="filter-group">
							<label for="email">Email Address:</label>
							<input type="email" name="email" id="email" 
									value="<?php echo esc_attr( $_GET['email'] ?? '' ); ?>" 
									placeholder="Search by email">
						</div>
					</div>
					
					<div class="filter-row">
						<div class="filter-group">
							<label for="date_from">Date From:</label>
							<input type="date" name="date_from" id="date_from" 
									value="<?php echo esc_attr( $_GET['date_from'] ?? '' ); ?>">
						</div>
						
						<div class="filter-group">
							<label for="date_to">Date To:</label>
							<input type="date" name="date_to" id="date_to" 
									value="<?php echo esc_attr( $_GET['date_to'] ?? '' ); ?>">
						</div>
						
						<div class="filter-group">
							<label for="per_page">Items per page:</label>
							<select name="per_page" id="per_page">
								<option value="10" <?php selected( 10, $per_page ); ?>>10</option>
								<option value="20" <?php selected( 20, $per_page ); ?>>20</option>
								<option value="50" <?php selected( 50, $per_page ); ?>>50</option>
								<option value="100" <?php selected( 100, $per_page ); ?>>100</option>
							</select>
						</div>
						
						<div class="filter-group">
							<button type="submit" class="button button-primary">Filter</button>
							<a href="<?php echo admin_url( 'admin.php?page=ses-email-tracker' ); ?>" class="button">Reset</a>
						</div>
					</div>
				</form>
			</div>
			
			<div class="ses-results">
				<div class="tablenav top">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							)
						);
						?>
					</div>
				</div>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>Message ID</th>
							<th>Type</th>
							<th>Email Address</th>
							<th>Status</th>
							<th>Timestamp</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr>
								<td colspan="7">No records found</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item->id ); ?></td>
									<td><?php echo esc_html( $item->message_id ); ?></td>
									<td><?php echo esc_html( ucfirst( $item->notification_type ) ); ?></td>
									<td><?php echo esc_html( $item->email_address ); ?></td>
									<td>
										<?php if ( $item->notification_type === 'Bounce' ) : ?>
											<?php echo esc_html( $item->bounce_type . '/' . $item->bounce_subtype ); ?>
										<?php elseif ( $item->notification_type === 'Complaint' ) : ?>
											<?php echo esc_html( $item->complaint_type ); ?>
										<?php elseif ( $item->notification_type === 'Delivery' ) : ?>
											Delivered
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( date( 'Y-m-d H:i:s', strtotime( $item->timestamp ) ) ); ?></td>
									<td>
										<button class="button view-details" 
												data-id="<?php echo esc_attr( $item->id ); ?>">
											View Details
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
								'total'     => $total_pages,
								'current'   => $current_page,
							)
						);
						?>
					</div>
				</div>
			</div>
			
			<!-- Modal for details -->
			<div id="ses-details-modal" class="ses-modal" style="display:none;">
				<div class="ses-modal-content">
					<div class="ses-modal-header">
						<h2>Notification Details</h2>
						<button class="ses-modal-close">&times;</button>
					</div>
					<div class="ses-modal-body">
						<pre id="ses-details-content"></pre>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

new SES_Email_Tracker();

// AJAX handler for notification details
add_action( 'wp_ajax_ses_get_notification_details', 'ses_get_notification_details' );
function ses_get_notification_details() {
	check_ajax_referer( 'ses_tracker_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	if ( empty( $_POST['id'] ) ) {
		wp_send_json_error( 'ID required' );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'email_responses';
	$id         = intval( $_POST['id'] );

	$item = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE id = %d",
			$id
		)
	);

	if ( ! $item ) {
		wp_send_json_error( 'Record not found' );
	}

	// Format the raw data for display
	$raw_data = maybe_unserialize( $item->raw_data );
	if ( is_string( $raw_data ) ) {
		$raw_data = json_decode( $raw_data, true );
	}

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		$raw_data = $item->raw_data;
	}

	ob_start();
	echo '<h3>Basic Information</h3>';
	echo '<table class="widefat">';
	echo '<tr><th>Field</th><th>Value</th></tr>';
	echo '<tr><td>Notification Type</td><td>' . esc_html( $item->notification_type ) . '</td></tr>';
	echo '<tr><td>Email Address</td><td>' . esc_html( $item->email_address ) . '</td></tr>';
	echo '<tr><td>Message ID</td><td>' . esc_html( $item->message_id ) . '</td></tr>';
	echo '<tr><td>Timestamp</td><td>' . esc_html( $item->timestamp ) . '</td></tr>';
	echo '</table>';

	echo '<h3>Raw Data</h3>';
	echo '<pre>' . esc_html( print_r( $raw_data, true ) ) . '</pre>';

	wp_send_json_success( ob_get_clean() );
}

// Localize script with nonce
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( $hook === 'toplevel_page_ses-email-tracker' ) {
			wp_localize_script(
				'ses-email-tracker-js',
				'ses_tracker',
				array(
					'nonce' => wp_create_nonce( 'ses_tracker_nonce' ),
				)
			);
		}
	}
);

add_action(
	'init',
	function () {
		// Only process if it's the webhook URL.
		if ( isset( $_GET['ses_webhook'] ) ) {
			ses_process_webhook();
		}
	}
);

/**
 * Process SES/SNS webhook payload.
 */
function ses_process_webhook() {
	// Verify this is a POST request
	if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
		http_response_code( 405 );
		exit( 'Method not allowed' );
	}

	// Get the raw POST data
	$data    = file_get_contents( 'php://input' );
	$headers = getallheaders();

	// Verify this is an SNS message
	if ( ! isset( $headers['X-Amz-Sns-Message-Type'] ) ) {
		http_response_code( 400 );
		exit( 'Invalid SNS message' );
	}

	// Decode the JSON message
	$message = json_decode( $data, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		http_response_code( 400 );
		exit( 'Invalid JSON' );
	}

	// Handle SNS subscription confirmation
	if ( $headers['X-Amz-Sns-Message-Type'] === 'SubscriptionConfirmation' ) {
		$subscribe_url = $message['SubscribeURL'];
		file_get_contents( $subscribe_url );
		http_response_code( 200 );
		exit( 'Subscription confirmed' );
	}

	function verify_sns_message( $message ) {
		if ( ! isset( $message['SigningCertURL'] ) || ! isset( $message['Signature'] ) ) {
			return false;
		}

		// Validate certificate URL
		$cert_url = parse_url( $message['SigningCertURL'] );
		// More flexible host verification - any amazonaws.com subdomain
		if ( ! preg_match( '/\.amazonaws\.com$/i', $cert_url['host'] ) ) {
			return false;
		}

		// Verify the path ends with .pem
		if ( ! preg_match( '/\.pem$/i', $cert_url['path'] ) ) {
			return false;
		}

		// Verify it's using HTTPS
		if ( strtolower( $cert_url['scheme'] ) !== 'https' ) {
			return false;
		}

		// Download certificate (with safety checks)
		$context = stream_context_create(
			array(
				'ssl'  => array(
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'allow_self_signed' => false,
				),
				'http' => array(
					'timeout'       => 2, // 2 second timeout
					'ignore_errors' => true,
				),
			)
		);

		$cert = @file_get_contents( $message['SigningCertURL'], false, $context );
		if ( ! $cert ) {
			return false;
		}

		// Get the public key
		$public_key = openssl_get_publickey( $cert );
		if ( ! $public_key ) {
			return false;
		}

		// Build the signed string
		$fields = array(
			'Message',
			'MessageId',
			'Subject',
			'SubscribeURL',
			'Timestamp',
			'Token',
			'TopicArn',
			'Type',
		);

		$signed_string = '';
		foreach ( $fields as $field ) {
			if ( isset( $message[ $field ] ) ) {
				$signed_string .= $field . "\n" . $message[ $field ] . "\n";
			}
		}

		// Verify the signature
		$result = openssl_verify(
			$signed_string,
			base64_decode( $message['Signature'] ),
			$public_key,
			OPENSSL_ALGO_SHA1
		);

		openssl_free_key( $public_key );

		return $result === 1;
	}

	/**
	 * Maintains the email_responses table by removing oldest records if count exceeds 100,000
	 */
	function maintain_email_responses_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'email_responses';

		// Check current row count
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		if ( $count > 100000 ) {
			// Calculate how many to delete (keeping exactly 100,000)
			$to_delete = $count - 100000;

			// Delete oldest records
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table_name 
                 ORDER BY created_at ASC 
                 LIMIT %d",
					$to_delete
				)
			);

			// Optional: Optimize table after deletion
			$wpdb->query( "OPTIMIZE TABLE $table_name" );
		}
	}

	if ( ! verify_sns_message( $message ) ) {
		exit( 'SNS message verified' );
	}

	// Process the notification
	if ( $headers['X-Amz-Sns-Message-Type'] === 'Notification' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'email_responses';

		$notification = json_decode( $message['Message'], true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			http_response_code( 400 );
			exit( 'Invalid notification format' );
		}

		// Extract common data
		$message_id        = $notification['mail']['messageId'] ?? '';
		$notification_type = $notification['eventType'] ?? '';
		$timestamp         = date( 'Y-m-d H:i:s', strtotime( $notification['mail']['timestamp'] ?? 'now' ) );

		// Prepare data for insertion
		$insert_data = array(
			'message_id'        => $message_id,
			'notification_type' => $notification_type,
			'timestamp'         => $timestamp,
			'raw_data'          => $message['Message'],
			'created_at'        => current_time( 'mysql' ),
		);

		// Handle different notification types
		switch ( $notification_type ) {
			case 'Bounce':
				$bounce                        = $notification['bounce'];
				$insert_data['bounce_type']    = $bounce['bounceType'] ?? '';
				$insert_data['bounce_subtype'] = $bounce['bounceSubType'] ?? '';

				// Get the first bounced recipient
				if ( ! empty( $bounce['bouncedRecipients'] ) ) {
					$recipient                      = $bounce['bouncedRecipients'][0];
					$insert_data['email_address']   = $recipient['emailAddress'] ?? '';
					$insert_data['status']          = $recipient['status'] ?? '';
					$insert_data['diagnostic_code'] = $recipient['diagnosticCode'] ?? '';
				}
				break;

			case 'Complaint':
				$complaint                     = $notification['complaint'];
				$insert_data['complaint_type'] = $complaint['complaintFeedbackType'] ?? '';

				// Get the first complained recipient
				if ( ! empty( $complaint['complainedRecipients'] ) ) {
					$insert_data['email_address'] = $complaint['complainedRecipients'][0]['emailAddress'] ?? '';
				}
				break;

			case 'Delivery':
				$delivery = $notification['delivery'];

				// Get the first delivered recipient
				if ( ! empty( $delivery['recipients'] ) ) {
					$insert_data['email_address'] = $delivery['recipients'][0] ?? '';
				}
				break;

			case 'Reject':
				$reject = $notification['reject'];

				// Set basic rejection info
				$insert_data['status']          = 'Rejected';
				$insert_data['diagnostic_code'] = $reject['reason'] ?? 'Unknown rejection reason';

				// If available, include the original message details
				if ( isset( $notification['mail'] ) ) {
					$insert_data['message_id'] = $notification['mail']['messageId'] ?? '';

					// Get the first recipient if available
					if ( ! empty( $notification['mail']['destination'] ) ) {
						$insert_data['email_address'] = $notification['mail']['destination'][0] ?? '';
					}
				}
				break;

			default:
				// Unknown notification type
				http_response_code( 400 );
				exit( 'Unknown notification type' );
		}

		// Insert into database
		maintain_email_responses_table();
		$result = $wpdb->insert( $table_name, $insert_data );

		if ( $result === false ) {
			error_log( 'Failed to insert SES notification: ' . $wpdb->last_error );
			http_response_code( 500 );
			exit( 'Database error' );
		}

		http_response_code( 200 );
		exit( 'Notification processed' );
	}

	http_response_code( 400 );
	exit( 'Invalid request' );
}
