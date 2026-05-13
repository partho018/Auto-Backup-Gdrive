<?php
/**
 * Plugin Name: Auto Backup Gdrive
 * Plugin URI: https://pnscode.com
 * Description: Automatically back up your WordPress site and database to Google Drive.
 * Version: 1.1.1
 * Author: Raju
 * Author URI: https://pnscode.com
 * License: GPL2
 * Text Domain: auto-backup-gdrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants
define( 'ABG_VERSION', '1.1.1' );
define( 'ABG_PATH', plugin_dir_path( __FILE__ ) );
define( 'ABG_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once ABG_PATH . 'includes/class-backup-engine.php';
require_once ABG_PATH . 'includes/class-gdrive-service.php';
require_once ABG_PATH . 'includes/class-scheduler.php';
require_once ABG_PATH . 'admin/settings-page.php';

/**
 * Initialize the plugin
 */
function abg_init() {
    // Initialize components
    new ABG_GDrive_Service();
    new ABG_Settings_Page();
    new ABG_Scheduler();
}
add_action( 'plugins_loaded', 'abg_init' );

/**
 * Activation hook
 */
function abg_activate() {
    // Set up default options
    $defaults = array(
        'backup_frequency' => 'daily',
        'backup_time'      => '00:00',
        'keep_backups'     => 5,
    );

    if ( ! get_option( 'abg_settings' ) ) {
        update_option( 'abg_settings', $defaults );
    }

    $settings = get_option( 'abg_settings', $defaults );
    $frequency = $settings['backup_frequency'] ?? 'daily';

    // Schedule initial cron
    if ( ! wp_next_scheduled( 'abg_scheduled_backup' ) ) {
        wp_schedule_event( time(), $frequency, 'abg_scheduled_backup' );
    }
}
register_activation_hook( __FILE__, 'abg_activate' );

/**
 * Deactivation hook
 */
function abg_deactivate() {
    // Clear scheduled tasks
    wp_clear_scheduled_hook( 'abg_scheduled_backup' );
}
register_deactivation_hook( __FILE__, 'abg_deactivate' );

/**
 * Auto Domain Sync
 *
 * Silently keeps siteurl + home in the database in sync with the current
 * domain. This means ANY backup restored to a different domain will
 * immediately work — no manual steps needed.
 *
 * How it works:
 *  - Reads the raw siteurl directly from the DB (bypasses all WP filters).
 *  - Compares just the HOST portion with the current HTTP_HOST.
 *  - If they differ, it updates siteurl & home in the DB and schedules a
 *    background full search-and-replace (only once per day).
 *  - No redirects, no filters that break login — just a silent DB write.
 */
function abg_sync_domain_to_current_host() {
    global $wpdb;

    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    if ( empty( $current_host ) ) return;

    // Read raw value to avoid WP filter loops
    $raw_siteurl = $wpdb->get_var(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl' LIMIT 1"
    );
    if ( empty( $raw_siteurl ) ) return;

    // Extract just the host from the stored URL
    $stored_host = wp_parse_url( $raw_siteurl, PHP_URL_HOST );
    if ( $stored_host === $current_host ) return; // Already correct — nothing to do

    // Build the correct new base URL
    $protocol = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';
    $new_url   = untrailingslashit( $protocol . '://' . $current_host );

    // Save the OLD URL so the background search-and-replace job knows what to
    // replace. Without this the background job would have no way to find the
    // old domain — it would read siteurl from DB and get the already-updated
    // new URL, making the replace a no-op.
    update_option( 'abg_restore_old_url', untrailingslashit( $raw_siteurl ) );

    // Update siteurl and home immediately (direct SQL for speed & safety)
    $wpdb->update( $wpdb->options, array( 'option_value' => $new_url ), array( 'option_name' => 'siteurl' ) );
    $wpdb->update( $wpdb->options, array( 'option_value' => $new_url ), array( 'option_name' => 'home' ) );

    // Clear WP object cache so the new values are used for this request
    wp_cache_delete( 'siteurl', 'options' );
    wp_cache_delete( 'home', 'options' );

    // Schedule a one-time full search-and-replace via WP-Cron
    // (runs in the background so it doesn't slow down the current request)
    if ( ! wp_next_scheduled( 'abg_background_domain_fix' ) ) {
        wp_schedule_single_event( time() + 5, 'abg_background_domain_fix' );
    }
}
// Run as early as possible — priority 1 on 'plugins_loaded'
add_action( 'plugins_loaded', 'abg_sync_domain_to_current_host', 1 );

/**
 * Auto .htaccess Regeneration After Restore
 *
 * After a restore completes, 'abg_needs_htaccess_regen' is set to true.
 * On the very next normal WordPress page load this function detects that
 * flag, regenerates .htaccess fresh for THIS server environment, then
 * clears the flag so it never runs again unnecessarily.
 *
 * This guarantees the user NEVER needs to manually delete .htaccess
 * from cPanel after a restore — it is done automatically.
 */
function abg_maybe_regen_htaccess() {
    // Only proceed if the restore flag is set
    if ( ! get_option( 'abg_needs_htaccess_regen' ) ) {
        return;
    }

    // Clear the flag first to prevent duplicate runs
    delete_option( 'abg_needs_htaccess_regen' );

    // Use $wp_rewrite to write a fresh .htaccess for this server
    global $wp_rewrite;
    if ( isset( $wp_rewrite ) && is_object( $wp_rewrite ) ) {
        $wp_rewrite->flush_rules( true ); // true = physically write to disk
    }
}
// 'init' fires after all plugins are loaded — $wp_rewrite is available here
add_action( 'init', 'abg_maybe_regen_htaccess', 1 );

/**
 * Background WP-Cron job: full search-and-replace across all tables.
 * Fires ~5 seconds after the first mismatched request, in the background.
 */
function abg_run_background_domain_fix() {
    require_once ABG_PATH . 'includes/class-backup-engine.php';
    $engine = new ABG_Backup_Engine();
    $engine->run_search_and_replace();
}
add_action( 'abg_background_domain_fix', 'abg_run_background_domain_fix' );
