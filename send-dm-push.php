<?php
/**
 * Plugin Name: Send Push Notification on DM & Saved Search (Voxel Unthrottled)
 * Description: Sends Progressier push notifications for Voxel DMs (only if user is offline) and Saved Search matches (Places, Jobs) using unthrottled events.
 * Version: 2.0 - Added online status check for DMs.
 * Author: ex0rcist88
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// --- Configuration (Shared) ---

/**
 * Retrieves the Progressier API endpoint.
 * @return string
 */
function eh_get_progressier_api_endpoint() {
    // Replace with your actual Progressier Send API endpoint ID
    return 'https://progressier.app/YOUR_PROGRESSIER_ENDPOINT_ID/send'; // Placeholder
}

/**
 * Retrieves the Progressier API Bearer Token securely.
 * @return string The token or empty string if not configured.
 */
function eh_get_progressier_api_token() {
    // Option 1: Define in wp-config.php (RECOMMENDED)
    // define('PROGRESSIER_API_TOKEN', 'YOUR_ACTUAL_TOKEN_DEFINED_IN_WP_CONFIG'); // Example placeholder for wp-config
    if ( defined('PROGRESSIER_API_TOKEN') ) {
        return PROGRESSIER_API_TOKEN;
    }

    // Option 2: Hardcode (Less secure, use only if wp-config is not possible)
    // Replace with your actual token if using this method. Keep this file secure!
    return 'YOUR_PROGRESSIER_API_TOKEN_PLACEHOLDER'; // Placeholder

    // Fallback if neither is set
    // return '';
}

/**
 * Helper function to send the notification payload to Progressier.
 *
 * @param array $payload The notification payload.
 * @param string $context A string for logging context (e.g., 'Voxel DM', 'Voxel Saved Search').
 * @return void
 */
