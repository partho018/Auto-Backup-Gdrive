<?php
/**
 * Backup Engine Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABG_Backup_Engine {

    public $backup_dir;

    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/abg-backups';
        
        if ( ! file_exists( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
            // Add index.php and .htaccess for security
            file_put_contents( $this->backup_dir . '/index.php', '<?php // Silence is golden' );
            file_put_contents( $this->backup_dir . '/.htaccess', 'deny from all' );
        }
    }


    private function export_database( $timestamp = null ) {
        global $wpdb;
        $timestamp = $timestamp ? $timestamp : time();
        $file_path = $this->backup_dir . "/db_backup_{$timestamp}.sql";
        $handle = fopen( $file_path, 'w' );

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        foreach ( $tables as $table ) {
            $table_name = $table[0];
            
            // DROP TABLE
            fwrite( $handle, "DROP TABLE IF EXISTS `$table_name`;\n" );

            // Create table structure
            $create_table = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_A );
            if ( isset( $create_table['Create Table'] ) ) {
                fwrite( $handle, $create_table['Create Table'] . ";\n\n" );
            }

            // Get table data in chunks to prevent memory exhaustion
            $offset = 0;
            $limit = 1000;
            
            while ( true ) {
                $rows = $wpdb->get_results( "SELECT * FROM `$table_name` LIMIT $limit OFFSET $offset", ARRAY_A );
                
                if ( empty( $rows ) ) {
                    break;
                }

                foreach ( $rows as $row ) {
                    $values = array_values( $row );
                    $escaped_values = array();
                    foreach ( $values as $value ) {
                        if ( is_null( $value ) ) {
                            $escaped_values[] = 'NULL';
                        } else {
                            $escaped_values[] = "'" . $wpdb->_escape( $value ) . "'";
                        }
                    }
                    $values_str = implode( ",", $escaped_values );
                    fwrite( $handle, "INSERT INTO `$table_name` VALUES ($values_str);\n" );
                }
                
                $offset += $limit;
                unset( $rows ); // Free up memory
            }
            fwrite( $handle, "\n" );
        }

        fclose( $handle );
        return $file_path;
    }

    public function init_backup() {
        $this->set_progress( 'Initializing backup...' );
        $domain = parse_url( get_site_url(), PHP_URL_HOST );
        $domain = str_replace( '.', '-', $domain ); // Convert dots to dashes for cleaner filename
        $zip_name = $domain . '_backup_' . date( 'Y-m-d_H-i-s' ) . '.mpack';
        update_option( 'abg_current_zip_name', $zip_name );
        
        // Clear old temp data
        $file_list_path = $this->backup_dir . '/file_list.txt';
        if ( file_exists( $file_list_path ) ) @unlink( $file_list_path );
        
        update_option( 'abg_backup_current_index', 0 );
        update_option( 'abg_backup_total_files', 0 );
        update_option( 'abg_scan_queue', array( ABSPATH ) );
        update_option( 'abg_scanned_dirs', array() );
        
        return array( 'zip_name' => $zip_name );
    }

    public function export_db_step() {
        $this->set_progress( 'Exporting database...' );
        $zip_name = get_option( 'abg_current_zip_name' );
        $zip_path = $this->backup_dir . '/' . $zip_name;
        
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE ) === TRUE ) {
            $db_file = $this->export_database();
            if ( $db_file ) {
                $zip->addFile( $db_file, 'database.sql' );
            }
            $zip->close();
            if ( $db_file ) @unlink( $db_file );
            return true;
        }
        return false;
    }

    public function scan_files_batch( $batch_size = 300 ) {
        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '1024M' );

        $queue = get_option( 'abg_scan_queue', array() );
        if ( empty( $queue ) ) {
            $this->set_progress( 'Scanning complete. Preparing for zipping...' );
            return true;
        }

        $file_list_path = $this->backup_dir . '/file_list.txt';
        $handle = fopen( $file_list_path, 'a' );
        
        $exclude_folders = array( 
            'node_modules', '.git', 'abg-backups', 'cache', 'upgrade', 'tmp', 'bower_components',
            'et-cache', 'elementor/css', 'wpforms', 'backwpup-', 'updraft', 'wp-optimize-backups',
            'wpo_cache', 'autoptimize', 'wp-fastest-cache', 'litespeed', 'object-cache.php'
        );

        $processed = 0;
        $total_scanned = (int) get_option( 'abg_backup_total_files', 0 );
        // Use associative array for faster lookup
        $scanned_dirs = get_option( 'abg_scanned_dirs', array() );
        if ( ! is_array( $scanned_dirs ) || ( ! empty($scanned_dirs) && ! isset($scanned_dirs[0]) && count($scanned_dirs) > 0 ) ) {
            // Already associative
        } else {
            $scanned_dirs = array_flip( $scanned_dirs );
        }

        $start_time = time();
        @ini_set( 'max_execution_time', 300 );

        while ( ! empty( $queue ) && $processed < $batch_size ) {
            // Check if we've been running too long (max 25s per AJAX call)
            if ( time() - $start_time > 20 ) break; 

            $current_dir = array_shift( $queue );
            
            // Normalize path for lookup
            $norm_dir = rtrim( str_replace( '\\', '/', $current_dir ), '/' );
            
            // Skip if already scanned or doesn't exist
            if ( isset( $scanned_dirs[$norm_dir] ) || ! is_dir( $current_dir ) ) {
                $processed++; // Count as processed to avoid infinite loops on unreadable dirs
                continue;
            }
            $scanned_dirs[$norm_dir] = 1;

            $items = @scandir( $current_dir );
            if ( ! $items ) {
                $processed++;
                continue;
            }

            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;
                $path = $current_dir . DIRECTORY_SEPARATOR . $item;

                // Fast exclusion check
                $is_excluded = false;
                $base_name = basename( $path );
                if ( in_array( $base_name, $exclude_folders ) ) {
                    $is_excluded = true;
                } else {
                    foreach ( $exclude_folders as $folder ) {
                        // Check for both separators
                        if ( strpos( $path, '/' . $folder . '/' ) !== false || strpos( $path, '\\' . $folder . '\\' ) !== false ) {
                            $is_excluded = true;
                            break;
                        }
                    }
                }
                
                if ( $is_excluded ) continue;

                if ( is_dir( $path ) ) {
                    $queue[] = $path;
                } else {
                    fwrite( $handle, $path . PHP_EOL );
                    $total_scanned++;
                }
            }
            $processed++;
            
            // Safety: Check if we are running low on memory
            if ( memory_get_usage() > 512 * 1024 * 1024 ) break; 
        }

        fclose( $handle );
        update_option( 'abg_scan_queue', $queue );
        update_option( 'abg_backup_total_files', $total_scanned );
        
        // Keep scanned_dirs reasonably sized
        if ( count( $scanned_dirs ) > 2000 ) {
            $scanned_dirs = array_slice( $scanned_dirs, -2000, null, true );
        }
        update_option( 'abg_scanned_dirs', $scanned_dirs );
        
        $this->set_progress( sprintf( 'Scanning files... (%d found)', $total_scanned ) );
        
        return count( $queue ) === 0 ? true : $total_scanned;
    }

    public function process_zip_batch( $batch_size = 700 ) {
        @set_time_limit( 300 );
        @ini_set( 'memory_limit', '1024M' );

        $index = get_option( 'abg_backup_current_index', 0 );
        $total_files = get_option( 'abg_backup_total_files', 0 );
        $zip_name = get_option( 'abg_current_zip_name' );
        $zip_path = $this->backup_dir . '/' . $zip_name;
        $file_list_path = $this->backup_dir . '/file_list.txt';

        if ( $index >= $total_files ) return true;

        if ( ! file_exists( $file_list_path ) ) return new WP_Error( 'file_missing', 'File list missing.' );

        $zip = new ZipArchive();
        $open_res = $zip->open( $zip_path );
        if ( $open_res === TRUE ) {
            $file_obj = new SplFileObject( $file_list_path );
            $file_obj->seek( $index );
            
            $processed = 0;
            $start_time = time();

            while ( ! $file_obj->eof() && $processed < $batch_size ) {
                if ( time() - $start_time > 20 ) break; // Safety timeout

                $file_path = trim( $file_obj->current() );
                if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
                    $relative_path = ltrim( substr( $file_path, strlen( ABSPATH ) ), DIRECTORY_SEPARATOR . '\\/' );
                    $relative_path = str_replace( '\\', '/', $relative_path );
                    
                    $zip->addFile( $file_path, $relative_path );
                    
                    // Try to set maximum compression for this file
                    if ( method_exists( $zip, 'setCompressionName' ) && defined('ZipArchive::CM_DEFLATE') ) {
                        $zip->setCompressionName( $relative_path, ZipArchive::CM_DEFLATE, 9 );
                    }
                }
                $file_obj->next();
                $processed++;
            }
            $zip->close();
            
            $new_index = $index + $processed;
            update_option( 'abg_backup_current_index', $new_index );
            
            $progress_percent = $total_files > 0 ? round(($new_index / $total_files) * 100) : 0;
            $this->set_progress( sprintf( 'Packing files into .mpack... %d%% (%d/%d)', $progress_percent, $new_index, $total_files ) );

            // Force garbage collection
            if ( function_exists('gc_collect_cycles') ) gc_collect_cycles();

            return ( $new_index >= $total_files ) ? true : $new_index;
        }
        return new WP_Error( 'zip_open_fail', 'Failed to open zip file (Code: ' . $open_res . ')' );
    }

    private function get_all_files( $dir ) {
        $file_list = array();
        $exclude_folders = array( 
            'node_modules', '.git', 'abg-backups', 'cache', 'upgrade', 'tmp', 'bower_components',
            'et-cache', 'elementor/css', 'wpforms', 'backwpup-', 'updraft', 'wp-optimize-backups'
        );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ( $iterator as $file ) {
            $file_path = $file->getRealPath();
            if ( ! $file_path || ! $file->isReadable() ) continue;

            $skip = false;
            foreach ( $exclude_folders as $folder ) {
                if ( strpos( $file_path, DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR ) !== false ) {
                    $skip = true;
                    break;
                }
            }
            if ( ! $skip ) {
                $file_list[] = $file_path;
            }
        }
        return $file_list;
    }

    public function start_resumable_upload() {
        $zip_name = get_option( 'abg_current_zip_name' );
        $zip_path = $this->backup_dir . '/' . $zip_name;
        if ( ! file_exists( $zip_path ) ) return new WP_Error( 'zip_missing', 'Zip file not found.' );

        $file_size = filesize( $zip_path );
        $gdrive = new ABG_GDrive_Service();
        $folder_id = $gdrive->get_or_create_folder( 'ABG Backups (mpack)' );
        
        $session_uri = $gdrive->init_resumable_session( $zip_name, $file_size, $folder_id );
        if ( is_wp_error( $session_uri ) ) return $session_uri;

        update_option( 'abg_upload_session_uri', $session_uri );
        update_option( 'abg_upload_offset', 0 );

        return array(
            'session_uri' => $session_uri,
            'file_size'   => $file_size
        );
    }

    public function upload_chunk_step() {
        $zip_name = get_option( 'abg_current_zip_name' );
        $zip_path = $this->backup_dir . '/' . $zip_name;
        $session_uri = get_option( 'abg_upload_session_uri' );
        $offset = (int) get_option( 'abg_upload_offset', 0 );
        $file_size = filesize( $zip_path );

        if ( $offset >= $file_size ) return true;

        $gdrive = new ABG_GDrive_Service();
        $chunk_size = 8 * 1024 * 1024; // Increased to 8MB chunk for faster upload
        $result = $gdrive->upload_chunk_direct( $zip_path, $session_uri, $offset, $chunk_size );

        if ( is_wp_error( $result ) ) return $result;

        update_option( 'abg_upload_offset', $result['next_offset'] );
        
        $progress = round( ($result['next_offset'] / $file_size) * 100 );
        $this->set_progress( "Uploading to Google Drive... ({$progress}%)" );

        return array(
            'offset' => $result['next_offset'],
            'done'   => $result['next_offset'] >= $file_size
        );
    }

    public function finalize_backup() {
        $zip_name = get_option( 'abg_current_zip_name' );
        $this->log_backup( $zip_name );
        
        // Clean up temp options and files
        delete_option( 'abg_backup_file_list' );
        delete_option( 'abg_backup_current_index' );
        delete_option( 'abg_backup_total_files' );
        delete_option( 'abg_current_zip_name' );
        delete_option( 'abg_upload_session_uri' );
        delete_option( 'abg_upload_offset' );
        
        $file_list_path = $this->backup_dir . '/file_list.txt';
        if ( file_exists( $file_list_path ) ) @unlink( $file_list_path );
        
        $this->set_progress( 'Backup completed successfully!' );
        return true;
    }

    public function create_full_backup( $force = false ) {
        if ( ! $force && ! $this->has_changes() ) {
            return false;
        }

        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '1024M' );

        // 1. Init
        $init = $this->init_backup();
        $zip_name = $init['zip_name'];

        // 2. Export DB
        $this->export_db_step();

        // 3. Scan Files
        while ( $this->scan_files_batch( 500 ) !== true ) {
            // Keep scanning
        }

        // 4. Zip Files (Optimized for scheduled backup: Batching for memory)
        $total_files = get_option( 'abg_backup_total_files', 0 );
        $zip_path = $this->backup_dir . '/' . $zip_name;
        $file_list_path = $this->backup_dir . '/file_list.txt';

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) === TRUE ) {
            $file_obj = new SplFileObject( $file_list_path );
            $count = 0;
            foreach ( $file_obj as $line ) {
                $file_path = trim( $line );
                if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
                    $relative_path = ltrim( substr( $file_path, strlen( ABSPATH ) ), DIRECTORY_SEPARATOR . '\\/' );
                    $relative_path = str_replace( '\\', '/', $relative_path );
                    $zip->addFile( $file_path, $relative_path );
                    if ( method_exists( $zip, 'setCompressionName' ) && defined('ZipArchive::CM_DEFLATE') ) {
                        $zip->setCompressionName( $relative_path, ZipArchive::CM_DEFLATE, 9 );
                    }
                }
                
                $count++;
                // Close and reopen every 500 files to save memory
                if ( $count % 500 === 0 ) {
                    $zip->close();
                    if ( $zip->open( $zip_path ) !== TRUE ) break;
                }
            }
            if ( $zip->status === ZipArchive::ER_OK ) $zip->close();
        }

        // 5. Upload to Google Drive
        $upload_init = $this->start_resumable_upload();
        if ( ! is_wp_error( $upload_init ) ) {
            while ( true ) {
                $chunk = $this->upload_chunk_step();
                if ( is_wp_error( $chunk ) || $chunk === true || ( isset($chunk['done']) && $chunk['done'] ) ) {
                    break;
                }
            }
        }

        // 6. Finalize
        return $this->finalize_backup();
    }

    public function restore_backup( $file_id, $file_name ) {
        $zip_path = $this->backup_dir . '/' . $file_name;
        $gdrive = new ABG_GDrive_Service();

        $this->set_progress( 'Downloading backup from Google Drive...' );
        $download = $gdrive->download_file( $file_id, $zip_path );
        if ( is_wp_error( $download ) ) return $download;

        // Legacy support: Run steps sequentially (might timeout on very large sites, but works for GDrive small restores)
        $this->process_restore_step( $zip_path, 'init' );
        $this->process_restore_step( $zip_path, 'wipe' );
        $index = 0;
        while ( ( $index = $this->process_restore_step( $zip_path, 'extract', $index ) ) !== true ) {
            if ( is_wp_error( $index ) ) return $index;
        }
        $this->process_restore_step( $zip_path, 'import_db' );
        return $this->process_restore_step( $zip_path, 'finalize' );
    }

    public function restore_backup_from_file( $zip_path ) {
        $this->process_restore_step( $zip_path, 'init' );
        $this->process_restore_step( $zip_path, 'wipe' );
        $index = 0;
        while ( ( $index = $this->process_restore_step( $zip_path, 'extract', $index ) ) !== true ) {
            if ( is_wp_error( $index ) ) return $index;
        }
        $this->process_restore_step( $zip_path, 'import_db' );
        return $this->process_restore_step( $zip_path, 'finalize' );
    }

    public function extract_files_batch( $zip_path, $start_index = 0, $batch_size = 300 ) {
        @set_time_limit( 60 );
        
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== TRUE ) {
            return new WP_Error( 'zip_open_fail', 'Failed to open zip for extraction.' );
        }

        $num_files = $zip->numFiles;
        $processed = 0;
        $current_index = $start_index;

        while ( $current_index < $num_files && $processed < $batch_size ) {
            $filename = $zip->getNameIndex( $current_index );
            
            // Try to extract individual file
            if ( ! $zip->extractTo( ABSPATH, $filename ) ) {
                if ( substr( $filename, -1 ) !== '/' && substr( $filename, -1 ) !== '\\' ) {
                    $zip->close();
                    return new WP_Error( 'extract_fail', 'Failed to extract: ' . $filename );
                }
            }
            
            $current_index++;
            $processed++;
        }

        $zip->close();
        
        $progress = round( ($current_index / $num_files) * 100 );
        $this->set_progress( "Extracting files... {$progress}% ({$current_index}/{$num_files})" );

        return ( $current_index >= $num_files ) ? true : $current_index;
    }


    public function process_restore_step( $zip_path, $step = 'init', $index = 0 ) {
        switch ( $step ) {
            case 'init':
                if ( ! file_exists( $zip_path ) ) return new WP_Error( 'not_found', 'Backup file not found on server.' );
                $zip = new ZipArchive();
                if ( $zip->open( $zip_path ) !== TRUE ) return new WP_Error( 'open_fail', 'Failed to open backup file.' );
                $total = $zip->numFiles;
                $zip->close();
                return array( 'total_files' => $total );
                
            case 'wipe':
                $this->set_progress( 'Preparing site for restoration...' );
                // We no longer wipe files here because it deletes the WordPress core files
                // needed to continue the AJAX restoration process. 
                // Extraction will naturally overwrite existing files.
                return true;
                
            case 'extract':
                return $this->extract_files_batch( $zip_path, $index, 300 );
                
            case 'import_db':
                $this->set_progress( 'Importing database SQL...' );
                $db_file = ABSPATH . 'database.sql';
                if ( file_exists( $db_file ) ) {
                    $result = $this->import_sql( $db_file );
                    @unlink( $db_file );
                    if ( is_wp_error( $result ) ) return $result;
                }
                return true;
                
            case 'finalize':
                $this->set_progress( 'Running search and replace for new domain...' );
                $this->run_search_and_replace();
                $this->set_progress( 'Cleaning up temporary files...' );
                @unlink( $zip_path );
                flush_rewrite_rules();
                delete_transient( 'abg_backup_status' );
                return true;
        }
        return new WP_Error( 'invalid_step', 'Invalid restore step.' );
    }

    private function run_search_and_replace() {
        global $wpdb;

        $new_url = untrailingslashit( get_site_url() );
        
        // Try to find the OLD URL from the imported options table
        $old_url = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
        $old_url = untrailingslashit( $old_url );

        if ( empty($old_url) || $old_url === $new_url ) {
            return; // No migration needed, same domain or failed to find old URL
        }

        // Tables to process
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        
        foreach ( $tables as $table_row ) {
            $table = $table_row[0];
            if ( strpos( $table, 'users' ) !== false ) continue;

            $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
            $primary_key = '';
            foreach($columns as $col) { if($col['Key'] === 'PRI') { $primary_key = $col['Field']; break; } }
            
            if (empty($primary_key)) continue;

            $where_clause = implode(' OR ', array_map(function($c) use ($old_url) { return "`{$c['Field']}` LIKE '%" . esc_sql($old_url) . "%'"; }, $columns));
            
            // Fetch only primary keys first to save memory
            $matching_pks = $wpdb->get_col( "SELECT `{$primary_key}` FROM `{$table}` WHERE {$where_clause}" );
            
            if ( empty( $matching_pks ) ) continue;

            $pk_chunks = array_chunk( $matching_pks, 1000 );

            foreach ( $pk_chunks as $chunk ) {
                // Ensure values are properly escaped for the IN clause
                $pk_list = "'" . implode("','", array_map('esc_sql', $chunk)) . "'";
                $rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE `{$primary_key}` IN ({$pk_list})", ARRAY_A );

                foreach ( $rows as $row ) {
                    $update_data = array();
                    foreach ( $row as $col_name => $value ) {
                        if ( empty($value) || is_numeric($value) ) continue;
                        
                        $new_value = $this->recursive_replace( $old_url, $new_url, $value );
                        if ( $new_value !== $value ) {
                            $update_data[$col_name] = $new_value;
                        }
                    }

                    if ( ! empty( $update_data ) ) {
                        $wpdb->update( $table, $update_data, array( $primary_key => $row[$primary_key] ) );
                    }
                }
                unset( $rows ); // Free memory
            }
        }
    }

    private function recursive_replace( $search, $replace, $data ) {
        if ( is_string( $data ) && ( $serialized = @unserialize( $data ) ) !== false ) {
            $unserialized = $this->recursive_replace( $search, $replace, $serialized );
            return serialize( $unserialized );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[$key] = $this->recursive_replace( $search, $replace, $value );
            }
        } elseif ( is_string( $data ) ) {
            $data = str_replace( $search, $replace, $data );
        }

        return $data;
    }

    private function import_sql( $file_path ) {
        global $wpdb;

        // 1. Detect old prefix from the beginning of the file
        $old_prefix = '';
        $sample_handle = fopen( $file_path, 'r' );
        if ( $sample_handle ) {
            $sample_content = fread( $sample_handle, 50000 ); // Read first 50KB to find options table
            if ( preg_match( '/CREATE TABLE `([^`]+)options`/', $sample_content, $matches ) ) {
                $old_prefix = $matches[1];
            }
            fclose( $sample_handle );
        }

        // 2. Import line by line
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) return new WP_Error( 'file_read_error', 'Cannot open SQL file.' );

        $query = '';
        $wpdb->query( "SET foreign_key_checks = 0" );

        while ( ( $line = fgets( $handle ) ) !== false ) {
            $trimmed_line = trim( $line );
            
            // Skip comments and empty lines
            if ( empty( $trimmed_line ) || strpos( $trimmed_line, '--' ) === 0 || strpos( $trimmed_line, '/*' ) === 0 ) {
                continue;
            }

            $query .= $line;

            // If the line ends with a semicolon, it's the end of a query
            if ( substr( $trimmed_line, -1 ) == ';' ) {
                // Perform prefix replacement if needed
                if ( ! empty( $old_prefix ) && $old_prefix !== $wpdb->prefix ) {
                    $query = str_replace( "`{$old_prefix}", "`{$wpdb->prefix}", $query );
                }
                
                $wpdb->query( $query );
                $query = '';
            }
        }

        // 3. Fix prefix-based meta keys in options and usermeta if prefix changed
        if ( ! empty( $old_prefix ) && $old_prefix !== $wpdb->prefix ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
                $old_prefix, $wpdb->prefix, $old_prefix . '%'
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s",
                $old_prefix, $wpdb->prefix, $old_prefix . '%'
            ) );
        }

        $wpdb->query( "SET foreign_key_checks = 1" );
        fclose( $handle );

        return true;
    }

    private function log_backup( $filename ) {
        $logs = get_option( 'abg_backup_logs', array() );
        array_unshift( $logs, array(
            'time' => time(),
            'file' => $filename
        ) );
        $logs = array_slice( $logs, 0, 10 ); // Keep last 10 logs
        update_option( 'abg_backup_logs', $logs );
    }

    public function set_progress( $message ) {
        update_option( 'abg_backup_status', $message );
    }

    public function has_changes() {
        $last_state = get_option( 'abg_last_state' );
        if ( ! $last_state ) return true; // First time backup

        $current_state = $this->get_site_state();
        return $current_state !== $last_state;
    }

    private function get_site_state() {
        global $wpdb;
        
        // 1. DB State: Sum of rows and update times
        $db_state = '';
        $tables = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
        foreach ( $tables as $table ) {
            $db_state .= $table['Name'] . $table['Rows'] . $table['Update_time'] . $table['Auto_increment'];
        }

        // 2. File State: Last modified time of wp-content
        $file_state = 0;
        if ( file_exists( WP_CONTENT_DIR ) ) {
            $file_state = $this->get_last_modified_time( WP_CONTENT_DIR );
        }

        return md5( $db_state . $file_state );
    }

    private function get_last_modified_time( $path ) {
        $last_mtime = filemtime( $path );
        
        if ( is_dir( $path ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $count = 0;
            foreach ( $iterator as $file ) {
                $count++;
                if ( $count % 1000 === 0 ) {
                    $this->set_progress( sprintf( 'Checking for changes... (%d files scanned)', $count ) );
                }
                // Skip our own backups folder to avoid false positives
                if ( strpos( $file->getPathname(), 'abg-backups' ) !== false ) continue;
                
                $mtime = $file->getMTime();
                if ( $mtime > $last_mtime ) {
                    $last_mtime = $mtime;
                }
            }
        }
        return $last_mtime;
    }

    private function wipe_site_files( $keep_zip_path ) {
        $iterator = new DirectoryIterator( ABSPATH );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) continue;
            
            $path = $item->getPathname();
            
            // Do NOT delete the backup directory where the zip is located
            if ( strpos( $path, 'abg-backups' ) !== false ) continue;
            
            // Do NOT delete the zip file we are currently using
            if ( $path === $keep_zip_path ) continue;

            if ( $item->isDir() ) {
                $this->recursive_delete( $path );
            } else {
                @unlink( $path );
            }
        }
    }

    private function recursive_delete( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) {
            $path = "$dir/$file";
            ( is_dir( $path ) ) ? $this->recursive_delete( $path ) : @unlink( $path );
        }
        return @rmdir( $dir );
    }

    private function wipe_database() {
        global $wpdb;
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        if ( empty( $tables ) ) return;

        $wpdb->query( "SET foreign_key_checks = 0" );
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table[0]}`" );
        }
        $wpdb->query( "SET foreign_key_checks = 1" );
    }
}
