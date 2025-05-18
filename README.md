# silver-lamp

# Voxel Push Notifications for Progressier

This WordPress plugin sends push notifications via Progressier for new Direct Messages (DMs) and Saved Search matches in your Voxel-powered site. It's designed to be fast by using Voxel's "unthrottled" events and only sends DM notifications if the user is offline.

**Author:** EmployHelpers

## What it Does:

*   **DM Notifications:** Notifies users of new DMs if they are offline.
*   **Saved Search Alerts:** Notifies users when new posts (e.g., "Places," "Jobs") match their saved searches.
*   Integrates with **Progressier** for sending push notifications.

## Requirements:

*   WordPress
*   [Voxel Theme](https://getvoxel.io/)
*   A [Progressier](https://progressier.com/) account (API Endpoint ID & Bearer Token).

## Installation:

1.  Download the plugin ZIP.
2.  In WordPress Admin: `Plugins` > `Add New` > `Upload Plugin`.
3.  Choose the ZIP file, install, and activate.

## Configuration (Important!):

You **MUST** add your Progressier API details for the plugin to work. Edit the plugin's main PHP file (`send-push-notification-on-dm-saved-search-voxel-unthrottled.php` or similar):

1.  **Progressier API Endpoint ID:**
    Find `eh_get_progressier_api_endpoint()` and replace `YOUR_PROGRESSIER_ENDPOINT_ID` with your actual ID.
    ```php
    // return 'https://progressier.app/YOUR_PROGRESSIER_ENDPOINT_ID/send';
    return 'https://progressier.app/your-actual-id-here/send';
    ```

2.  **Progressier API Bearer Token:**
    Find `eh_get_progressier_api_token()`. Two options:

    *   **Recommended (More Secure):** Add to your `wp-config.php` file:
        ```php
        define('PROGRESSIER_API_TOKEN', 'YOUR_ACTUAL_TOKEN_HERE');
        ```
    *   **In Plugin (Less Secure):** Replace `YOUR_PROGRESSIER_API_TOKEN_PLACEHOLDER` in the function.
        ```php
        // return 'YOUR_PROGRESSIER_API_TOKEN_PLACEHOLDER';
        return 'your_actual_api_token_here';
        ```

**If you don't replace the placeholders, notifications will NOT be sent.**

## Key Features:

*   Real-time DM notifications (for offline users).
*   Instant alerts for new Saved Search matches (for configured post types like "Places" and "Jobs").
*   Uses Voxel's unthrottled events for speed.
*   Basic user online status detection for DMs.

## Important Notes:

*   **Post Types:** By default, it works for saved searches on "places" and "job" post types. You may need to edit the plugin file if your Voxel post type slugs are different.
*   **Logging:** The plugin logs its actions to your PHP error log, which can help with troubleshooting.
*   This plugin is specifically for the **Voxel Theme**.

## Troubleshooting:

1.  **Check PHP Error Log:** For detailed messages.
2.  **Verify API Credentials:** Ensure they are correct and not placeholders.
3.  **Progressier Account:** Make sure your Progressier setup is active and working.

---
_Use this plugin at your own risk._
