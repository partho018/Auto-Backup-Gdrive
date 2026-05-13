jQuery(document).ready(function($) {
    const $modal = $('#abg-modal');
    const $progressFill = $('.abg-progress-fill');
    const $progressStatus = $('#abg-progress-status');
    const $progressTitle = $('#abg-progress-title');
    const $runBtn = $('#abg-run-backup');

    $runBtn.on('click', function() {
        $modal.css('display', 'flex');
        startBackup();
    });

    $('.abg-close').on('click', function() {
        $modal.hide();
    });

    // Restore Backup
    $(document).on('click', '.abg-restore-btn', function() {
        const fileId = $(this).data('id');
        const fileName = $(this).data('name');

        if (confirm('WARNING: This will overwrite your current site files and database. Are you sure you want to restore this backup?')) {
            $progressTitle.text('Restoring Site...');
            $modal.css('display', 'flex');
            startRestore(fileId, fileName);
        }
    });

    // Manual Upload & Restore (Sequential Flow)
    $('#abg-upload-restore-btn').on('click', function() {
        const fileInput = $('#abg-upload-file')[0];
        if (fileInput.files.length === 0) {
            alert('Please select a .zip backup file first.');
            return;
        }

        const file = fileInput.files[0];
        if (!confirm('WARNING: This will overwrite your current site with the uploaded backup. Are you sure?')) {
            return;
        }

        $modal.css('display', 'flex');
        $progressTitle.text('Uploading Backup...');
        updateProgress(5, 'Uploading file to server...');

        const formData = new FormData();
        formData.append('action', 'abg_manual_restore');
        formData.append('nonce', abg_vars.nonce);
        formData.append('backup_file', file);

        $.ajax({
            url: abg_vars.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = Math.round((evt.loaded / evt.total) * 100);
                        updateProgress(percentComplete, 'Uploading file to server... ' + percentComplete + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    const zipPath = response.data.zip_path;
                    runRestoreStep(zipPath, 'init', 0);
                } else {
                    handleRestoreError(response.data);
                }
            },
            error: function() {
                handleRestoreError('Upload failed. The file might be too large for your server limits.');
            }
        });
    });

    function runRestoreStep(zipPath, step, index, totalFiles = 0) {
        let statusMsg = '';
        switch(step) {
            case 'init': statusMsg = 'Initializing restoration...'; break;
            case 'wipe': statusMsg = 'Wiping existing data...'; break;
            case 'extract': statusMsg = 'Extracting files...'; break;
            case 'import_db': statusMsg = 'Importing database...'; break;
            case 'finalize': statusMsg = 'Finalizing migration...'; break;
        }
        
        $progressTitle.text('Restoring Site...');
        updateProgress(0, statusMsg); // Progress will be updated by fetchProgress transient

        $.post(abg_vars.ajax_url, {
            action: 'abg_manual_restore_step',
            nonce: abg_vars.nonce,
            zip_path: zipPath,
            step: step,
            index: index
        }, function(response) {
            if (response.success) {
                if (step === 'init') {
                    runRestoreStep(zipPath, 'wipe', 0, response.data.total_files);
                } else if (step === 'wipe') {
                    runRestoreStep(zipPath, 'extract', 0, totalFiles);
                } else if (step === 'extract') {
                    if (response.data === true) {
                        runRestoreStep(zipPath, 'import_db', 0);
                    } else {
                        // Continue extraction
                        runRestoreStep(zipPath, 'extract', response.data, totalFiles);
                    }
                } else if (step === 'import_db') {
                    runRestoreStep(zipPath, 'finalize', 0);
                } else if (step === 'finalize') {
                    updateProgress(100, 'Restore completed successfully!');
                    $progressTitle.text('Success!');
                    setTimeout(() => { location.reload(); }, 3000);
                }
            } else {
                handleRestoreError(response.data);
            }
        }).fail(function() {
            handleRestoreError('Server timeout or error during ' + step);
        });

        // Start progress polling
        if (!progressInterval) {
            progressInterval = setInterval(fetchProgress, 2000);
        }
    }

    function handleRestoreError(msg) {
        clearInterval(progressInterval);
        progressInterval = null;
        updateProgress(0, 'Error: ' + msg);
        $progressTitle.text('Restore Failed');
        alert('Restore Failed: ' + msg);
    }

    // Toggle Settings Sections
    $('.abg-section h3').on('click', function() {
        $(this).parent().find('.abg-settings-fields').slideToggle();
        $(this).find('.abg-toggle-settings').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
    });

    // Disconnect Account
    $('#abg-disconnect').on('click', function() {
        if (confirm('Are you sure you want to disconnect your Google account?')) {
            $.post(abg_vars.ajax_url, {
                action: 'abg_disconnect',
                nonce: abg_vars.nonce
            }, function() {
                location.reload();
            });
        }
    });

    let progressInterval;
    let isProcessHalted = false;

    function fetchProgress() {
        if (isProcessHalted) return;
        $.get(abg_vars.ajax_url, {
            action: 'abg_get_progress',
            nonce: abg_vars.nonce
        }, function(response) {
            if (isProcessHalted) return;
            if (response.success && response.data) {
                $progressStatus.text(response.data);
            }
        });
    }

    function startBackup() {
        $runBtn.prop('disabled', true);
        isProcessHalted = false;
        $modal.css('display', 'flex');
        updateProgress(2, 'Initializing backup...');
        
        progressInterval = setInterval(fetchProgress, 2000);

        // Step 1: Init
        $.post(abg_vars.ajax_url, {
            action: 'abg_init_backup',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                exportDB();
            } else {
                handleError(response.data || 'Initialization failed.');
            }
        }).fail(handleXhrError);
    }

    function exportDB() {
        if (isProcessHalted) return;
        updateProgress(5, 'Exporting database...');
        $.post(abg_vars.ajax_url, {
            action: 'abg_export_db',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                scanBatch();
            } else {
                handleError(response.data || 'DB Export failed.');
            }
        }).fail(handleXhrError);
    }

    function scanBatch() {
        if (isProcessHalted) return;
        $.post(abg_vars.ajax_url, {
            action: 'abg_scan_batch',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                if (response.data === true) {
                    // Scanning done, start zipping
                    fetchTotalFilesAndStartZip();
                } else {
                    scanBatch(); // Continue scanning
                }
            } else {
                handleError(response.data || 'Scanning failed.');
            }
        }).fail(handleXhrError);
    }

    function fetchTotalFilesAndStartZip() {
        $.get(abg_vars.ajax_url, {
            action: 'abg_get_progress',
            nonce: abg_vars.nonce,
            get_total: 1
        }, function(response) {
            const total = parseInt(response.data.total_files) || 0;
            processBatch(0, total);
        });
    }

    function processBatch(startIndex, totalFiles) {
        if (isProcessHalted) return;

        const progress = Math.min(10 + Math.round((startIndex / totalFiles) * 80), 90);
        updateProgress(progress, 'Zipping files... (' + startIndex + ' / ' + totalFiles + ')');

        $.post(abg_vars.ajax_url, {
            action: 'abg_zip_batch',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                const nextIndex = response.data.next_index;
                if (nextIndex === true || nextIndex >= totalFiles) {
                    startUpload();
                } else {
                    processBatch(nextIndex, totalFiles);
                }
            } else {
                handleError(response.data || 'Zipping failed.');
            }
        }).fail(handleXhrError);
    }

    function startUpload() {
        if (isProcessHalted) return;
        updateProgress(92, 'Preparing Google Drive upload...');
        $.post(abg_vars.ajax_url, {
            action: 'abg_start_upload',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                uploadChunk();
            } else {
                handleError(response.data || 'Upload initialization failed.');
            }
        }).fail(handleXhrError);
    }

    function uploadChunk() {
        if (isProcessHalted) return;
        $.post(abg_vars.ajax_url, {
            action: 'abg_upload_chunk',
            nonce: abg_vars.nonce
        }, function(response) {
            if (response.success) {
                if (response.data === true || response.data.done) {
                    finalizeBackup();
                } else {
                    uploadChunk(); // Continue next chunk
                }
            } else {
                handleError(response.data || 'Chunk upload failed.');
            }
        }).fail(handleXhrError);
    }

    function finalizeBackup() {
        updateProgress(95, 'Finalizing and uploading...');
        $.post(abg_vars.ajax_url, {
            action: 'abg_finalize_backup',
            nonce: abg_vars.nonce
        }, function(response) {
            clearInterval(progressInterval);
            if (response.success) {
                updateProgress(100, 'Backup completed!');
                $progressTitle.text('Success');
                setTimeout(() => { location.reload(); }, 2000);
            } else {
                handleError(response.data);
            }
        }).fail(handleXhrError);
    }

    function handleError(msg) {
        isProcessHalted = true;
        clearInterval(progressInterval);
        $progressStatus.text(msg);
        $progressTitle.text('Error');
        $runBtn.prop('disabled', false);
    }

    function handleXhrError(xhr) {
        let msg = 'Server Error (' + xhr.status + ')';
        if (xhr.status === 504 || xhr.status === 502) {
            msg = 'Gateway Timeout (504). Please try again, it will resume where it left off.';
        }
        handleError(msg);
    }

    function startRestore(fileId, fileName) {
        updateProgress(10, 'Preparing restoration...');
        progressInterval = setInterval(fetchProgress, 1500);
        
        $.ajax({
            url: abg_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'abg_restore_backup',
                nonce: abg_vars.nonce,
                file_id: fileId,
                file_name: fileName
            },
            success: function(response) {
                clearInterval(progressInterval);
                if (response.success) {
                    updateProgress(100, 'Restore completed successfully!');
                    $progressTitle.text('Done!');
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                } else {
                    updateProgress(0, 'Error: ' + response.data);
                    $progressTitle.text('Restore Failed');
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                updateProgress(0, 'A critical error occurred during restoration.');
                $progressTitle.text('Error');
            }
        });
    }

    function updateProgress(percent, status) {
        $progressFill.css('width', percent + '%');
        $progressStatus.text(status);
    }
});
