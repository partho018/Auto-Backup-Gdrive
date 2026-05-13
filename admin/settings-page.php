<?php
/**
 * Admin Settings Page Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABG_Settings_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_google_auth' ) );
	}

    public function add_menu_page() {
        add_menu_page(
            __( 'Auto Backup Gdrive', 'auto-backup-gdrive' ),
            __( 'GDrive Backup', 'auto-backup-gdrive' ),
            'manage_options',
            'abg-settings',
            array( $this, 'render_backup_page' ),
            'dashicons-cloud-upload',
            80
        );

        add_submenu_page(
            'abg-settings',
            __( 'Backup Settings', 'auto-backup-gdrive' ),
            __( 'Backup', 'auto-backup-gdrive' ),
            'manage_options',
            'abg-settings',
            array( $this, 'render_backup_page' )
        );

        add_submenu_page(
            'abg-settings',
            __( 'Restore Backups', 'auto-backup-gdrive' ),
            __( 'Restore', 'auto-backup-gdrive' ),
            'manage_options',
            'abg-restore',
            array( $this, 'render_restore_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_abg-settings' !== $hook && 'gdrive-backup_page_abg-restore' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'abg-admin-style', ABG_URL . 'assets/admin-style.css', array(), ABG_VERSION );
        wp_enqueue_script( 'abg-admin-js', ABG_URL . 'assets/admin-script-v106.js', array( 'jquery' ), ABG_VERSION, true );
        wp_localize_script( 'abg-admin-js', 'abg_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'abg_nonce' ),
            'auth_url' => $this->get_auth_url(),
        ) );
    }

    public function register_settings() {
        register_setting( 'abg_settings_group', 'abg_settings' );
    }

    private function render_header( $is_connected ) {
        if ( isset( $_GET['auth_success'] ) ) {
            echo '<script>if(window.opener){window.opener.location.reload();window.close();}else{window.location.href="'.admin_url('admin.php?page=abg-settings').'";}</script>';
            exit;
        }

        $error = get_option( 'abg_auth_error' );
        ?>
        <div class="wrap abg-admin-wrap">
            <?php if ( isset( $_GET['auth_failed'] ) && $error ) : ?>
                <div class="notice notice-error is-dismissible" style="border-radius: 12px; border-left-width: 4px;">
                    <p><strong><?php _e( 'Connection Failed:', 'auto-backup-gdrive' ); ?></strong> <?php echo esc_html( $error ); ?></p>
                </div>
            <?php endif; ?>

            <header class="abg-header">
                <h1><span class="dashicons dashicons-cloud-upload"></span> <?php _e( 'Auto Backup Gdrive', 'auto-backup-gdrive' ); ?></h1>
                <div class="abg-status-badge <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                    <?php echo $is_connected ? __( 'Cloud Active', 'auto-backup-gdrive' ) : __( 'Cloud Offline', 'auto-backup-gdrive' ); ?>
                </div>
            </header>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=abg-settings'); ?>" class="nav-tab <?php echo (isset($_GET['page']) && $_GET['page'] === 'abg-settings') ? 'nav-tab-active' : ''; ?>"><?php _e( 'Dashboard & Configuration', 'auto-backup-gdrive' ); ?></a>
                <a href="<?php echo admin_url('admin.php?page=abg-restore'); ?>" class="nav-tab <?php echo (isset($_GET['page']) && $_GET['page'] === 'abg-restore') ? 'nav-tab-active' : ''; ?>"><?php _e( 'Recovery Center', 'auto-backup-gdrive' ); ?></a>
            </nav>
        <?php
    }

    private function render_footer() {
        ?>
            <div id="abg-modal" class="abg-modal" style="display:none;">
                <div class="abg-modal-content">
                    <span class="abg-close">&times;</span>
                    <div id="abg-progress-container">
                        <div style="margin-bottom: 20px;">
                            <span class="dashicons dashicons-update spin" style="font-size: 40px; width: 40px; height: 40px; color: var(--abg-primary);"></span>
                        </div>
                        <h2 id="abg-progress-title" style="font-size: 20px; font-weight: 800; margin-bottom: 10px;"><?php _e( 'Processing Request', 'auto-backup-gdrive' ); ?></h2>
                        <p id="abg-progress-status" style="color: var(--abg-text-muted); font-size: 14px;"><?php _e( 'Please wait while we handle your data...', 'auto-backup-gdrive' ); ?></p>
                        <div class="abg-progress-bar">
                            <div class="abg-progress-fill"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .spin { animation: abg-spin 2s linear infinite; }
            @keyframes abg-spin { 100% { transform: rotate(360deg); } }
        </style>
        <?php
    }

    public function render_backup_page() {
        $settings = get_option( 'abg_settings', array() );
        $gdrive = new ABG_GDrive_Service();
        $is_connected = $gdrive->is_connected();
        $connected_email = get_option( 'abg_connected_email' );

        if ( $is_connected && empty( $connected_email ) ) {
            $token = $gdrive->get_valid_access_token();
            if ( $token && ! is_wp_error( $token ) ) {
                $connected_email = $gdrive->fetch_and_save_user_email( $token );
            }
        }

        $has_creds = ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );

        $this->render_header( $is_connected );
        ?>
            <div class="abg-content-grid">
                <div class="abg-main-column">
                    <?php if ( ! $is_connected ) : ?>
                        <div class="abg-connect-wizard abg-card">
                            <div class="abg-wizard-step">
                                <div style="margin-bottom: 25px;">
                                    <span class="dashicons dashicons-google" style="font-size: 60px; width: 60px; height: 60px; color: #4285f4;"></span>
                                </div>
                                <h2><?php _e( 'Connect your Google Drive', 'auto-backup-gdrive' ); ?></h2>
                                <p style="max-width: 400px; margin: 0 auto 30px;"><?php _e( 'Authorize your site to securely store backups in your personal Google Drive account.', 'auto-backup-gdrive' ); ?></p>
                                
                                <?php if ( $has_creds ) : ?>
                                    <a id="abg-connect-google" href="<?php echo esc_url( $this->get_auth_url() ); ?>" target="_blank" class="button button-hero button-primary">
                                        <?php _e( 'Link Google Account', 'auto-backup-gdrive' ); ?>
                                    </a>
                                <?php else : ?>
                                    <div class="abg-help-box" style="display: inline-block; text-align: left; max-width: 450px;">
                                        <strong><?php _e( 'Setup Required', 'auto-backup-gdrive' ); ?></strong>
                                        <p style="font-size: 13px; margin: 0;"><?php _e( 'Please enter your API credentials in the "API Configuration" section below to enable Google Drive integration.', 'auto-backup-gdrive' ); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="abg-card" style="margin-bottom: 24px; background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%); border-color: #bbf7d0;">
                            <div style="display: flex; align-items: center; gap: 20px;">
                                <div style="background: #10b981; color: #fff; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                                    <span class="dashicons dashicons-yes-alt" style="font-size: 24px; width: 24px; height: 24px;"></span>
                                </div>
                                <div>
                                    <h2 style="margin: 0; font-size: 18px; font-weight: 800; color: #065f46;"><?php _e( 'System Active', 'auto-backup-gdrive' ); ?></h2>
                                    <p style="margin: 4px 0 0; font-size: 14px; color: #047857;"><?php printf( __( 'Secured by: %s', 'auto-backup-gdrive' ), '<strong>' . esc_html( $connected_email ? $connected_email : 'Google Drive' ) . '</strong>' ); ?></p>
                                </div>
                                <button id="abg-disconnect" class="button" style="margin-left: auto; background: #fff; color: #ef4444; border-color: #fecaca; padding: 6px 16px; border-radius: 8px; font-weight: 600;"><?php _e( 'Disconnect', 'auto-backup-gdrive' ); ?></button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="options.php" class="abg-settings-form">
                        <?php settings_fields( 'abg_settings_group' ); ?>
                        
                        <div class="abg-section">
                            <h3 class="abg-section-toggle"><span class="dashicons dashicons-admin-generic"></span> <?php _e( 'API Credentials', 'auto-backup-gdrive' ); ?> <span class="abg-toggle-settings dashicons dashicons-arrow-right-alt2" style="margin-left: auto;"></span></h3>
                            <div class="abg-settings-fields">
                                <div class="abg-help-box">
                                    <strong><?php _e( 'Integration Guide', 'auto-backup-gdrive' ); ?></strong>
                                    <ol style="margin: 15px 0 0 20px; padding: 0; list-style: decimal;">
                                        <li style="margin-bottom: 10px;"><?php printf( __( 'Go to the <a href="%s" target="_blank" style="color: var(--abg-primary); font-weight: 700;">Google Cloud Console</a> and create a new project.', 'auto-backup-gdrive' ), 'https://console.cloud.google.com/' ); ?></li>
                                        <li style="margin-bottom: 10px;"><?php _e( 'Navigate to <strong>Enabled APIs & Services</strong> and enable the <strong>Google Drive API</strong>.', 'auto-backup-gdrive' ); ?></li>
                                        <li style="margin-bottom: 10px;"><?php _e( 'Go to <strong>Credentials</strong>, click <strong>Create Credentials</strong>, and select <strong>OAuth Client ID</strong>.', 'auto-backup-gdrive' ); ?></li>
                                        <li style="margin-bottom: 10px;"><?php _e( 'Set the Application Type to <strong>Web Application</strong> and add the Redirect URI shown below.', 'auto-backup-gdrive' ); ?></li>
                                    </ol>
                                </div>
                                
                                <div class="abg-field" style="margin-top: 20px;">
                                    <label><?php _e( 'Client ID', 'auto-backup-gdrive' ); ?></label>
                                    <input type="text" name="abg_settings[client_id]" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" placeholder="your-id.apps.googleusercontent.com">
                                </div>

                                <div class="abg-field">
                                    <label><?php _e( 'Client Secret', 'auto-backup-gdrive' ); ?></label>
                                    <input type="password" name="abg_settings[client_secret]" value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" placeholder="••••••••••••••••">
                                </div>

                                <div class="abg-field">
                                    <label><?php _e( 'Authorized Redirect URI', 'auto-backup-gdrive' ); ?></label>
                                    <code class="abg-copy-code" style="display: block; padding: 12px; background: #f1f5f9; border-radius: 8px; border: 1px dashed #cbd5e1; font-size: 11px; word-break: break-all;"><?php echo esc_url( admin_url( 'admin.php?page=abg-settings&abg_auth=1' ) ); ?></code>
                                </div>
                                <?php submit_button( __( 'Verify & Save', 'auto-backup-gdrive' ), 'primary' ); ?>
                            </div>
                        </div>

                        <div class="abg-section">
                            <h3 class="abg-section-toggle"><span class="dashicons dashicons-calendar-alt"></span> <?php _e( 'Automation Engine', 'auto-backup-gdrive' ); ?> <span class="abg-toggle-settings dashicons dashicons-arrow-right-alt2" style="margin-left: auto;"></span></h3>
                            <div class="abg-settings-fields">
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #f8fafc; border-radius: 12px; margin-bottom: 24px; border: 1px solid #e2e8f0;">
                                    <div>
                                        <h4 style="margin: 0; font-size: 15px; font-weight: 700;"><?php _e( 'Automatic Backups', 'auto-backup-gdrive' ); ?></h4>
                                        <p style="margin: 4px 0 0; font-size: 13px; color: var(--abg-text-muted);"><?php _e( 'Run scheduled backups to your cloud storage.', 'auto-backup-gdrive' ); ?></p>
                                    </div>
                                    <label class="abg-switch">
                                        <input type="checkbox" name="abg_settings[backup_enabled]" value="1" <?php checked( $settings['backup_enabled'] ?? 0, 1 ); ?>>
                                        <span class="abg-slider"></span>
                                    </label>
                                </div>

                                <div class="abg-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                    <div class="abg-field">
                                        <label><?php _e( 'Frequency', 'auto-backup-gdrive' ); ?></label>
                                        <select name="abg_settings[backup_frequency]">
                                            <option value="daily" <?php selected( $settings['backup_frequency'] ?? '', 'daily' ); ?>><?php _e( 'Daily', 'auto-backup-gdrive' ); ?></option>
                                            <option value="weekly" <?php selected( $settings['backup_frequency'] ?? '', 'weekly' ); ?>><?php _e( 'Weekly', 'auto-backup-gdrive' ); ?></option>
                                            <option value="monthly" <?php selected( $settings['backup_frequency'] ?? '', 'monthly' ); ?>><?php _e( 'Monthly', 'auto-backup-gdrive' ); ?></option>
                                        </select>
                                    </div>
                                    <div class="abg-field">
                                        <label><?php _e( 'Retention (Last N Backups)', 'auto-backup-gdrive' ); ?></label>
                                        <input type="number" name="abg_settings[keep_backups]" value="<?php echo esc_attr( $settings['keep_backups'] ?? 5 ); ?>" min="1" max="50">
                                    </div>
                                </div>
                                <?php submit_button( __( 'Apply Schedule', 'auto-backup-gdrive' ), 'primary' ); ?>
                            </div>
                        </div>
                    </form>
                </div>

                <aside class="abg-sidebar">
                    <div class="abg-card" style="background: linear-gradient(135deg, var(--abg-primary), var(--abg-primary-dark)); color: #fff; border: none; padding: 30px;">
                        <h3 style="color: #fff; margin-bottom: 12px; font-size: 18px; font-weight: 800;"><?php _e( 'Instant Backup', 'auto-backup-gdrive' ); ?></h3>
                        <p style="font-size: 13px; opacity: 0.9; margin-bottom: 25px;"><?php _e( 'Generate a full site backup immediately and upload to Google Drive.', 'auto-backup-gdrive' ); ?></p>
                        <button id="abg-run-backup" class="button" style="width: 100%; height: 50px; background: #fff !important; color: var(--abg-primary) !important; border: none !important; font-weight: 800 !important; font-size: 15px !important; border-radius: 14px !important; box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important; display: flex; align-items: center; justify-content: center; gap: 10px; padding: 10px 20px;" <?php disabled( ! $is_connected ); ?>>
                            <span class="dashicons dashicons-backup" style="font-size: 20px; width: 20px; height: 20px;"></span> <?php _e( 'Start Now', 'auto-backup-gdrive' ); ?>
                        </button>
                    </div>

                    <div class="abg-card" style="margin-top: 24px;">
                        <h3><span class="dashicons dashicons-info"></span> <?php _e( 'System Status', 'auto-backup-gdrive' ); ?></h3>
                        <div style="font-size: 13px; color: var(--abg-text-muted);">
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9;">
                                <span><?php _e( 'Max Upload:', 'auto-backup-gdrive' ); ?></span>
                                <span style="color: var(--abg-text-main); font-weight: 600;"><?php echo ini_get('upload_max_filesize'); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9;">
                                <span><?php _e( 'PHP Version:', 'auto-backup-gdrive' ); ?></span>
                                <span style="color: var(--abg-text-main); font-weight: 600;"><?php echo PHP_VERSION; ?></span>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        <?php
        $this->render_footer();
    }

    public function render_restore_page() {
        $gdrive = new ABG_GDrive_Service();
        $is_connected = $gdrive->is_connected();
        
        $cloud_backups = array();
        if ( $is_connected ) {
            $folder_id = $gdrive->get_or_create_folder( 'ABG Backups' );
            $cloud_backups = array_slice( $gdrive->list_folder_files( $folder_id ), 0, 2 );
        }

        $engine = new ABG_Backup_Engine();
        $local_backups = array();
        if ( is_dir( $engine->backup_dir ) ) {
            $files = scandir( $engine->backup_dir, SCANDIR_SORT_DESCENDING );
            foreach ( $files as $file ) {
                if ( pathinfo( $file, PATHINFO_EXTENSION ) === 'zip' ) {
                    $path = $engine->backup_dir . '/' . $file;
                    $local_backups[] = array(
                        'name' => $file,
                        'size' => filesize( $path ),
                        'time' => filemtime( $path ),
                        'path' => $path
                    );
                }
                if ( count( $local_backups ) >= 2 ) break;
            }
        }

        $this->render_header( $is_connected );
        ?>
            <div class="abg-content-grid">
                <div class="abg-main-column">
                    <div class="abg-card">
                        <h3><span class="dashicons dashicons-cloud"></span> <?php _e( 'Cloud Repositories (Google Drive)', 'auto-backup-gdrive' ); ?></h3>
                        <div class="abg-cloud-list">
                            <?php if ( ! $is_connected ) : ?>
                                <div style="text-align: center; padding: 40px;">
                                    <span class="dashicons dashicons-lock" style="font-size: 40px; width: 40px; height: 40px; color: #cbd5e1; margin-bottom: 15px;"></span>
                                    <p style="color: var(--abg-text-muted); font-size: 14px;"><?php _e( 'Authentication required to access cloud backups.', 'auto-backup-gdrive' ); ?></p>
                                </div>
                            <?php elseif ( empty( $cloud_backups ) ) : ?>
                                <div style="text-align: center; padding: 40px;">
                                    <span class="dashicons dashicons-search" style="font-size: 40px; width: 40px; height: 40px; color: #cbd5e1; margin-bottom: 15px;"></span>
                                    <p style="color: var(--abg-text-muted); font-size: 14px;"><?php _e( 'No backups found in your Drive folder.', 'auto-backup-gdrive' ); ?></p>
                                </div>
                            <?php else : ?>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Timestamp', 'auto-backup-gdrive' ); ?></th>
                                            <th><?php _e( 'Archive Name', 'auto-backup-gdrive' ); ?></th>
                                            <th><?php _e( 'Size', 'auto-backup-gdrive' ); ?></th>
                                            <th style="text-align:right;"><?php _e( 'Operations', 'auto-backup-gdrive' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $cloud_backups as $file ) : ?>
                                            <tr>
                                                <td style="font-weight: 600;"><?php echo date( 'M d, Y | H:i', strtotime( $file['createdTime'] ) ); ?></td>
                                                <td style="color: var(--abg-text-muted); font-family: monospace;"><?php echo esc_html( $file['name'] ); ?></td>
                                                <td><?php echo round( $file['size'] / ( 1024 * 1024 ), 2 ) . ' MB'; ?></td>
                                                <td style="text-align:right;">
                                                    <button type="button" class="button abg-restore-btn" data-id="<?php echo esc_attr( $file['id'] ); ?>" data-name="<?php echo esc_attr( $file['name'] ); ?>">
                                                        <span class="dashicons dashicons-undo"></span> <?php _e( 'Restore', 'auto-backup-gdrive' ); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="abg-card" style="margin-top: 24px; border-top: 4px solid var(--abg-primary-light);">
                        <h3><span class="dashicons dashicons-admin-home"></span> <?php _e( 'Local Storage Repositories', 'auto-backup-gdrive' ); ?></h3>
                        <div class="abg-list">
                            <?php if ( empty( $local_backups ) ) : ?>
                                <div style="text-align: center; padding: 40px;">
                                    <span class="dashicons dashicons-warning" style="font-size: 40px; width: 40px; height: 40px; color: #cbd5e1; margin-bottom: 15px;"></span>
                                    <p style="color: var(--abg-text-muted); font-size: 14px;"><?php _e( 'No local archives detected.', 'auto-backup-gdrive' ); ?></p>
                                </div>
                            <?php else : ?>
                                <div style="display: grid; gap: 12px; margin-top: 10px;">
                                    <?php foreach ( $local_backups as $backup ) : ?>
                                        <div class="abg-log-item" style="display: flex; justify-content: space-between; align-items: center; border: 1px solid #f1f5f9; padding: 20px; border-radius: 12px; background: #fff;">
                                            <div>
                                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                                    <span class="dashicons dashicons-calendar-alt" style="font-size: 14px; width: 14px; height: 14px; color: var(--abg-primary);"></span>
                                                    <span style="font-size: 11px; font-weight: 700; color: var(--abg-text-muted); text-transform: uppercase;"><?php echo date_i18n( get_option( 'date_format' ) . ' | ' . get_option( 'time_format' ), $backup['time'] ); ?></span>
                                                </div>
                                                <span title="<?php echo esc_attr( $backup['name'] ); ?>" style="font-size: 14px; font-weight: 700; color: var(--abg-text-main); font-family: monospace;"><?php echo esc_html( $backup['name'] ); ?></span>
                                            </div>
                                            <button type="button" class="button abg-local-restore-btn" data-path="<?php echo esc_attr( $backup['path'] ); ?>" data-name="<?php echo esc_attr( $backup['name'] ); ?>">
                                                <span class="dashicons dashicons-migrate"></span> <?php _e( 'Restore', 'auto-backup-gdrive' ); ?>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <aside class="abg-sidebar">
                    <div class="abg-card" style="flex: 1; display: flex; flex-direction: column; padding: 24px 20px;">
                        <h3 style="margin-bottom: 10px;"><span class="dashicons dashicons-upload"></span> <?php _e( 'Import Archive', 'auto-backup-gdrive' ); ?></h3>
                        <p style="font-size: 13px; color: var(--abg-text-muted); margin-bottom: 20px;"><?php _e( 'Upload a .zip backup from your computer.', 'auto-backup-gdrive' ); ?></p>
                        
                        <div class="abg-upload-box" id="abg-upload-container" style="flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 40px; padding: 40px 20px;">
                            <div class="abg-upload-icon" style="cursor: pointer; margin-top: auto;">
                                <span class="dashicons dashicons-cloud-upload" style="font-size: 56px; width: 56px; height: 56px; color: #94a3b8;"></span>
                                <div id="abg-selected-file-name" style="font-size: 13px; font-weight: 600; color: var(--abg-primary); margin-top: 15px; display: none;"></div>
                            </div>
                            <input type="file" id="abg-upload-file" accept=".zip" style="display: none;">
                            <button type="button" id="abg-manual-upload-btn" class="button button-primary" style="width: 100%; height: 45px; font-weight: 700; font-size: 14px; border-radius: 10px; margin-top: auto;">
                                <?php _e( 'Start Upload', 'auto-backup-gdrive' ); ?>
                            </button>
                        </div>

                        <div id="abg-restore-ready-box" style="display:none; flex: 1; flex-direction: column; justify-content: center; align-items: center; background: #ecfdf5; border-radius: 16px; padding: 30px; border: 1px dashed #10b981;">
                            <span class="dashicons dashicons-yes-alt" style="font-size: 40px; width: 40px; height: 40px; color: #10b981; margin-bottom: 15px;"></span>
                            <h4 style="margin: 0 0 10px; color: #065f46;"><?php _e( 'Archive Verified', 'auto-backup-gdrive' ); ?></h4>
                            <p id="uploaded-file-name" style="font-size: 12px; font-family: monospace; background: #fff; padding: 8px 12px; border-radius: 6px; margin-bottom: 25px; border: 1px solid #a7f3d0; text-align: center; width: 100%; word-break: break-all;"></p>
                            <button type="button" id="abg-manual-restore-btn" class="button button-primary button-hero" style="width: 100%; background: #059669 !important;">
                                <?php _e( 'Run Restoration', 'auto-backup-gdrive' ); ?>
                            </button>
                        </div>
                    </div>

                    <div class="abg-help-box" style="margin-top: 24px;">
                        <strong><?php _e( 'Quick Tip', 'auto-backup-gdrive' ); ?></strong>
                        <p style="font-size: 12px; line-height: 1.6; margin: 0; opacity: 0.8;"><?php _e( 'Restoring a backup will overwrite all current files and database tables. Always ensure you have a fallback!', 'auto-backup-gdrive' ); ?></p>
                    </div>
                </aside>
            </div>
        <?php
        $this->render_footer();
    }

    private function get_auth_url() {
        $gdrive = new ABG_GDrive_Service();
        return $gdrive->get_auth_url();
    }

    public function handle_google_auth() {
        if ( isset( $_GET['abg_auth'] ) && isset( $_GET['code'] ) ) {
            $gdrive = new ABG_GDrive_Service();
            $result = $gdrive->fetch_tokens( sanitize_text_field( $_GET['code'] ) );
            
            if ( is_wp_error( $result ) ) {
                update_option( 'abg_auth_error', $result->get_error_message() );
                wp_redirect( admin_url( 'admin.php?page=abg-settings&auth_failed=1' ) );
            } else {
                delete_option( 'abg_auth_error' );
                wp_redirect( admin_url( 'admin.php?page=abg-settings&auth_success=1' ) );
            }
            exit;
        }
    }
}
