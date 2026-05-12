<?php
/**
 * Plugin Name: Auto Backup Gdrive
 * Plugin URI: https://pnscode.com
 * Description: Automatically back up your WordPress site and database to Google Drive.
 * Version: 1.0.7
 * Author: Raju
 * Author URI: https://pnscode.com
 * License: GPL2
 * Text Domain: auto-backup-gdrive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define constants
define( 'ABG_VERSION', '1.0.7' );
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
