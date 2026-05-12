<?php
/**
 * Scheduler Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABG_Scheduler {

    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );
        add_action( 'abg_scheduled_backup', array( $this, 'run_scheduled_backup' ) );
        add_action( 'wp_ajax_abg_init_backup', array( $this, 'ajax_init_backup' ) );
        add_action( 'wp_ajax_abg_export_db', array( $this, 'ajax_export_db' ) );
        add_action( 'wp_ajax_abg_scan_batch', array( $this, 'ajax_scan_batch' ) );
        add_action( 'wp_ajax_abg_zip_batch', array( $this, 'ajax_zip_batch' ) );
        add_action( 'wp_ajax_abg_start_upload', array( $this, 'ajax_start_upload' ) );
        add_action( 'wp_ajax_abg_upload_chunk', array( $this, 'ajax_upload_chunk' ) );
        add_action( 'wp_ajax_abg_finalize_backup', array( $this, 'ajax_finalize_backup' ) );
        add_action( 'wp_ajax_abg_restore_backup', array( $this, 'ajax_restore_backup' ) );
        add_action( 'wp_ajax_abg_get_progress', array( $this, 'ajax_get_progress' ) );
        add_action( 'wp_ajax_abg_manual_restore', array( $this, 'ajax_manual_restore' ) );
        add_action( 'wp_ajax_abg_manual_restore_step', array( $this, 'ajax_manual_restore_step' ) );
        add_action( 'wp_ajax_abg_manual_upload_chunk', array( $this, 'ajax_manual_upload_chunk' ) );
        add_action( 'wp_ajax_abg_disconnect', array( $this, 'ajax_disconnect' ) );
        add_action( 'update_option_abg_settings', array( $this, 'reschedule_backups' ), 10, 2 );
    }

    public function add_custom_cron_intervals( $schedules ) {
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __( 'Once Weekly', 'auto-backup-gdrive' )
        );
        $schedules['monthly'] = array(
            'interval' => 2592000,
            'display'  => __( 'Once Monthly', 'auto-backup-gdrive' )
        );
        return $schedules;
    }

    public function run_scheduled_backup() {
        $settings = get_option( 'abg_settings', array() );
        if ( empty( $settings['backup_enabled'] ) ) {
            return; // Auto backup is turned off
        }

        $engine = new ABG_Backup_Engine();
        $engine->create_full_backup();
    }

    public function ajax_init_backup() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->init_backup();
        wp_send_json_success( $result );
    }

    public function ajax_export_db() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->export_db_step();
        wp_send_json_success( $result );
    }

    public function ajax_scan_batch() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->scan_files_batch( 100 ); // Increased for live performance
        wp_send_json_success( $result );
    }

    public function ajax_zip_batch() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $next_index = $engine->process_zip_batch( 400 ); // Reduced from 700 for better stability on all servers
        wp_send_json_success( array( 'next_index' => $next_index ) );
    }

    public function ajax_start_upload() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->start_resumable_upload();
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( $result );
        }
    }

    public function ajax_upload_chunk() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->upload_chunk_step();
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( $result );
        }
    }

    public function ajax_finalize_backup() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $engine = new ABG_Backup_Engine();
        $result = $engine->finalize_backup();
        wp_send_json_success( 'Backup completed successfully!' );
    }

    public function ajax_disconnect() {
        check_ajax_referer( 'abg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Delete token storage
        delete_option( 'abg_tokens' );
        delete_option( 'abg_connected_email' );

        // Clean up old token storage in settings just in case
        $settings = get_option( 'abg_settings', array() );
        unset( $settings['access_token'] );
        unset( $settings['refresh_token'] );
        unset( $settings['token_expiry'] );
        update_option( 'abg_settings', $settings );

        wp_send_json_success();
    }


    public function ajax_restore_backup() {
        check_ajax_referer( 'abg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $file_id = sanitize_text_field( $_POST['file_id'] );
        $file_name = sanitize_text_field( $_POST['file_name'] );

        $engine = new ABG_Backup_Engine();
        $result = $engine->restore_backup( $file_id, $file_name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( 'Restore completed!' );
        }
    }

    public function ajax_get_progress() {
        if ( isset( $_GET['get_total'] ) ) {
            wp_send_json_success( array( 
                'total_files' => get_option( 'abg_backup_total_files', 0 ) 
            ) );
        }
        $status = get_option( 'abg_backup_status' );
        wp_send_json_success( $status ? $status : 'Processing...' );
    }

    public function ajax_manual_restore() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $file_name = sanitize_text_field( $_POST['file_name'] );
        $engine = new ABG_Backup_Engine();
        $destination = $engine->backup_dir . '/' . sanitize_file_name( $file_name );

        if ( file_exists( $destination ) ) {
            wp_send_json_success( array( 'zip_path' => $destination ) );
        } else {
            wp_send_json_error( 'Uploaded file not found.' );
        }
    }

    public function ajax_manual_upload_chunk() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        if ( empty( $_FILES['file_chunk'] ) ) {
            wp_send_json_error( 'No chunk received. Check post_max_size or upload_max_filesize.' );
        }

        $file_name = sanitize_text_field( $_POST['file_name'] );
        if ( pathinfo( $file_name, PATHINFO_EXTENSION ) !== 'mpack' ) {
            wp_send_json_error( 'Invalid file format.' );
        }

        $chunk_index = intval( $_POST['chunk_index'] );
        $total_chunks = intval( $_POST['total_chunks'] );
        
        $engine = new ABG_Backup_Engine();
        
        // Ensure directory exists and is writable
        if ( ! file_exists( $engine->backup_dir ) ) {
            wp_mkdir_p( $engine->backup_dir );
        }
        if ( ! is_writable( $engine->backup_dir ) ) {
            wp_send_json_error( 'Backup directory is not writable.' );
        }

        $temp_path = $engine->backup_dir . '/' . sanitize_file_name( $file_name ) . '.part';

        $chunk = $_FILES['file_chunk'];
        $handle = @fopen( $temp_path, $chunk_index === 0 ? 'wb' : 'ab' );
        
        if ( $handle ) {
            $chunk_data = @file_get_contents( $chunk['tmp_name'] );
            if ( $chunk_data === false ) {
                @fclose( $handle );
                wp_send_json_error( 'Failed to read uploaded chunk.' );
            }
            
            fwrite( $handle, $chunk_data );
            fclose( $handle );

            if ( $chunk_index === $total_chunks - 1 ) {
                $final_path = $engine->backup_dir . '/' . sanitize_file_name( $file_name );
                if ( file_exists( $final_path ) ) @unlink( $final_path );
                rename( $temp_path, $final_path );
            }

            wp_send_json_success( 'Chunk saved.' );
        } else {
            wp_send_json_error( 'Failed to open temp file for writing. Path: ' . $temp_path );
        }
    }

    public function ajax_manual_restore_step() {
        check_ajax_referer( 'abg_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $zip_path = sanitize_text_field( $_POST['zip_path'] );
        $step = sanitize_text_field( $_POST['step'] );
        $index = intval( $_POST['index'] ?? 0 );

        $engine = new ABG_Backup_Engine();
        $result = $engine->process_restore_step( $zip_path, $step, $index );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( $result );
        }
    }

    public function reschedule_backups( $old_value, $new_value ) {
        if ( ! isset( $new_value['backup_frequency'] ) ) return;
        if ( isset( $old_value['backup_frequency'] ) && $old_value['backup_frequency'] === $new_value['backup_frequency'] ) return;

        wp_clear_scheduled_hook( 'abg_scheduled_backup' );
        wp_schedule_event( time(), $new_value['backup_frequency'], 'abg_scheduled_backup' );
    }
}
