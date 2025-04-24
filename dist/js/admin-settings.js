/**
 * WordPress Hreflang Settings JS
 */
(function($) {
    'use strict';
    
    const settings = wpHreflangAdminSettings;
    let isProcessing = false;
    
    // DOM Elements
    const $form = $('#wp-hreflang-settings-form');
    const $saveButton = $('#save-settings-button');
    const $message = $('#wp-hreflang-settings-message');
    const $modal = $('#wp-hreflang-progress-modal');
    const $progressTitle = $('#wp-hreflang-progress-title');
    const $progressDesc = $('#wp-hreflang-progress-desc');
    const $siteProgressLabel = $('#wp-hreflang-site-progress-label');
    const $postProgressLabel = $('#wp-hreflang-post-progress-label');
    const $currentSite = $('#wp-hreflang-current-site');
    const $totalSites = $('#wp-hreflang-total-sites');
    const $currentPost = $('#wp-hreflang-current-post');
    const $totalPosts = $('#wp-hreflang-total-posts');
    const $siteProgress = $('#wp-hreflang-site-progress');
    const $postProgress = $('#wp-hreflang-post-progress');
    const $startButton = $('#wp-hreflang-start-button');
    const $cancelButton = $('#wp-hreflang-cancel-button');
    const $closeButton = $('#wp-hreflang-close-button');
    const $addLocaleButton = $('#add-locale');
    const $addArchiveButton = $('#add-archive-page');
    
    /**
     * Show a message to the user
     */
    function showMessage(text, type) {
        $message.removeClass('notice-success notice-error notice-warning notice-info')
            .addClass('notice-' + type)
            .html('<p>' + text + '</p>')
            .show();
            
        setTimeout(() => {
            $message.fadeOut();
        }, 5000);
    }
    
    /**
     * Initialize the settings page
     */
    function init() {
        // Form submission
        $form.on('submit', function(e) {
            e.preventDefault();
            
            if (isProcessing) {
                return;
            }
            
            saveSettings();
        });
        
        // Modal events
        $startButton.on('click', function() {
            startRebuild();
        });
        
        $cancelButton.on('click', function() {
            $modal.hide();
        });
        
        $closeButton.on('click', function() {
            $modal.hide();
        });
        
        // Locale handling
        $addLocaleButton.on('click', addLocaleRow);
        $(document).on('click', '.remove-locale', function() {
            $(this).closest('.locale-row').remove();
        });
        
        // Archive pages handling
        $addArchiveButton.on('click', addArchiveRow);
        $(document).on('click', '.remove-archive', function() {
            $(this).closest('.archive-page-row').remove();
        });
        
        // Auto-generate archive ID from name on blur
        $(document).on('blur', '.archive-name-input', function() {
            const idInput = $(this).siblings('.archive-id-input');
            if (idInput.val() === '') {
                idInput.val($(this).val().toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, ''));
            }
        });
    }
    
    /**
     * Add a new locale row
     */
    function addLocaleRow() {
        const sites = settings.sites;
        
        const siteOptions = sites.map(function(site) {
            return `<option value="${site.id}">${site.name}</option>`;
        }).join('');
        
        const row = `
            <div class="locale-row">
                <select name="locales[site_id][]" class="site-select">
                    <option value="">${settings.i18n.select_site}</option>
                    ${siteOptions}
                </select>
                <input type="text" 
                    name="locales[locale][]" 
                    value="" 
                    class="locale-input" 
                    placeholder="${settings.i18n.locale_placeholder}" />
                <button type="button" class="remove-locale button">${settings.i18n.remove}</button>
            </div>
        `;
        
        $('#wp-hreflang-locales').append(row);
    }
    
    /**
     * Add a new archive row
     */
    function addArchiveRow() {
        const template = `
            <div class="archive-page-row">
                <input type="text"
                    name="archive_pages[name][]"
                    class="archive-name-input"
                    placeholder="${settings.i18n.archive_name}" />
                <input type="text"
                    name="archive_pages[id][]"
                    class="archive-id-input"
                    placeholder="${settings.i18n.archive_id}" />
                <button type="button" class="remove-archive button">${settings.i18n.remove}</button>
            </div>
        `;
        
        $('#wp-hreflang-archive-pages').append(template);
    }
    
    /**
     * Save settings via AJAX
     */
    function saveSettings() {
        isProcessing = true;
        $saveButton.prop('disabled', true).text(settings.i18n.processing);
        
        const formData = $form.serializeArray();
        const data = {
            locales: { site_id: [], locale: [] },
            post_types: [],
            archive_pages: { name: [], id: [] },
            nonce: settings.nonce
        };
        
        // Process form data
        $.each(formData, function(i, field) {
            if (field.name === 'ignore_query_params') {
                data[field.name] = true;
            } else if (field.name.match(/^locales\[site_id\]/)) {
                data.locales.site_id.push(field.value);
            } else if (field.name.match(/^locales\[locale\]/)) {
                data.locales.locale.push(field.value);
            } else if (field.name === 'post_types[]') {
                data.post_types.push(field.value);
            } else if (field.name.match(/^archive_pages\[name\]/)) {
                data.archive_pages.name.push(field.value);
            } else if (field.name.match(/^archive_pages\[id\]/)) {
                data.archive_pages.id.push(field.value);
            }
        });
        
        // Save settings via AJAX
        $.ajax({
            url: settings.api.root + 'wp-hreflang/v1/update-settings',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', settings.api.nonce);
            },
            data: JSON.stringify(data),
            contentType: 'application/json',
            success: function(response) {
                showMessage(settings.i18n.success, 'success');
                
                // Show rebuild modal
                $progressTitle.text(settings.i18n.rebuild_title);
                $progressDesc.text(settings.i18n.rebuild_desc);
                $siteProgressLabel.text(settings.i18n.site_progress);
                $postProgressLabel.text(settings.i18n.post_progress);
                
                $startButton.show();
                $closeButton.hide();
                $modal.show();
            },
            error: function(error) {
                console.error('Error saving settings:', error);
                showMessage(settings.i18n.error + ': ' + (error.responseJSON?.message || 'Unknown error'), 'error');
            },
            complete: function() {
                isProcessing = false;
                $saveButton.prop('disabled', false).text(settings.i18n.save_settings);
            }
        });
    }
    
    /**
     * Start the rebuild process
     */
    function startRebuild() {
        isProcessing = true;
        $startButton.prop('disabled', true).text(settings.i18n.processing);
        
        // Start rebuild via AJAX
        $.ajax({
            url: settings.api.root + 'wp-hreflang/v1/rebuild/start',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', settings.api.nonce);
            },
            success: function(response) {
                if (!response.success) {
                    showMessage(settings.i18n.error + ': ' + response.message, 'error');
                    $modal.hide();
                    return;
                }
                
                updateProgress(response.status);
                
                // Start processing batches
                processNextBatch();
            },
            error: function(error) {
                console.error('Error starting rebuild:', error);
                showMessage(settings.i18n.error + ': ' + (error.responseJSON?.message || 'Unknown error'), 'error');
                $modal.hide();
            }
        });
    }
    
    /**
     * Process the next batch of posts
     */
    function processNextBatch() {
        $.ajax({
            url: settings.api.root + 'wp-hreflang/v1/rebuild/process',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', settings.api.nonce);
            },
            success: function(response) {
                if (!response.success) {
                    showMessage(settings.i18n.error + ': ' + response.message, 'error');
                    completeRebuild();
                    return;
                }
                
                updateProgress(response.status);
                
                // If not completed, process the next batch
                if (!response.status.completed) {
                    setTimeout(processNextBatch, 100); // Short delay to prevent overwhelming the server
                } else {
                    // Rebuild completed
                    completeRebuild();
                }
            },
            error: function(error) {
                console.error('Error processing batch:', error);
                showMessage(settings.i18n.error + ': ' + (error.responseJSON?.message || 'Unknown error'), 'error');
                completeRebuild();
            }
        });
    }
    
    /**
     * Update the progress UI
     */
    function updateProgress(status) {
        // Update site progress
        const siteProgress = status.current_site_index / status.total_sites * 100;
        $currentSite.text(status.current_site_index);
        $totalSites.text(status.total_sites);
        $siteProgress.css('width', siteProgress + '%');
        
        // Update post progress
        let postProgress = 0;
        if (status.total_posts > 0) {
            postProgress = status.current_post_index / status.total_posts * 100;
        }
        $currentPost.text(status.current_post_index);
        $totalPosts.text(status.total_posts);
        $postProgress.css('width', postProgress + '%');
    }
    
    /**
     * Complete the rebuild process
     */
    function completeRebuild() {
        isProcessing = false;
        $startButton.hide();
        $cancelButton.hide();
        $closeButton.show();
        
        // Set progress to 100%
        $siteProgress.css('width', '100%');
        $postProgress.css('width', '100%');
        
        // Update labels
        $siteProgressLabel.text(settings.i18n.complete);
        $postProgressLabel.text(settings.i18n.complete);
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        init();
    });
    
})(jQuery); 