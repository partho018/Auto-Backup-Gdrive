<?php
/**
 * Google Drive Service Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABG_GDrive_Service {

    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $auth_endpoint = 'https://accounts.google.com/o/oauth2/auth';
    private $token_endpoint = 'https://oauth2.googleapis.com/token';
    private $upload_endpoint = 'https://www.googleapis.com/upload/drive/v3/files';

    public function __construct() {
        $settings = get_option( 'abg_settings', array() );
        $this->client_id = $settings['client_id'] ?? '';
        $this->client_secret = $settings['client_secret'] ?? '';
        $this->redirect_uri = admin_url( 'admin.php?page=abg-settings&abg_auth=1' );
    }

    public function is_connected() {
        $tokens = get_option( 'abg_tokens', array() );
        return ! empty( $tokens['access_token'] );
    }

    public function get_auth_url() {
        if ( empty( $this->client_id ) ) return false;

        $params = array(
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent'
        );

        return $this->auth_endpoint . '?' . http_build_query( $params );
    }

    public function fetch_tokens( $code ) {
        $response = wp_remote_post( $this->token_endpoint, array(
            'sslverify' => false,
            'body' => array(
                'code'          => $code,
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset( $data['access_token'] ) ) {
            $tokens = array(
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? '',
                'token_expiry'  => time() + ( $data['expires_in'] ?? 3600 ),
            );
            update_option( 'abg_tokens', $tokens );
            
            // Also fetch user email
            $this->fetch_and_save_user_email( $data['access_token'] );
            
            return true;
        }

        return new WP_Error( 'token_error', $data['error_description'] ?? 'Failed to fetch tokens.' );
    }

    public function fetch_and_save_user_email( $access_token ) {
        $response = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'sslverify' => false,
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['email'] ) ) {
                update_option( 'abg_connected_email', $data['email'] );
                return $data['email'];
            }
        }
        return false;
    }

    public function refresh_token() {
        $tokens = get_option( 'abg_tokens', array() );
        if ( empty( $tokens['refresh_token'] ) ) return false;

        $response = wp_remote_post( $this->token_endpoint, array(
            'sslverify' => false,
            'body' => array(
                'refresh_token' => $tokens['refresh_token'],
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['access_token'] ) ) {
                $tokens['access_token'] = $data['access_token'];
                $tokens['token_expiry'] = time() + ( $data['expires_in'] ?? 3600 );
                update_option( 'abg_tokens', $tokens );
                return $data['access_token'];
            }
        }
        return false;
    }

    public function get_valid_access_token() {
        $tokens = get_option( 'abg_tokens', array() );
        $access_token = $tokens['access_token'] ?? '';
        
        if ( empty( $access_token ) ) return false;

        if ( time() >= ($tokens['token_expiry'] ?? 0) - 60 ) {
            $refreshed_token = $this->refresh_token();
            if ( $refreshed_token ) {
                return $refreshed_token;
            } else {
                return new WP_Error( 'auth_expired', 'Google Drive authorization expired.' );
            }
        }

        return $access_token;
    }

    public function get_or_create_folder( $folder_name ) {
        $access_token = $this->get_valid_access_token();
        if ( empty( $access_token ) || is_wp_error( $access_token ) ) return false;

        // Search for folder
        $query = "name = '{$folder_name}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode( $query );

        $response = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'sslverify' => false
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['files'] ) ) {
                return $data['files'][0]['id'];
            }
        }

        // Create folder
        $response = wp_remote_post( 'https://www.googleapis.com/drive/v3/files', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ),
            'body' => json_encode( array(
                'name'     => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder'
            ) ),
            'sslverify' => false
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            return $data['id'] ?? false;
        }

        return false;
    }

    public function init_resumable_session( $file_name, $file_size, $parent_id = null ) {
        $access_token = $this->get_valid_access_token();
        if ( is_wp_error( $access_token ) ) return $access_token;

        $metadata = array( 'name' => $file_name );
        if ( $parent_id ) {
            $metadata['parents'] = array( $parent_id );
        }

        $response = wp_remote_post( 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', array(
            'headers' => array(
                'Authorization'  => 'Bearer ' . $access_token,
                'Content-Type'   => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => $file_size,
            ),
            'body' => json_encode( $metadata ),
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) return $response;
        
        $session_uri = wp_remote_retrieve_header( $response, 'location' );
        if ( ! $session_uri ) {
            return new WP_Error( 'upload_init_failed', 'Failed to get upload session URI.' );
        }

        return $session_uri;
    }

    public function upload_chunk_direct( $file_path, $session_uri, $offset, $chunk_size ) {
        $file_size = filesize( $file_path );
        $handle = fopen( $file_path, 'rb' );
        fseek( $handle, $offset );
        $chunk = fread( $handle, $chunk_size );
        $chunk_length = strlen( $chunk );
        fclose( $handle );

        if ( $chunk_length === 0 ) return array( 'next_offset' => $offset, 'status' => 200 );

        $end_pos = $offset + $chunk_length - 1;
        
        $response = wp_remote_request( $session_uri, array(
            'method'    => 'PUT',
            'headers'   => array(
                'Content-Length' => $chunk_length,
                'Content-Range'  => "bytes {$offset}-{$end_pos}/{$file_size}",
            ),
            'body'      => $chunk,
            'timeout'   => 120,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $status = wp_remote_retrieve_response_code( $response );
        return array(
            'next_offset' => $offset + $chunk_length,
            'status'      => $status
        );
    }

    public function list_folder_files( $folder_id ) {
        $access_token = $this->get_valid_access_token();
        if ( empty( $access_token ) || is_wp_error( $access_token ) ) return array();

        $query = "'{$folder_id}' in parents and trashed = false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode( $query ) . "&orderBy=createdTime desc&fields=files(id,name,size,createdTime,webViewLink)";

        $response = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'sslverify' => false
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            return $data['files'] ?? array();
        }

        return array();
    }

    public function delete_old_backups( $folder_id, $keep_count ) {
        $access_token = $this->get_valid_access_token();
        if ( empty( $access_token ) || is_wp_error( $access_token ) ) return false;

        $query = "'{$folder_id}' in parents and trashed = false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode( $query ) . "&orderBy=createdTime desc&fields=files(id,name,createdTime)";

        $response = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
            'sslverify' => false
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data['files'] ) && count( $data['files'] ) > $keep_count ) {
                $files_to_delete = array_slice( $data['files'], $keep_count );
                foreach ( $files_to_delete as $file ) {
                    wp_remote_request( "https://www.googleapis.com/drive/v3/files/{$file['id']}", array(
                        'method'    => 'DELETE',
                        'headers'   => array( 'Authorization' => 'Bearer ' . $access_token ),
                        'sslverify' => false
                    ) );
                }
            }
        }
    }

    public function download_file( $file_id, $destination ) {
        $access_token = $this->get_valid_access_token();
        if ( empty( $access_token ) || is_wp_error( $access_token ) ) return $access_token;

        $response = wp_remote_get( "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media", array(
            'headers'  => array( 'Authorization' => 'Bearer ' . $access_token ),
            'timeout'  => 3600,
            'stream'   => true,
            'filename' => $destination,
            'sslverify' => false
        ) );

        return is_wp_error( $response ) ? $response : true;
    }
}