function eh_send_progressier_notification_request(array $payload, string $context = 'Generic') {
    $pwa_api_endpoint = eh_get_progressier_api_endpoint();
    $pwa_api_bearer_token = eh_get_progressier_api_token();

    if ( empty($pwa_api_bearer_token) || $pwa_api_bearer_token === 'YOUR_PROGRESSIER_API_TOKEN_PLACEHOLDER' ) {
        error_log("Progressier API Error ($context): Bearer token is empty or still a placeholder. Notification not sent.");
        return;
    }

    if ( empty($pwa_api_endpoint) || !filter_var($pwa_api_endpoint, FILTER_VALIDATE_URL) || strpos($pwa_api_endpoint, 'YOUR_PROGRESSIER_ENDPOINT_ID') !== false ) {
        error_log("Progressier API Error ($context): API endpoint is missing, invalid, or still a placeholder. Notification not sent.");
        return;
    }

    // Ensure recipient ID is a string if present
    if (isset($payload['recipients']['id'])) {
         $payload['recipients']['id'] = (string) $payload['recipients']['id'];
    }

    $args = [
        'method'  => 'POST',
        'headers' => [
            'Authorization' => 'Bearer ' . $pwa_api_bearer_token,
            'Content-Type'  => 'application/json; charset=utf-8',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 15, // seconds
        'sslverify' => true, // IMPORTANT: Set to false only for local dev with self-signed SSL, not for production
    ];

    error_log("Progressier API Request ($context): Sending payload: " . print_r($payload, true) . " to " . $pwa_api_endpoint);

    $response = wp_remote_post($pwa_api_endpoint, $args);

    // Log Progressier API response
    if (is_wp_error($response)) {
        error_log("Progressier API Call Error ($context): " . $response->get_error_message());
        error_log("Progressier Payload Sent ($context - on WP_Error): " . print_r($payload, true));
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $log_level = ($response_code >= 200 && $response_code < 300) ? 'INFO' : 'ERROR'; // Adjust based on success/failure

        error_log("Progressier API Response ($context) - Code $response_code ($log_level): " . $response_body);

        // Log payload again if there was an API-level error or unexpected response
        if ($response_code >= 400 || stripos($response_body, '"status":"success"') === false) {
             error_log("Progressier Payload Sent ($context - on API error/unexpected response): " . print_r($payload, true));
        }
    }
}


// --- User Online Status Tracking ---
define('EH_USER_ONLINE_TIMEOUT', 60); // 1 minutes in seconds
define('EH_USER_ONLINE_TRANSIENT', 'eh_users_online_status'); // Unique transient name

/**
 * Updates the transient storing the last seen time for logged-in users.
 */
add_action('wp', 'eh_update_online_users_status');
function eh_update_online_users_status() {
    if ( is_user_logged_in() ) {
        // Get the online users list
        $logged_in_users = get_transient(EH_USER_ONLINE_TRANSIENT);
        if ( false === $logged_in_users ) {
            $logged_in_users = [];
        }

        $current_user = wp_get_current_user();
        $current_user_id = $current_user->ID;
        $current_time = current_time('timestamp');

        // Update the user's last seen time
        // No need to check if they were already in, just update.
        // The check for !isset or old time is only relevant if you want to *trigger* something on login/re-login,
        // but for simply tracking activity, updating is fine.
        $logged_in_users[$current_user_id] = $current_time;
        set_transient(EH_USER_ONLINE_TRANSIENT, $logged_in_users, EH_USER_ONLINE_TIMEOUT + (5 * 60)); // Transient expiry slightly longer than timeout
    }
}

/**
 * Checks if a specific user is currently considered online.
 *
 * @param int $user_id The ID of the user to check.
 * @return bool True if the user is considered online, false otherwise.
 */
function eh_is_user_online( $user_id ) {
    if ( empty($user_id) || !is_numeric($user_id) ) {
        return false; // Invalid user ID
    }

    $logged_in_users = get_transient(EH_USER_ONLINE_TRANSIENT);

    if ( false === $logged_in_users || !is_array($logged_in_users) ) {
        return false; // No transient data or invalid data
    }

    if ( isset( $logged_in_users[$user_id] ) ) {
        $last_seen = $logged_in_users[$user_id];
        $current_time = current_time('timestamp');

        // If user's last activity was within the defined timeout period
        if ( $last_seen >= ( $current_time - EH_USER_ONLINE_TIMEOUT ) ) {
            return true; // User is online
        }
    }
    return false; // User is offline or not in transient
}


// --- HOOK 1: Direct Message Notification ---
add_action( 'voxel/app-events/messages/user:received_message_unthrottled', 'eh_handle_voxel_dm_notification', 10, 1 );

function eh_handle_voxel_dm_notification( $event ) {
    $context = 'Voxel DM (Unthrottled)';
    error_log("$context: Event triggered.");

    // Basic validation
    if ( ! ( isset($event->user) && $event->user instanceof \Voxel\User &&
             isset($event->message) && $event->message instanceof \Voxel\Direct_Messages\Message &&
             isset($event->sender) )) {
         error_log("$context Error: Essential properties (user, message, sender) missing or invalid type in event object.");
        return;
    }
    $recipient_voxel_user = $event->user;
    $recipient_id = $recipient_voxel_user->get_id();
    if ( empty($recipient_id) || !is_numeric($recipient_id) ) {
         error_log("$context Error: Recipient WordPress User ID is empty or invalid."); return; }

    // <<< --- NEW CHECK FOR ONLINE STATUS --- >>>
    if ( eh_is_user_online( $recipient_id ) ) {
        error_log("$context: Recipient User ID $recipient_id is online. Skipping push notification.");
        return; // User is online, so don't send a push
    }
    error_log("$context: Recipient User ID $recipient_id is offline. Proceeding with push notification.");
    // <<< --- END NEW CHECK --- >>>


    // Extract data
    $message_obj = $event->message;
    $message_content = wp_strip_all_tags( $message_obj->get_content() );
    $sender_voxel_entity = $event->sender;
    $sender_name = 'Someone';
    $sender_avatar_url = '';
    if ( $sender_voxel_entity instanceof \Voxel\User ) {
        $sender_name = $sender_voxel_entity->get_display_name();
        $sender_avatar_url = $sender_voxel_entity->get_avatar_url();
    } elseif ( $sender_voxel_entity instanceof \Voxel\Post ) {
        $sender_name = $sender_voxel_entity->get_title();
        $author_of_post = $sender_voxel_entity->get_author();
        if ($author_of_post instanceof \Voxel\User) { $sender_avatar_url = $author_of_post->get_avatar_url(); }
    }

    // Construct Chat URL
    $chat_url = home_url('/');
    $chat_arg_val = '';
    if ($message_obj->get_receiver_type() === 'post') { $chat_arg_val .= $message_obj->get_receiver_id(); }
    $chat_arg_val .= ($message_obj->get_sender_type() === 'post') ? 'p' : 'u';
    $chat_arg_val .= $message_obj->get_sender_id();
    $inbox_page_id = \Voxel\get('templates.inbox');
    $inbox_url = $inbox_page_id ? get_permalink($inbox_page_id) : home_url('/');
    if ($inbox_url && $inbox_url !== home_url('/') && !empty($chat_arg_val)) {
        $chat_url = add_query_arg('chat', $chat_arg_val, $inbox_url);
    }

    // Prepare payload
    $payload_title = sprintf('New message from %s', $sender_name);
    $payload_body = wp_trim_words($message_content, 15, '...');
    $payload = [ 'recipients' => ['id' => (string) $recipient_id], 'title' => $payload_title, 'body' => $payload_body, 'url' => $chat_url ];
    if (!empty($sender_avatar_url)) { $payload['icon'] = $sender_avatar_url; }

    eh_send_progressier_notification_request($payload, $context);
}


// --- HOOK 2: Saved Search Notification (Places/Helpers) ---
add_action( 'voxel/app-events/post-types/places/saved-search:post-published', 'eh_handle_voxel_places_saved_search_notification', 10, 1 );

function eh_handle_voxel_places_saved_search_notification( $event ) {
    $context = 'Voxel Saved Search (places)';
    error_log("$context: Event triggered.");

    // Validate Recipient
    if ( ! isset( $event->recipient ) || ! ( $event->recipient instanceof \Voxel\User ) || ! method_exists($event->recipient, 'get_id') ) {
        error_log("$context Error: Event object missing, invalid 'recipient', or get_id() method missing."); return; }
    $recipient_user = $event->recipient;
    $recipient_id = $recipient_user->get_id();
    if ( empty($recipient_id) || !is_numeric($recipient_id) ) {
        error_log("$context Error: Recipient User ID empty/invalid."); return; }
    error_log("$context: Recipient User ID found: $recipient_id");

    // Extract Post ID via Notification Cache and get_details()
    $post_id = null;
    if ( isset($event->_inapp_sent_cache['notification-subscribers']) ) {
        $notification_object = $event->_inapp_sent_cache['notification-subscribers'];
        if ( $notification_object instanceof \Voxel\Notification && method_exists( $notification_object, 'get_details' ) ) {
            $details = $notification_object->get_details();
            if ( is_array( $details ) && isset( $details['post_id'] ) && is_numeric( $details['post_id'] ) ) {
                $post_id = absint( $details['post_id'] );
                error_log("$context: Extracted post_id ($post_id) via get_details().");
            } else { error_log("$context Warning: get_details() failed to return expected array/post_id."); }
        } else { error_log("$context Warning: Object in cache not Voxel\\Notification or lacks get_details()."); }
    } else { error_log("$context Warning: Cache path not found."); }
    if ( empty( $post_id ) ) { error_log("$context Error: Could not extract valid Post ID."); return; }

    // Fetch Voxel Post
    if ( ! class_exists('\Voxel\Post') || ! method_exists('\Voxel\Post', 'get') ) {
        error_log("$context Error: \\Voxel\\Post class or get() method not found."); return; }
    $post = \Voxel\Post::get( $post_id );
    if ( ! $post instanceof \Voxel\Post ) {
        error_log("$context Error: Failed to fetch Voxel Post object for ID: " . $post_id); return; }
    error_log("$context: Successfully fetched Voxel Post object for ID: $post_id");

    // Extract Post Data
    $post_title = $post->get_title();
    $post_url = $post->get_link();
    if ( empty($post_title) ) { $post_title = '(Untitled Helper)'; } // Placeholder title
    if ( empty($post_url) ) { error_log("$context Error: Fetched Post (ID: $post_id) has empty URL."); return; }

    // Prepare Payload
    $payload_title = 'New Matching Helper Found';
    $payload_body = sprintf('Saved Search: New helper "%s" has been added', $post_title);
    $payload_icon = '';
    $post_author = $post->get_author();
    if ($post_author instanceof \Voxel\User && method_exists($post_author, 'get_avatar_url')) {
        $payload_icon = $post_author->get_avatar_url(); }
    $payload = [ 'recipients' => ['id' => (string) $recipient_id], 'title' => $payload_title, 'body' => $payload_body, 'url' => $post_url ];
    if (!empty($payload_icon)) { $payload['icon'] = $payload_icon; }

    // Send Notification
    eh_send_progressier_notification_request($payload, $context);
}


// --- HOOK 3: Saved Search Notification (Jobs) --- NEW BLOCK ---
add_action( 'voxel/app-events/post-types/job/saved-search:post-published', 'eh_handle_voxel_job_saved_search_notification', 10, 1 );

function eh_handle_voxel_job_saved_search_notification( $event ) {
    $context = 'Voxel Saved Search (job)'; // << CONTEXT CHANGED
    error_log("$context: Event triggered.");

    // Validate Recipient (Identical logic to 'places')
    if ( ! isset( $event->recipient ) || ! ( $event->recipient instanceof \Voxel\User ) || ! method_exists($event->recipient, 'get_id') ) {
        error_log("$context Error: Event object missing, invalid 'recipient', or get_id() method missing."); return; }
    $recipient_user = $event->recipient;
    $recipient_id = $recipient_user->get_id();
    if ( empty($recipient_id) || !is_numeric($recipient_id) ) {
        error_log("$context Error: Recipient User ID empty/invalid."); return; }
    error_log("$context: Recipient User ID found: $recipient_id");

    // Extract Post ID via Notification Cache and get_details() (Identical logic to 'places')
    $post_id = null;
    if ( isset($event->_inapp_sent_cache['notification-subscribers']) ) {
        $notification_object = $event->_inapp_sent_cache['notification-subscribers'];
        if ( $notification_object instanceof \Voxel\Notification && method_exists( $notification_object, 'get_details' ) ) {
            $details = $notification_object->get_details();
            if ( is_array( $details ) && isset( $details['post_id'] ) && is_numeric( $details['post_id'] ) ) {
                $post_id = absint( $details['post_id'] );
                error_log("$context: Extracted post_id ($post_id) via get_details().");
            } else { error_log("$context Warning: get_details() failed to return expected array/post_id."); }
        } else { error_log("$context Warning: Object in cache not Voxel\\Notification or lacks get_details()."); }
    } else { error_log("$context Warning: Cache path not found."); }
    if ( empty( $post_id ) ) { error_log("$context Error: Could not extract valid Post ID."); return; }

    // Fetch Voxel Post (Identical logic to 'places')
    if ( ! class_exists('\Voxel\Post') || ! method_exists('\Voxel\Post', 'get') ) {
        error_log("$context Error: \\Voxel\\Post class or get() method not found."); return; }
    $post = \Voxel\Post::get( $post_id );
    if ( ! $post instanceof \Voxel\Post ) {
        error_log("$context Error: Failed to fetch Voxel Post object for ID: " . $post_id); return; }
    error_log("$context: Successfully fetched Voxel Post object for ID: $post_id");

    // Extract Post Data (Identical logic to 'places')
    $post_title = $post->get_title();
    $post_url = $post->get_link();
    if ( empty($post_title) ) { $post_title = '(Untitled Job)'; } // << PLACEHOLDER CHANGED
    if ( empty($post_url) ) { error_log("$context Error: Fetched Post (ID: $post_id) has empty URL."); return; }

    // Prepare Payload << PAYLOAD CONTENT CHANGED
    $payload_title = 'New Matching Job Found';
    $payload_body = sprintf('Saved Search: New job "%s" has been added', $post_title); // << BODY TEXT CHANGED
    $payload_icon = '';
    $post_author = $post->get_author();
    if ($post_author instanceof \Voxel\User && method_exists($post_author, 'get_avatar_url')) {
        $payload_icon = $post_author->get_avatar_url(); }
    $payload = [ 'recipients' => ['id' => (string) $recipient_id], 'title' => $payload_title, 'body' => $payload_body, 'url' => $post_url ];
    if (!empty($payload_icon)) { $payload['icon'] = $payload_icon; }

    // Send Notification (Identical logic to 'places')
    eh_send_progressier_notification_request($payload, $context);
}

?>
