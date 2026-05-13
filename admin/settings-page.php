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
			array( $this, 'render_page' ),
			'dashicons-cloud-upload',
			80
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_abg-settings' !== $hook ) {
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

	public function render_page() {
		$settings = get_option( 'abg_settings', array() );
        $tokens = get_option( 'abg_tokens', array() );
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

        // Fetch Cloud Backups if connected
        $cloud_backups = array();
        if ( $is_connected ) {
            $gdrive = new ABG_GDrive_Service();
            // Always use the fixed folder name 'ABG Backups' — same as backup engine uses
            $folder_id = $gdrive->get_or_create_folder( 'ABG Backups' );
            $cloud_backups = array_slice( $gdrive->list_folder_files( $folder_id ), 0, 5 );
        }

        // Fetch Local Backups (Files ready to restore)
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
                if ( count( $local_backups ) >= 3 ) break;
            }
        }

        // If success in URL, we might be in a popup
        if ( isset( $_GET['auth_success'] ) ) {
            echo '<script>if(window.opener){window.opener.location.reload();window.close();}else{window.location.href="'.admin_url('admin.php?page=abg-settings').'";}</script>';
            exit;
        }

        $error = get_option( 'abg_auth_error' );
		?>
		<div class="wrap abg-admin-wrap">
            <?php if ( isset( $_GET['auth_failed'] ) && $error ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong><?php _e( 'Connection Failed:', 'auto-backup-gdrive' ); ?></strong> <?php echo esc_html( $error ); ?></p>
                </div>
            <?php endif; ?>

			<header class="abg-header">
				<h1><span class="dashicons dashicons-cloud-upload"></span> <?php _e( 'Auto Backup Gdrive', 'auto-backup-gdrive' ); ?></h1>
                <div class="abg-status-badge <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                    <?php echo $is_connected ? __( 'Connected', 'auto-backup-gdrive' ) : __( 'Not Connected', 'auto-backup-gdrive' ); ?>
                </div>
			</header>

			<div class="abg-content-grid">
				<div class="abg-main-card">
                    <?php if ( ! $is_connected ) : ?>
                        <div class="abg-connect-wizard">
                            <div class="abg-wizard-step">
                                <h2><?php _e( 'Connect your Google Drive', 'auto-backup-gdrive' ); ?></h2>
                                <p><?php _e( 'Click the button below to select your Gmail account and authorize backups.', 'auto-backup-gdrive' ); ?></p>
                                
                                <?php if ( $has_creds ) : ?>
                                    <a id="abg-connect-google" href="<?php echo esc_url( $this->get_auth_url() ); ?>" target="_blank" class="button button-hero button-primary">
                                        <span class="dashicons dashicons-google"></span> <?php _e( 'Connect Google Account', 'auto-backup-gdrive' ); ?>
                                    </a>
                                <?php else : ?>
                                    <div class="abg-notice warning">
                                        <p><?php _e( 'Please enter your API credentials below first to enable the "Sign in" button.', 'auto-backup-gdrive' ); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="abg-success-card">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <h2><?php _e( 'Everything is Ready!', 'auto-backup-gdrive' ); ?></h2>
                            <p><?php _e( 'Your site is now connected to Google Drive. Backups will run automatically based on your schedule.', 'auto-backup-gdrive' ); ?></p>
                            <p style="margin-top: 10px; font-weight: 500; color: #1d2327;">
                                <?php printf( __( 'Connected Account: %s', 'auto-backup-gdrive' ), '<span style="color: #2271b1;">' . esc_html( $connected_email ? $connected_email : __( 'Connected', 'auto-backup-gdrive' ) ) . '</span>' ); ?>
                            </p>
                            <button id="abg-disconnect" class="button button-link-delete" style="margin-top: 15px;"><?php _e( 'Disconnect Account', 'auto-backup-gdrive' ); ?></button>
                        </div>
                    <?php endif; ?>

					<form method="post" action="options.php" class="abg-settings-form <?php echo $is_connected ? 'is-collapsed' : ''; ?>">
						<?php settings_fields( 'abg_settings_group' ); ?>
						
						<section class="abg-section">
							<h3><span class="dashicons dashicons-admin-generic"></span> <?php _e( 'API Configuration', 'auto-backup-gdrive' ); ?> <span class="abg-toggle-settings dashicons dashicons-arrow-right-alt2"></span></h3>
							<div class="abg-settings-fields">
                                <div class="abg-help-box">
                                    <strong><?php _e( 'How to get Client ID & Secret?', 'auto-backup-gdrive' ); ?></strong>
                                    <ol>
                                        <li><?php printf( __( 'Go to <a href="%s" target="_blank">Google Cloud Console</a>.', 'auto-backup-gdrive' ), 'https://console.cloud.google.com/' ); ?></li>
                                        <li><?php _e( 'Create a Project & Enable <strong>Google Drive API</strong>.', 'auto-backup-gdrive' ); ?></li>
                                        <li><?php _e( 'Create <strong>OAuth Client ID</strong> (Web Application).', 'auto-backup-gdrive' ); ?></li>
                                        <li><?php _e( 'Use the Redirect URI below in Google settings.', 'auto-backup-gdrive' ); ?></li>
                                    </ol>
                                </div>
                                
                                <div class="abg-field">
                                    <label for="client_id"><?php _e( 'Client ID', 'auto-backup-gdrive' ); ?></label>
                                    <input type="text" id="client_id" name="abg_settings[client_id]" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" placeholder="xxxxxx.apps.googleusercontent.com">
                                </div>

                                <div class="abg-field">
                                    <label for="client_secret"><?php _e( 'Client Secret', 'auto-backup-gdrive' ); ?></label>
                                    <input type="password" id="client_secret" name="abg_settings[client_secret]" value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" placeholder="••••••••••••••••">
                                </div>

                                <div class="abg-field">
                                    <label><?php _e( 'Authorized Redirect URI', 'auto-backup-gdrive' ); ?></label>
                                    <code class="abg-copy-code"><?php echo esc_url( admin_url( 'admin.php?page=abg-settings&abg_auth=1' ) ); ?></code>
                                </div>
                                <?php submit_button( __( 'Save Credentials', 'auto-backup-gdrive' ), 'secondary' ); ?>
                            </div>
						</section>

                        <section class="abg-section">
							<h3><span class="dashicons dashicons-calendar-alt"></span> <?php _e( 'Backup Schedule', 'auto-backup-gdrive' ); ?> <span class="abg-toggle-settings dashicons dashicons-arrow-right-alt2"></span></h3>
							<div class="abg-settings-fields">
                                <div class="abg-field abg-toggle-field">
                                    <label class="abg-switch-label">
                                        <span><?php _e( 'Auto Backup Status', 'auto-backup-gdrive' ); ?></span>
                                        <div class="abg-switch">
                                            <input type="checkbox" name="abg_settings[backup_enabled]" value="1" <?php checked( $settings['backup_enabled'] ?? 0, 1 ); ?>>
                                            <span class="abg-slider"></span>
                                        </div>
                                    </label>
                                    <p class="description"><?php _e( 'Turn this ON to enable automatic backups to Google Drive.', 'auto-backup-gdrive' ); ?></p>
                                </div>

                                <div class="abg-row">
                                    <div class="abg-field">
                                        <label for="backup_frequency"><?php _e( 'Frequency', 'auto-backup-gdrive' ); ?></label>
                                        <select id="backup_frequency" name="abg_settings[backup_frequency]">
                                            <option value="daily" <?php selected( $settings['backup_frequency'] ?? '', 'daily' ); ?>><?php _e( 'Daily', 'auto-backup-gdrive' ); ?></option>
                                            <option value="weekly" <?php selected( $settings['backup_frequency'] ?? '', 'weekly' ); ?>><?php _e( 'Weekly', 'auto-backup-gdrive' ); ?></option>
                                            <option value="monthly" <?php selected( $settings['backup_frequency'] ?? '', 'monthly' ); ?>><?php _e( 'Monthly', 'auto-backup-gdrive' ); ?></option>
                                        </select>
                                    </div>

                                    <div class="abg-field">
                                        <label for="keep_backups"><?php _e( 'Keep Last Backups', 'auto-backup-gdrive' ); ?></label>
                                        <input type="number" id="keep_backups" name="abg_settings[keep_backups]" value="<?php echo esc_attr( $settings['keep_backups'] ?? 5 ); ?>" min="1" max="50">
                                    </div>
                                </div>
                                <?php submit_button( __( 'Save Schedule', 'auto-backup-gdrive' ), 'secondary' ); ?>
                            </div>
						</section>
					</form>

                    <div class="abg-card" style="margin-top: 30px;">
                        <h3><span class="dashicons dashicons-cloud"></span> <?php _e( 'Cloud Backups (Google Drive)', 'auto-backup-gdrive' ); ?></h3>
                        <div class="abg-cloud-list">
                            <?php if ( ! $is_connected ) : ?>
                                <p><?php _e( 'Please connect to Google Drive to see your backups.', 'auto-backup-gdrive' ); ?></p>
                            <?php elseif ( empty( $cloud_backups ) ) : ?>
                                <p><?php _e( 'No backups found in your domain folder.', 'auto-backup-gdrive' ); ?></p>
                            <?php else : ?>
                                <table class="wp-list-table widefat fixed striped" style="border:none; box-shadow:none; background:transparent;">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Date', 'auto-backup-gdrive' ); ?></th>
                                            <th><?php _e( 'File Name', 'auto-backup-gdrive' ); ?></th>
                                            <th><?php _e( 'Size', 'auto-backup-gdrive' ); ?></th>
                                            <th style="text-align:right;"><?php _e( 'Action', 'auto-backup-gdrive' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $cloud_backups as $file ) : ?>
                                            <tr>
                                                <td style="vertical-align:middle;"><?php echo date( 'M d, Y H:i', strtotime( $file['createdTime'] ) ); ?></td>
                                                <td style="vertical-align:middle; font-weight:500;"><?php echo esc_html( $file['name'] ); ?></td>
                                                <td style="vertical-align:middle;"><?php echo round( $file['size'] / ( 1024 * 1024 ), 2 ) . ' MB'; ?></td>
                                                <td style="text-align:right;">
                                                    <button type="button" class="button abg-restore-btn" data-id="<?php echo esc_attr( $file['id'] ); ?>" data-name="<?php echo esc_attr( $file['name'] ); ?>" style="border-radius:6px;">
                                                        <span class="dashicons dashicons-undo" style="margin-top:4px;"></span> <?php _e( 'Restore', 'auto-backup-gdrive' ); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                </div> <!-- Correctly closing abg-main-card here -->

				<aside class="abg-sidebar">
					<div class="abg-card abg-quick-actions">
						<h3><?php _e( 'Quick Actions', 'auto-backup-gdrive' ); ?></h3>
						<button id="abg-run-backup" class="button button-hero button-primary" <?php disabled( ! $is_connected ); ?>>
							<span class="dashicons dashicons-backup"></span> <?php _e( 'Run Backup Now', 'auto-backup-gdrive' ); ?>
						</button>
					</div>

                    <div class="abg-card abg-recent-backups">
						<h3><?php _e( 'Local Backups (Available)', 'auto-backup-gdrive' ); ?></h3>
						<ul class="abg-list">
							<?php
                            if ( empty( $local_backups ) ) :
                                ?>
                                <li class="empty"><?php _e( 'No local backups found.', 'auto-backup-gdrive' ); ?></li>
                                <?php
                            else :
                                foreach ( $local_backups as $backup ) :
                                    ?>
                                    <li>
                                        <div class="abg-log-item" style="display: flex; justify-content: space-between; align-items: center;">
                                            <div style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <span class="abg-log-time" style="font-size: 10px;"><span class="dashicons dashicons-calendar-alt" style="font-size: 12px; width: 12px; height: 12px;"></span> <?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['time'] ); ?></span>
                                                <span class="abg-log-file" title="<?php echo esc_attr( $backup['name'] ); ?>" style="font-size: 11px; display: block;"><?php echo esc_html( $backup['name'] ); ?></span>
                                            </div>
                                            <button type="button" class="button button-small abg-local-restore-btn" data-path="<?php echo esc_attr( $backup['path'] ); ?>" data-name="<?php echo esc_attr( $backup['name'] ); ?>" style="font-size: 10px; padding: 0 8px;">
                                                <?php _e( 'Restore', 'auto-backup-gdrive' ); ?>
                                            </button>
                                        </div>
                                    </li>
                                    <?php
                                endforeach;
                            endif;
                            ?>
						</ul>
					</div>

                    <div class="abg-card" style="margin-top: 20px; border-top: 4px solid var(--abg-primary);">
                        <h3><span class="dashicons dashicons-upload"></span> <?php _e( 'Manual Upload', 'auto-backup-gdrive' ); ?></h3>
                        <div class="abg-upload-area">
                            <p class="description" style="font-size: 12px;"><?php _e( 'Upload a .zip backup to restore.', 'auto-backup-gdrive' ); ?></p>
                            <div class="abg-upload-box" id="abg-upload-container" style="flex-direction: column; padding: 15px; text-align: center;">
                                <input type="file" id="abg-upload-file" accept=".zip" style="width: 100%; margin-bottom: 10px;">
                                <button type="button" id="abg-manual-upload-btn" class="button button-primary" style="width: 100%;">
                                    <span class="dashicons dashicons-cloud-upload"></span> <?php _e( 'Upload File', 'auto-backup-gdrive' ); ?>
                                </button>
                            </div>
                            <div id="abg-restore-ready-box" style="display:none; padding: 15px; text-align: center; background: #f0f6fb; border-radius: 8px; margin-top: 10px; border: 1px dashed var(--abg-primary);">
                                <p style="margin-bottom: 10px; font-weight: 600; color: #2271b1;"><span class="dashicons dashicons-yes-alt"></span> <?php _e( 'File Uploaded Successfully!', 'auto-backup-gdrive' ); ?></p>
                                <p id="uploaded-file-name" style="font-size: 12px; margin-bottom: 15px; color: #646970;"></p>
                                <button type="button" id="abg-manual-restore-btn" class="button button-primary" style="width: 100%; background: #2271b1;">
                                    <span class="dashicons dashicons-migrate"></span> <?php _e( 'Restore Now', 'auto-backup-gdrive' ); ?>
                                </button>
                                <button type="button" id="abg-upload-another" class="button button-link" style="margin-top: 10px; font-size: 12px; color: #d63638;"><?php _e( 'Upload Another', 'auto-backup-gdrive' ); ?></button>
                            </div>
                        </div>

				</aside>
			</div>

            <div id="abg-modal" class="abg-modal" style="display:none;">
                <div class="abg-modal-content">
                    <span class="abg-close">&times;</span>
                    <div id="abg-progress-container">
                        <h2 id="abg-progress-title"><?php _e( 'Creating Backup...', 'auto-backup-gdrive' ); ?></h2>
                        <div class="abg-progress-bar">
                            <div class="abg-progress-fill"></div>
                        </div>
                        <p id="abg-progress-status"><?php _e( 'Initializing...', 'auto-backup-gdrive' ); ?></p>
                    </div>
                </div>
            </div>
		</div>
		<?php
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
