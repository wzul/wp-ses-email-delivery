<?php
/**
 * AWS SES Webhook Handler for WordPress
 */

// Load WordPress environment
require_once 'wp-load.php';

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
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    if ($count > 100000) {
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
        $wpdb->query("OPTIMIZE TABLE $table_name");
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
