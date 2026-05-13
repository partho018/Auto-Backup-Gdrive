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

    // Manual Upload (Step 1: Upload Only)
    $('#abg-manual-upload-btn').on('click', function() {
        const fileInput = $('#abg-upload-file')[0];
        if (fileInput.files.length === 0) {
            alert('Please select a .zip backup file first.');
            return;
        }

        const file = fileInput.files[0];
        if (file.name.split('.').pop() !== 'zip') {
            alert('Invalid file format. Only .zip files are allowed.');
            return;
        }

        $modal.css('display', 'flex');
        $progressTitle.text('Uploading Backup...');
        updateProgress(0, 'Preparing upload...');

        const chunkSize = 1 * 1024 * 1024; // 1MB
        const totalChunks = Math.ceil(file.size / chunkSize);
        
        sendChunk(0, 0);

        function sendChunk(index, retryCount) {
            if (index >= totalChunks) {
                finalizeUploadOnly();
                return;
            }

            const start = index * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);

            const percent = Math.round((index / totalChunks) * 100);
            updateProgress(percent, 'Uploading file to server... ' + percent + '%');

            const formData = new FormData();
            formData.append('action', 'abg_manual_upload_chunk');
            formData.append('nonce', abg_vars.nonce);
            formData.append('file_name', file.name);
            formData.append('chunk_index', index);
            formData.append('total_chunks', totalChunks);
            formData.append('file_chunk', chunk, 'chunk.blob');

            $.ajax({
                url: abg_vars.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        setTimeout(() => {
                            sendChunk(index + 1, 0);
                        }, 100);
                    } else {
                        handleRestoreError('Server Error: ' + response.data);
                    }
                },
                error: function(xhr) {
                    if (retryCount < 3) {
                        setTimeout(() => {
                            sendChunk(index, retryCount + 1);
                        }, 1000);
                    } else {
                        handleRestoreError('Upload failed at chunk ' + (index + 1));
                    }
                }
            });
        }

        function finalizeUploadOnly() {
            updateProgress(100, 'Upload complete!');
            setTimeout(() => {
                $modal.hide();
                $('#abg-upload-container').hide();
                $('#uploaded-file-name').text(file.name);
                $('#abg-restore-ready-box').fadeIn();
            }, 1000);
        }
    });

    // Step 2: Manual Restore
    $('#abg-manual-restore-btn').on('click', function() {
        const fileName = $('#uploaded-file-name').text();
        if (!fileName) return;

        if (!confirm('WARNING: This will overwrite your current site with the uploaded backup. Are you sure?')) {
            return;
        }

        $modal.css('display', 'flex');
        $progressTitle.text('Initializing Restore...');
        updateProgress(0, 'Preparing files...');

        $.post(abg_vars.ajax_url, {
            action: 'abg_manual_restore',
            nonce: abg_vars.nonce,
            file_name: fileName
        }, function(response) {
            if (response.success) {
                const zipPath = response.data.zip_path;
                runRestoreStep(zipPath, 'init', 0);
            } else {
                handleRestoreError(response.data);
            }
        }).fail(function(xhr) {
            handleRestoreError('Finalization failed (Status: ' + xhr.status + ')');
        });
    });

    $('#abg-upload-another').on('click', function() {
        $('#abg-restore-ready-box').hide();
        $('#abg-upload-container').fadeIn();
        $('#abg-upload-file').val('');
        $('#abg-selected-file-name').hide().text('');
    });

    // Trigger file input when clicking the upload box
    $('#abg-upload-container').on('click', function(e) {
        if (e.target.id !== 'abg-manual-upload-btn') {
            $('#abg-upload-file').click();
        }
    });

    // Show selected file name
    $('#abg-upload-file').on('change', function() {
        const file = this.files[0];
        if (file) {
            $('#abg-selected-file-name').text('Selected: ' + file.name).fadeIn();
        } else {
            $('#abg-selected-file-name').hide().text('');
        }
    });

    // Local Restore button
    $(document).on('click', '.abg-local-restore-btn', function() {
        const zipPath = $(this).data('path');
        const fileName = $(this).data('name');

        if (confirm('WARNING: This will overwrite your current site with "' + fileName + '". Are you sure?')) {
            $modal.css('display', 'flex');
            $progressTitle.text('Initializing Restore...');
            runRestoreStep(zipPath, 'init', 0);
        }
    });



    // ─── Google Drive → Download → Restore ───────────────────────────────────
    function startRestore(fileId, fileName) {
        $progressTitle.text('Downloading from Google Drive...');
        updateProgress(10, 'Connecting to Google Drive...');

        $.post(abg_vars.ajax_url, {
            action: 'abg_download_from_gdrive',
            nonce:  abg_vars.nonce,
            file_id:   fileId,
            file_name: fileName
        }, function(response) {
            if (response.success) {
                updateProgress(40, 'Download complete. Starting restore...');
                const zipPath = response.data.zip_path;
                runRestoreStep(zipPath, 'init', 0);
            } else {
                handleRestoreError('Download failed: ' + response.data);
            }
        }).fail(function(xhr) {
            handleRestoreError('Download request failed (Status: ' + xhr.status + ')');
        });
    }
    // ─────────────────────────────────────────────────────────────────────────

    function runRestoreStep(zipPath, step, index, totalFiles = 0) {
        let statusMsg = '';
        switch(step) {
            case 'init': statusMsg = 'Initializing restoration...'; break;
            case 'wipe': statusMsg = 'Wiping existing data...'; break;
            case 'extract': statusMsg = 'Extracting files...'; break;
            case 'import_db': statusMsg = 'Importing database...'; break;
            case 'finalize': statusMsg = 'Finalizing and cleaning up...'; break;
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
                    clearInterval(progressInterval);
                    progressInterval = null;
                    updateProgress(100, 'Restore completed! Domain fix running in background...');
                    $progressTitle.text('Success!');
                    setTimeout(() => { location.reload(); }, 3000);
                }
            } else {
                handleRestoreError(response.data);
            }
        }).fail(function(xhr) {
            // Special case: if FINALIZE times out, the restore already succeeded.
            // The DB is imported, URLs are updated. Finalize only does cleanup + schedules background job.
            // Treat timeout on finalize as SUCCESS.
            if (step === 'finalize') {
                clearInterval(progressInterval);
                progressInterval = null;
                updateProgress(100, 'Restore completed! Admin panel will reload shortly...');
                $progressTitle.text('Success!');
                setTimeout(() => { location.reload(); }, 4000);
            } else {
                handleRestoreError('Server timeout or error during ' + step);
            }
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

    // Auto-save backup toggle
    $('input[name="abg_settings[backup_enabled]"]').on('change', function() {
        const isEnabled = $(this).is(':checked') ? 1 : 0;
        const $form = $(this).closest('form');
        
        // Use standard WordPress options saving for the whole form or just this field?
        // Since it's part of a group, we can just trigger the form submission via AJAX
        // or a custom handler. Let's add a custom handler for better control.
        $.post(abg_vars.ajax_url, {
            action: 'abg_save_toggle',
            nonce: abg_vars.nonce,
            enabled: isEnabled
        });
    });

    // Delete Local Backup
    $(document).on('click', '.abg-local-delete-btn', function() {
        const fileName = $(this).data('name');
        const $row = $(this).closest('.abg-log-item');

        if (confirm('Are you sure you want to permanently delete this backup from the server?')) {
            $.post(abg_vars.ajax_url, {
                action: 'abg_delete_local_backup',
                nonce: abg_vars.nonce,
                file_name: fileName
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error: ' + response.data);
                }
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
