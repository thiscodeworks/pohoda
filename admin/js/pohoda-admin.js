jQuery(document).ready(function($) {
    // Load Products
    $('#load-products').on('click', function() {
        var $button = $(this);
        var $count = $('#product-count');
        var $container = $('#products-table-container');

        $button.prop('disabled', true);
        $container.html('<div class="loading">Loading products...</div>');

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_products',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var products = response.data;
                    $count.text(products.length + ' products loaded');
                    
                    if (products.length > 0) {
                        var table = '<table class="wp-list-table widefat fixed striped">';
                        table += '<thead><tr>';
                        table += '<th>ID</th>';
                        table += '<th>Code</th>';
                        table += '<th>Name</th>';
                        table += '<th>Unit</th>';
                        table += '<th>Count</th>';
                        table += '<th>Purchasing Price</th>';
                        table += '<th>Selling Price</th>';
                        table += '</tr></thead><tbody>';
                        
                        products.forEach(function(product) {
                            table += '<tr>';
                            table += '<td>' + product.id + '</td>';
                            table += '<td>' + product.code + '</td>';
                            table += '<td>' + product.name + '</td>';
                            table += '<td>' + product.unit + '</td>';
                            table += '<td>' + product.count + '</td>';
                            table += '<td>' + product.purchasingPrice + '</td>';
                            table += '<td>' + product.sellingPrice + '</td>';
                            table += '</tr>';
                        });
                        
                        table += '</tbody></table>';
                        $container.html(table);
                    } else {
                        $container.html('<div class="notice notice-warning"><p>No products found.</p></div>');
                    }
                } else {
                    $container.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $container.html('<div class="notice notice-error"><p>Failed to load products. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Load Stores
    $('#load-stores').on('click', function() {
        var $button = $(this);
        var $count = $('#store-count');
        var $container = $('#stores-table-container');

        $button.prop('disabled', true);
        $container.html('<div class="loading">Loading stores...</div>');

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_stores',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var stores = response.data;
                    $count.text(stores.length + ' stores loaded');
                    
                    if (stores.length > 0) {
                        var table = '<table class="wp-list-table widefat fixed striped">';
                        table += '<thead><tr>';
                        table += '<th>ID</th>';
                        table += '<th>Name</th>';
                        table += '<th>Description</th>';
                        table += '<th>Use PLU</th>';
                        table += '<th>Storekeeper</th>';
                        table += '</tr></thead><tbody>';
                        
                        stores.forEach(function(store) {
                            table += '<tr>';
                            table += '<td>' + store.id + '</td>';
                            table += '<td>' + store.name + '</td>';
                            table += '<td>' + store.text + '</td>';
                            table += '<td>' + (store.usePLU ? 'Yes' : 'No') + '</td>';
                            table += '<td>' + (store.storekeeper || '-') + '</td>';
                            table += '</tr>';
                        });
                        
                        table += '</tbody></table>';
                        $container.html(table);
                    } else {
                        $container.html('<div class="notice notice-warning"><p>No stores found.</p></div>');
                    }
                } else {
                    $container.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $container.html('<div class="notice notice-error"><p>Failed to load stores. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Main Sync Page
    const $startSyncButton = $('#start-full-sync');
    const $syncWizardProgress = $('#sync-wizard-progress');
    const $syncStepsList = $('#sync-steps li');
    const $wizardProgressBar = $('#wizard-progress-bar-inner');

    let pohodaLastSyncId = 0; // Variable to store the last_id from download_first_products
    let pohodaSyncHasMore = true; // Variable to store has_more from download_first_products
    let totalProductsToSyncForRestStep = 0; // Estimate, can be updated by API if it provides a total
    let productsProcessedInRestStep = 0;

    let imagesHaveMoreToProcess = true; // For reupload_images step
    let totalImagesToProcess = 0; // Estimate for reupload_images
    let imagesProcessedInStep = 0;

    // For create_missing_products step
    let missingProductsList = [];
    let missingProductsBatchSize = 20; // Or get from settings? Or response?
    let currentMissingProductIndex = 0;
    let totalMissingProductsToCreate = 0;
    let missingProductsCreatedInStep = 0;

    // For check_orphan_products_and_hide step
    let orphanProductsList = [];
    let orphanProductsBatchSize = 50;
    let currentOrphanProductIndex = 0;
    let totalOrphanProductsToHide = 0;
    let orphanProductsHiddenInStep = 0;

    // For check_no_price_products_and_hide step
    let noPriceProductsList = [];
    let noPriceProductsBatchSize = 50;
    let currentNoPriceProductIndex = 0;
    let totalNoPriceProductsToHide = 0;
    let noPriceProductsHiddenInStep = 0;

    // For check_no_image_products_and_hide step
    let noImageProductsList = [];
    let noImageProductsBatchSize = 50;
    let currentNoImageProductIndex = 0;
    let totalNoImageProductsToHide = 0;
    let noImageProductsHiddenInStep = 0;

    const syncSteps = [
        'check_settings',
        'download_first_products',
        'sync_rest_products',
        'update_pohoda_db',
        'update_prices',
        'update_stock',
        'reupload_images',
        'create_missing_products',
        'check_no_price_products',
        'check_no_image_products',
        'finished'
    ];

    $startSyncButton.on('click', function() {
        $startSyncButton.prop('disabled', true);
        $syncWizardProgress.show();
        $syncStepsList.removeClass('completed error active');
        $wizardProgressBar.css('width', '0%').text('0%');
        runSyncWizard(0);
    });

    function runSyncWizard(currentStepIndex) {
        console.log(`runSyncWizard called. Current step index: ${currentStepIndex}`);
        if (currentStepIndex >= syncSteps.length) {
            $startSyncButton.prop('disabled', false);
            console.log('Sync wizard finished all steps.');
            // Optionally, hide wizard progress after a delay or keep it visible
            // $syncWizardProgress.delay(5000).fadeOut(); 
            return;
        }

        const currentStep = syncSteps[currentStepIndex];
        const $currentStepLi = $syncStepsList.filter('[data-step="' + currentStep + '"]');
        
        console.log(`Processing step: ${currentStep}`);

        $currentStepLi.addClass('active').removeClass('completed error');
        $currentStepLi.next('.step-error-message').remove();

        if (currentStep === 'sync_rest_products') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Starting batch processing...');
            productsProcessedInRestStep = 0;
            // We need an estimate of total products for sub-progress. 
            // If download_first_products gave a total for *all* products, we could use that.
            // For now, we'll update progress based on batches, assuming an unknown total initially.
            // Or, the first call to sync_rest_products could return an estimated total.

            runRestProductsBatch(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel);
        } else if (currentStep === 'reupload_images') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Starting image reupload...');
            imagesHaveMoreToProcess = true; // Reset for current run
            imagesProcessedInStep = 0; 
            totalImagesToProcess = 0; // This might be updated by the first batch response if available

            runReuploadImagesBatch(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel);
        } else if (currentStep === 'create_missing_products') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Fetching list of products to create...');
            
            missingProductsList = [];
            currentMissingProductIndex = 0;
            totalMissingProductsToCreate = 0;
            missingProductsCreatedInStep = 0;

            runCreateMissingProductsPhases(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'fetch_list');
        } else if (currentStep === 'check_orphan_products_and_hide') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Fetching list of orphan products to hide...');
            
            orphanProductsList = [];
            currentOrphanProductIndex = 0;
            totalOrphanProductsToHide = 0;
            orphanProductsHiddenInStep = 0;

            runCheckOrphanProductsPhases(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'fetch_list');
        } else if (currentStep === 'check_no_price_products_and_hide') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Fetching list of products with no price to hide...');
            
            noPriceProductsList = [];
            currentNoPriceProductIndex = 0;
            totalNoPriceProductsToHide = 0;
            noPriceProductsHiddenInStep = 0;

            runCheckNoPriceProductsPhases(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'fetch_list');
        } else if (currentStep === 'check_no_image_products_and_hide') {
            const $subProgressContainer = $currentStepLi.find('.sub-progress-container');
            const $subProgressBar = $subProgressContainer.find('.sub-progress-bar');
            const $subProgressLabel = $subProgressContainer.find('.sub-progress-label');
            
            $subProgressContainer.show();
            $subProgressBar.css('width', '0%');
            $subProgressLabel.text('Fetching list of products with no image to hide...');
            
            noImageProductsList = [];
            currentNoImageProductIndex = 0;
            totalNoImageProductsToHide = 0;
            noImageProductsHiddenInStep = 0;

            runCheckNoImageProductsPhases(currentStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'fetch_list');
        } else {
            // Standard AJAX call for other steps
            console.log(`Running AJAX for step: ${currentStep}`);
            let ajaxData = {
                action: 'pohoda_run_sync_step',
                nonce: pohodaAdmin.nonce,
                step_name: currentStep
            };

            $.ajax({
                url: pohodaAdmin.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    if (response.success) {
                        $currentStepLi.removeClass('active').addClass('completed');
                        let successMessage = `Completed step: ${currentStep}`;
                        if (response.data && response.data.message) {
                            successMessage += `: ${response.data.message}`;
                        }
                        console.log(successMessage, response.data ? response.data : '');

                        if (currentStep === 'download_first_products' && response.data) {
                            pohodaLastSyncId = response.data.last_id !== undefined ? response.data.last_id : 0;
                            pohodaSyncHasMore = response.data.has_more !== undefined ? response.data.has_more : false;
                            // If API provides an estimated total, store it for sub-progress
                            // totalProductsToSyncForRestStep = response.data.estimated_total_remaining || 0; 
                            console.log(`Stored after download_first_products - last_id: ${pohodaLastSyncId}, has_more: ${pohodaSyncHasMore}`);
                        }
                        
                        updateProgressBar(currentStepIndex);
                        if (currentStepIndex < syncSteps.length - 1) {
                            runSyncWizard(currentStepIndex + 1);
                        } else {
                            $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                            $startSyncButton.prop('disabled', false);
                            console.log('Sync wizard successfully completed all AJAX steps.');
                        }
                    } else {
                        handleStepError($currentStepLi, currentStep, response.data ? response.data.message : 'Unknown error', response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown);
                }
            });
        }
    }

    function runRestProductsBatch(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel) {
        if (!pohodaSyncHasMore) {
            $subProgressLabel.text('All remaining products processed.');
            $subProgressBar.css('width', '100%');
            $currentStepLi.removeClass('active').addClass('completed');
            console.log('sync_rest_products: No more batches to process based on pohodaSyncHasMore.');
            updateProgressBar(mainStepIndex); // Update main progress bar
            if (mainStepIndex < syncSteps.length - 1) {
                runSyncWizard(mainStepIndex + 1); // Proceed to next main step
            } else {
                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                $startSyncButton.prop('disabled', false);
                console.log('Sync wizard successfully completed all AJAX steps including batched rest_products.');
            }
            return;
        }

        console.log(`sync_rest_products: Requesting batch. Last ID: ${pohodaLastSyncId}, Processed in step: ${productsProcessedInRestStep}`);
        $subProgressLabel.text(`Processing batch starting ID ${pohodaLastSyncId}. Total processed in this step: ${productsProcessedInRestStep}`);

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pohoda_run_sync_step',
                nonce: pohodaAdmin.nonce,
                step_name: 'sync_rest_products', // Critical: ensure this is the correct step name
                pohoda_last_sync_id: pohodaLastSyncId,
                pohoda_sync_has_more: pohodaSyncHasMore, // Send current has_more status
                processed_in_step: productsProcessedInRestStep
            },
            success: function(response) {
                if (response.success && response.data) {
                    const batchData = response.data;
                    pohodaLastSyncId = batchData.current_last_id !== undefined ? batchData.current_last_id : pohodaLastSyncId;
                    pohodaSyncHasMore = batchData.current_has_more !== undefined ? batchData.current_has_more : false;
                    productsProcessedInRestStep = batchData.processed_total_in_step || productsProcessedInRestStep;
                    
                    console.log(`sync_rest_products batch success: Fetched ${batchData.batch_fetched}. New Last ID: ${pohodaLastSyncId}, Has More: ${pohodaSyncHasMore}, Total in step: ${productsProcessedInRestStep}`);
                    $subProgressLabel.text(`Batch from ID ${response.config && response.config.data ? JSON.parse(response.config.data).pohoda_last_sync_id : 'N/A'} done. Fetched: ${batchData.batch_fetched}. Total for step: ${productsProcessedInRestStep}`);
                    
                    // Update sub-progress bar - this is tricky without a total count for this step.
                    // For now, just make it move, or if we get an estimated total count we can use it.
                    // Example: if totalProductsToSyncForRestStep > 0, calculate percentage.
                    // Let's assume for now the sub-bar just fills gradually if pohodaSyncHasMore is true, then jumps to 100%
                    if (pohodaSyncHasMore) {
                        // Simple increment, not super accurate without total.
                        let currentSubWidth = parseFloat($subProgressBar.css('width'));
                        let newSubWidth = Math.min(95, currentSubWidth + 10); // Arbitrary increment up to 95%
                        $subProgressBar.css('width', newSubWidth + '%'); 
                    } else {
                        $subProgressBar.css('width', '100%');
                    }

                    if (!jQuery.isEmptyObject(batchData.errors)) {
                        console.warn('Errors in sync_rest_products batch:', batchData.errors);
                        // Optionally display these errors under the sub-progress
                        $currentStepLi.find('.sub-progress-container').append(`<div class="step-batch-error" style="color: orange; font-size:0.9em;">Batch errors: ${batchData.errors.join(', ')}</div>`);
                    }

                    runRestProductsBatch(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel); // Request next batch
                } else {
                    // Handle failure of a batch for sync_rest_products
                    let errMsg = 'Error in sync_rest_products batch.';
                    if(response.data && response.data.message) errMsg = response.data.message;
                    else if (response.message) errMsg = response.message;
                    
                    console.error(errMsg, response.data || response);
                    $subProgressLabel.text(errMsg).css('color', 'red');
                    $currentStepLi.find('.sub-progress-container').append(`<div class="step-batch-error" style="color: red; font-size:0.9em;">${errMsg}</div>`);
                    // Halt the main wizard on batch error
                    handleStepError($currentStepLi, 'sync_rest_products', errMsg, response.data || response);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Halt the main wizard on AJAX error during batch processing
                const errorText = jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message 
                                ? jqXHR.responseJSON.data.message 
                                : errorThrown;
                let detailedError = `AJAX Error in sync_rest_products batch: ${errorText || textStatus}`;
                console.error(detailedError, jqXHR.responseText);
                $subProgressLabel.text(detailedError).css('color', 'red');
                handleAjaxError($currentStepLi, 'sync_rest_products', jqXHR, textStatus, errorThrown);
            }
        });
    }

    function runReuploadImagesBatch(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel) {
        if (!imagesHaveMoreToProcess) {
            $subProgressLabel.text('All images reuploaded.');
            $subProgressBar.css('width', '100%');
            $currentStepLi.removeClass('active').addClass('completed');
            console.log('reupload_images: No more batches to process.');
            updateProgressBar(mainStepIndex);
            if (mainStepIndex < syncSteps.length - 1) {
                runSyncWizard(mainStepIndex + 1);
            } else {
                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                $startSyncButton.prop('disabled', false);
                console.log('Sync wizard successfully completed all AJAX steps including image reupload.');
            }
            return;
        }

        console.log('runReuploadImagesBatch: Requesting image batch.');

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pohoda_run_sync_step',
                nonce: pohodaAdmin.nonce,
                step_name: 'reupload_images'
                // No extra params like last_id needed for images, service handles its own queue
            },
            success: function(response) {
                if (response.success && response.data) {
                    imagesHaveMoreToProcess = response.data.current_has_more;
                    
                    const batchProcessedCount = response.data.processed_count || 0;
                    const batchSuccessCount = response.data.success_count || 0;
                    const batchFailedCount = response.data.failed_count || 0;
                    imagesProcessedInStep += batchSuccessCount; // Only count successful ones for overall progress perhaps? Or processed_count? Let's use processed for now.
                                                            // Assuming processed_count = success_count + failed_count

                    // Try to get a total if the API provides it (e.g., total_pending_images in first batch response)
                    if (response.data.total_pending_images && totalImagesToProcess === 0) {
                        totalImagesToProcess = response.data.total_pending_images;
                    }
                    
                    let progressPercent = 0;
                    if (totalImagesToProcess > 0) {
                        // If we track total images processed so far (across batches)
                        // For now, let's assume API might give current_batch_offset and total_pending_images
                        // For simplicity, if total is known, progress is (total - remaining) / total or similar
                        // Since PHP gives current_has_more, we can rely on that primarily.
                        // Let's refine progress if total is known:
                        // imagesProcessedInStep is cumulative.
                        progressPercent = Math.min(100, (imagesProcessedInStep / totalImagesToProcess) * 100);
                    } else if (!imagesHaveMoreToProcess) { // If no total, but processing finished
                        progressPercent = 100;
                    }
                    // If totalImagesToProcess is still 0, the progress bar won't be very accurate until the end.
                    // We could also update it based on an assumption like "each batch is X% of unknown total" but that's weak.

                    $subProgressBar.css('width', progressPercent + '%');
                    let batchSummary = `Batch: ${batchProcessedCount} checked, ${batchSuccessCount} uploaded, ${batchFailedCount} failed.`;
                    if (totalImagesToProcess > 0) {
                         $subProgressLabel.text(`Reuploaded: ${imagesProcessedInStep} / ${totalImagesToProcess} images. ${batchSummary}`);
                    } else {
                         $subProgressLabel.text(`Reuploaded: ${imagesProcessedInStep} images. ${batchSummary}`);
                    }
                    console.log(`reupload_images batch success. Processed in batch: ${batchProcessedCount}, Successful: ${batchSuccessCount}, Has more: ${imagesHaveMoreToProcess}`);
                    
                    if (imagesHaveMoreToProcess) {
                        runReuploadImagesBatch(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel); // Process next batch
                    } else {
                        // This is the final batch
                        $subProgressLabel.text(`All images processed. Total reuploaded: ${imagesProcessedInStep}.`);
                        $subProgressBar.css('width', '100%');
                        $currentStepLi.removeClass('active').addClass('completed');
                        console.log('reupload_images: Finished all batches.');
                        updateProgressBar(mainStepIndex);
                        if (mainStepIndex < syncSteps.length - 1) {
                            runSyncWizard(mainStepIndex + 1);
                        } else {
                             $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                             $startSyncButton.prop('disabled', false);
                             console.log('Sync wizard successfully completed all AJAX steps after final image batch.');
                        }
                    }
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : 'Image reupload batch error';
                    handleStepError($currentStepLi, 'reupload_images', errorMessage + ' (batch)', response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError($currentStepLi, 'reupload_images', jqXHR, textStatus, errorThrown, ' (batch)');
            }
        });
    }

    function runCreateMissingProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, subAction) {
        const currentStep = 'create_missing_products';
        let ajaxData = {
            action: 'pohoda_run_sync_step',
            nonce: pohodaAdmin.nonce,
            step_name: currentStep,
            sub_action: subAction
        };

        if (subAction === 'fetch_list') {
            console.log('runCreateMissingProductsPhases: Fetching list.');
            $subProgressLabel.text('Fetching list of products to create...');
        } else if (subAction === 'process_batch') {
            if (currentMissingProductIndex >= missingProductsList.length) {
                $subProgressLabel.text(`All ${missingProductsCreatedInStep} missing products created (or updated).`);
                $subProgressBar.css('width', '100%');
                $currentStepLi.removeClass('active').addClass('completed');
                console.log('create_missing_products: Finished all batches.');
                updateProgressBar(mainStepIndex);
                if (mainStepIndex < syncSteps.length - 1) {
                    runSyncWizard(mainStepIndex + 1);
                } else {
                    $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                    $startSyncButton.prop('disabled', false);
                    console.log('Sync wizard successfully completed all AJAX steps after creating missing products.');
                }
                return;
            }
            const batch = missingProductsList.slice(currentMissingProductIndex, currentMissingProductIndex + missingProductsBatchSize);
            ajaxData.batch_data = JSON.stringify(batch); // Ensure PHP gets it as a JSON string if it expects that
            ajaxData.current_index = currentMissingProductIndex; // Optional: for logging or context on PHP side
            ajaxData.total_in_list = missingProductsList.length; // Optional

            console.log(`runCreateMissingProductsPhases: Processing batch starting at index ${currentMissingProductIndex}. Batch size: ${batch.length}`);
        }

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success && response.data) {
                    if (subAction === 'fetch_list') {
                        missingProductsList = response.data.products_to_create || [];
                        totalMissingProductsToCreate = missingProductsList.length;
                        currentMissingProductIndex = 0;
                        missingProductsCreatedInStep = 0;
                        console.log(`create_missing_products: Fetched ${totalMissingProductsToCreate} products to create.`);
                        if (totalMissingProductsToCreate > 0) {
                            $subProgressLabel.text(`Fetched ${totalMissingProductsToCreate} products. Starting creation...`);
                            runCreateMissingProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                        } else {
                            $subProgressLabel.text('No missing products found to create.');
                            $subProgressBar.css('width', '100%');
                            $currentStepLi.removeClass('active').addClass('completed');
                            updateProgressBar(mainStepIndex);
                            if (mainStepIndex < syncSteps.length - 1) {
                                runSyncWizard(mainStepIndex + 1);
                            } else {
                                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                                $startSyncButton.prop('disabled', false);
                            }
                        }
                    } else if (subAction === 'process_batch') {
                        const batchCreatedCount = response.data.created_count || 0;
                        const batchUpdatedCount = response.data.updated_count || 0; // If create can also update existing by SKU
                        const batchFailedCount = response.data.failed_count || 0;
                        missingProductsCreatedInStep += (batchCreatedCount + batchUpdatedCount); 

                        currentMissingProductIndex += (response.data.processed_in_batch || missingProductsBatchSize); // Advance index by how many were actually processed

                        let progressPercent = 0;
                        if (totalMissingProductsToCreate > 0) {
                            progressPercent = Math.min(100, (missingProductsCreatedInStep / totalMissingProductsToCreate) * 100);
                        }
                        $subProgressBar.css('width', progressPercent + '%');
                        let batchSummary = `Batch: ${batchCreatedCount} created, ${batchUpdatedCount} updated, ${batchFailedCount} failed.`;
                        $subProgressLabel.text(`Processed: ${missingProductsCreatedInStep} / ${totalMissingProductsToCreate} products. ${batchSummary}`);
                        console.log(`create_missing_products batch success. ${batchSummary}. Total processed so far: ${missingProductsCreatedInStep}`);

                        // Continue with the next batch
                        runCreateMissingProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                    }
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : `Error during ${currentStep} (${subAction})`;
                    handleStepError($currentStepLi, currentStep, errorMessage + ` (sub-action: ${subAction})`, response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown, ` (sub-action: ${subAction})`);
            }
        });
    }

    function runCheckOrphanProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, subAction) {
        const currentStep = 'check_orphan_products_and_hide';
        let ajaxData = {
            action: 'pohoda_run_sync_step',
            nonce: pohodaAdmin.nonce,
            step_name: currentStep,
            sub_action: subAction
        };

        if (subAction === 'fetch_list') {
            console.log('runCheckOrphanProductsPhases: Fetching list.');
            $subProgressLabel.text('Fetching list of orphan products to hide...');
        } else if (subAction === 'process_batch') {
            if (currentOrphanProductIndex >= orphanProductsList.length) {
                $subProgressLabel.text(`All ${orphanProductsHiddenInStep} orphan products processed (hidden).`);
                $subProgressBar.css('width', '100%');
                $currentStepLi.removeClass('active').addClass('completed');
                console.log('check_orphan_products_and_hide: Finished all batches.');
                updateProgressBar(mainStepIndex);
                if (mainStepIndex < syncSteps.length - 1) {
                    runSyncWizard(mainStepIndex + 1);
                } else {
                    $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                    $startSyncButton.prop('disabled', false);
                    console.log('Sync wizard successfully completed all AJAX steps after checking orphan products.');
                }
                return;
            }
            const batch = orphanProductsList.slice(currentOrphanProductIndex, currentOrphanProductIndex + orphanProductsBatchSize);
            ajaxData.product_ids_batch = JSON.stringify(batch); // PHP expects product_ids_batch based on admin class
            // console.log(`runCheckOrphanProductsPhases: Processing batch of IDs starting at index ${currentOrphanProductIndex}. Batch size: ${batch.length}`);
        }

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success && response.data) {
                    if (subAction === 'fetch_list') {
                        orphanProductsList = response.data.product_ids_to_hide || [];
                        totalOrphanProductsToHide = orphanProductsList.length;
                        currentOrphanProductIndex = 0;
                        orphanProductsHiddenInStep = 0;
                        console.log(`check_orphan_products_and_hide: Fetched ${totalOrphanProductsToHide} orphan product IDs.`);
                        if (totalOrphanProductsToHide > 0) {
                            $subProgressLabel.text(`Fetched ${totalOrphanProductsToHide} orphan product IDs. Starting processing...`);
                            runCheckOrphanProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                        } else {
                            $subProgressLabel.text('No orphan products found to hide.');
                            $subProgressBar.css('width', '100%');
                            $currentStepLi.removeClass('active').addClass('completed');
                            updateProgressBar(mainStepIndex);
                            if (mainStepIndex < syncSteps.length - 1) {
                                runSyncWizard(mainStepIndex + 1);
                            } else {
                                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                                $startSyncButton.prop('disabled', false);
                            }
                        }
                    } else if (subAction === 'process_batch') {
                        const batchHiddenCount = response.data.batch_hidden || 0;
                        const batchFailedCount = response.data.batch_failed || 0;
                        orphanProductsHiddenInStep += batchHiddenCount;

                        currentOrphanProductIndex += orphanProductsBatchSize; // Assumes full batch size was attempted

                        let progressPercent = 0;
                        if (totalOrphanProductsToHide > 0) {
                            progressPercent = Math.min(100, (currentOrphanProductIndex / totalOrphanProductsToHide) * 100);
                        }
                         if(currentOrphanProductIndex >= totalOrphanProductsToHide) progressPercent = 100;

                        $subProgressBar.css('width', progressPercent + '%');
                        let batchSummary = `Batch: ${batchHiddenCount} hidden, ${batchFailedCount} failed.`;
                        $subProgressLabel.text(`Processed: ${Math.min(currentOrphanProductIndex, totalOrphanProductsToHide)} / ${totalOrphanProductsToHide} orphan IDs. ${batchSummary}`);
                        console.log(`check_orphan_products_and_hide batch success. ${batchSummary}. Total hidden so far: ${orphanProductsHiddenInStep}`);
                        
                        if (!jQuery.isEmptyObject(response.data.batch_errors)) {
                            console.warn('Errors in check_orphan_products_and_hide batch:', response.data.batch_errors);
                            $currentStepLi.find('.sub-progress-container').append(`<div class="step-batch-error" style="color: orange; font-size:0.9em;">Batch errors: ${response.data.batch_errors.join(', ')}</div>`);
                        }

                        runCheckOrphanProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                    }
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : `Error during ${currentStep} (${subAction})`;
                    handleStepError($currentStepLi, currentStep, errorMessage + ` (sub-action: ${subAction})`, response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown, ` (sub-action: ${subAction})`);
            }
        });
    }

    function runCheckNoPriceProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, subAction) {
        const currentStep = 'check_no_price_products_and_hide';
        let ajaxData = {
            action: 'pohoda_run_sync_step',
            nonce: pohodaAdmin.nonce,
            step_name: currentStep,
            sub_action: subAction
        };

        if (subAction === 'fetch_list') {
            console.log('runCheckNoPriceProductsPhases: Fetching list.');
            $subProgressLabel.text('Fetching list of products with no price...');
        } else if (subAction === 'process_batch') {
            if (currentNoPriceProductIndex >= noPriceProductsList.length) {
                $subProgressLabel.text(`All ${noPriceProductsHiddenInStep} products with no price processed (hidden).`);
                $subProgressBar.css('width', '100%');
                $currentStepLi.removeClass('active').addClass('completed');
                console.log('check_no_price_products_and_hide: Finished all batches.');
                updateProgressBar(mainStepIndex);
                if (mainStepIndex < syncSteps.length - 1) {
                    runSyncWizard(mainStepIndex + 1);
                } else {
                    $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                    $startSyncButton.prop('disabled', false);
                    console.log('Sync wizard successfully completed all AJAX steps after checking no-price products.');
                }
                return;
            }
            const batch = noPriceProductsList.slice(currentNoPriceProductIndex, currentNoPriceProductIndex + noPriceProductsBatchSize);
            ajaxData.product_ids_batch = JSON.stringify(batch);
        }

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success && response.data) {
                    if (subAction === 'fetch_list') {
                        noPriceProductsList = response.data.product_ids_to_hide || [];
                        totalNoPriceProductsToHide = noPriceProductsList.length;
                        currentNoPriceProductIndex = 0;
                        noPriceProductsHiddenInStep = 0;
                        console.log(`check_no_price_products_and_hide: Fetched ${totalNoPriceProductsToHide} product IDs with no price.`);
                        if (totalNoPriceProductsToHide > 0) {
                            $subProgressLabel.text(`Fetched ${totalNoPriceProductsToHide} product IDs. Starting processing...`);
                            runCheckNoPriceProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                        } else {
                            $subProgressLabel.text('No products with no price found to hide.');
                            $subProgressBar.css('width', '100%');
                            $currentStepLi.removeClass('active').addClass('completed');
                            updateProgressBar(mainStepIndex);
                            if (mainStepIndex < syncSteps.length - 1) {
                                runSyncWizard(mainStepIndex + 1);
                            } else {
                                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                                $startSyncButton.prop('disabled', false);
                            }
                        }
                    } else if (subAction === 'process_batch') {
                        const batchHiddenCount = response.data.batch_hidden || 0;
                        const batchFailedCount = response.data.batch_failed || 0;
                        noPriceProductsHiddenInStep += batchHiddenCount;
                        currentNoPriceProductIndex += noPriceProductsBatchSize;

                        let progressPercent = 0;
                        if (totalNoPriceProductsToHide > 0) {
                            progressPercent = Math.min(100, (currentNoPriceProductIndex / totalNoPriceProductsToHide) * 100);
                        }
                        if(currentNoPriceProductIndex >= totalNoPriceProductsToHide) progressPercent = 100;

                        $subProgressBar.css('width', progressPercent + '%');
                        let batchSummary = `Batch: ${batchHiddenCount} hidden, ${batchFailedCount} failed.`;
                        $subProgressLabel.text(`Processed: ${Math.min(currentNoPriceProductIndex, totalNoPriceProductsToHide)} / ${totalNoPriceProductsToHide} IDs (no price). ${batchSummary}`);
                        console.log(`check_no_price_products_and_hide batch success. ${batchSummary}. Total hidden: ${noPriceProductsHiddenInStep}`);
                        
                        if (!jQuery.isEmptyObject(response.data.batch_errors)) {
                            console.warn('Errors in check_no_price_products_and_hide batch:', response.data.batch_errors);
                             $currentStepLi.find('.sub-progress-container').append(`<div class="step-batch-error" style="color: orange; font-size:0.9em;">Batch errors: ${response.data.batch_errors.join(', ')}</div>`);
                        }

                        runCheckNoPriceProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                    }
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : `Error during ${currentStep} (${subAction})`;
                    handleStepError($currentStepLi, currentStep, errorMessage + ` (sub-action: ${subAction})`, response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown, ` (sub-action: ${subAction})`);
            }
        });
    }

    function runCheckNoImageProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, subAction) {
        const currentStep = 'check_no_image_products_and_hide';
        let ajaxData = {
            action: 'pohoda_run_sync_step',
            nonce: pohodaAdmin.nonce,
            step_name: currentStep,
            sub_action: subAction
        };

        if (subAction === 'fetch_list') {
            console.log('runCheckNoImageProductsPhases: Fetching list.');
            $subProgressLabel.text('Fetching list of products with no image...');
        } else if (subAction === 'process_batch') {
            if (currentNoImageProductIndex >= noImageProductsList.length) {
                $subProgressLabel.text(`All ${noImageProductsHiddenInStep} products with no image processed (hidden).`);
                $subProgressBar.css('width', '100%');
                $currentStepLi.removeClass('active').addClass('completed');
                console.log('check_no_image_products_and_hide: Finished all batches.');
                updateProgressBar(mainStepIndex);
                if (mainStepIndex < syncSteps.length - 1) {
                    runSyncWizard(mainStepIndex + 1);
                } else {
                    $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                    $startSyncButton.prop('disabled', false);
                    console.log('Sync wizard successfully completed all AJAX steps after checking no-image products.');
                }
                return;
            }
            const batch = noImageProductsList.slice(currentNoImageProductIndex, currentNoImageProductIndex + noImageProductsBatchSize);
            ajaxData.product_ids_batch = JSON.stringify(batch);
        }

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success && response.data) {
                    if (subAction === 'fetch_list') {
                        noImageProductsList = response.data.product_ids_to_hide || [];
                        totalNoImageProductsToHide = noImageProductsList.length;
                        currentNoImageProductIndex = 0;
                        noImageProductsHiddenInStep = 0;
                        console.log(`check_no_image_products_and_hide: Fetched ${totalNoImageProductsToHide} product IDs with no image.`);
                        if (totalNoImageProductsToHide > 0) {
                            $subProgressLabel.text(`Fetched ${totalNoImageProductsToHide} product IDs. Starting processing...`);
                            runCheckNoImageProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                        } else {
                            $subProgressLabel.text('No products with no image found to hide.');
                            $subProgressBar.css('width', '100%');
                            $currentStepLi.removeClass('active').addClass('completed');
                            updateProgressBar(mainStepIndex);
                            if (mainStepIndex < syncSteps.length - 1) {
                                runSyncWizard(mainStepIndex + 1);
                            } else {
                                $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                                $startSyncButton.prop('disabled', false);
                            }
                        }
                    } else if (subAction === 'process_batch') {
                        const batchHiddenCount = response.data.batch_hidden || 0;
                        const batchFailedCount = response.data.batch_failed || 0;
                        noImageProductsHiddenInStep += batchHiddenCount;
                        currentNoImageProductIndex += noImageProductsBatchSize;

                        let progressPercent = 0;
                        if (totalNoImageProductsToHide > 0) {
                            progressPercent = Math.min(100, (currentNoImageProductIndex / totalNoImageProductsToHide) * 100);
                        }
                        if(currentNoImageProductIndex >= totalNoImageProductsToHide) progressPercent = 100;

                        $subProgressBar.css('width', progressPercent + '%');
                        let batchSummary = `Batch: ${batchHiddenCount} hidden, ${batchFailedCount} failed.`;
                        $subProgressLabel.text(`Processed: ${Math.min(currentNoImageProductIndex, totalNoImageProductsToHide)} / ${totalNoImageProductsToHide} IDs (no image). ${batchSummary}`);
                        console.log(`check_no_image_products_and_hide batch success. ${batchSummary}. Total hidden: ${noImageProductsHiddenInStep}`);
                        
                        if (!jQuery.isEmptyObject(response.data.batch_errors)) {
                            console.warn('Errors in check_no_image_products_and_hide batch:', response.data.batch_errors);
                             $currentStepLi.find('.sub-progress-container').append(`<div class="step-batch-error" style="color: orange; font-size:0.9em;">Batch errors: ${response.data.batch_errors.join(', ')}</div>`);
                        }

                        runCheckNoImageProductsPhases(mainStepIndex, $currentStepLi, $subProgressBar, $subProgressLabel, 'process_batch');
                    }
                } else {
                    const errorMessage = response.data && response.data.message ? response.data.message : `Error during ${currentStep} (${subAction})`;
                    handleStepError($currentStepLi, currentStep, errorMessage + ` (sub-action: ${subAction})`, response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown, ` (sub-action: ${subAction})`);
            }
        });
    }

    function handleStepError($currentStepLi, currentStep, message, responseData) {
        $currentStepLi.removeClass('active').addClass('error');
        let errorMessage = `Chyba v kroku ${currentStep}`;
        if (message) {
            errorMessage += `: ${message}`;
        }
        console.error(errorMessage, responseData);
        // Remove previous error message for this step before adding new one
        $currentStepLi.next('.step-error-message').remove(); 
        $currentStepLi.after(`<li class="step-error-message" style="color: red; padding-left: 20px;">${errorMessage}</li>`);
        $startSyncButton.prop('disabled', false);
    }

    function handleAjaxError($currentStepLi, currentStep, jqXHR, textStatus, errorThrown) {
        const $errorDiv = $('<div class="step-error-message notice notice-error"><p></p></div>');
        let errorMessage = `AJAX Error for step ${currentStep}: ${textStatus}`;
        if (errorThrown) {
            errorMessage += ` - ${errorThrown}`;
        }
        console.error(errorMessage, jqXHR.responseText);
        $errorDiv.find('p').text(errorMessage + '. Check console for details.');
        $currentStepLi.after($errorDiv).removeClass('active').addClass('error');
        $startSyncButton.prop('disabled', false); // Re-enable button on error
    }

    function updateProgressBar(currentStepIndex) {
        const progressPercentage = Math.round(((currentStepIndex + 1) / syncSteps.length) * 100);
        $wizardProgressBar.css('width', progressPercentage + '%').text(progressPercentage + '%');
    }

    // Sync History Page
    const $syncHistoryTableBody = $('#sync-history-table-body');
    const $syncHistoryDetails = $('#sync-history-details');
    const $historyDetailsSyncId = $('#history-details-sync-id');
    const $historyDetailsActionList = $('#history-details-action-list');

    function loadSyncHistory() {
        // Placeholder: AJAX call to fetch sync history
        console.log('Loading sync history...');
        // Example of how to populate the table - replace with actual data
        // Simulating a delay and then populating
        $syncHistoryTableBody.html('<tr><td colspan="5">Načítání historie...</td></tr>');
        setTimeout(function() {
            const dummyHistory = [
                {
                    id: 1, 
                    startTime: '2023-10-26 10:00:00', 
                    status: 'Completed', 
                    actionCount: 120,
                },
                {
                    id: 2, 
                    startTime: '2023-10-27 14:30:00', 
                    status: 'Failed', 
                    actionCount: 45,
                }
            ];

            let rowsHtml = '';
            if (dummyHistory.length > 0) {
                dummyHistory.forEach(item => {
                    rowsHtml += `<tr>
                        <td>${item.id}</td>
                        <td>${item.startTime}</td>
                        <td>${item.status}</td>
                        <td>${item.actionCount}</td>
                        <td><button class="button view-history-details" data-sync-id="${item.id}">Zobrazit detaily</button></td>
                    </tr>`;
                });
            } else {
                rowsHtml = '<tr><td colspan="5">Žádná historie nebyla nalezena.</td></tr>';
            }
            $syncHistoryTableBody.html(rowsHtml);
        }, 1000);
    }

    // Event listener for viewing history details
    $syncHistoryTableBody.on('click', '.view-history-details', function() {
        const syncId = $(this).data('sync-id');
        loadSyncHistoryDetails(syncId);
    });

    function loadSyncHistoryDetails(syncId) {
        // Placeholder: AJAX call to fetch details for a specific sync ID
        console.log(`Loading details for sync ID: ${syncId}`);
        $historyDetailsSyncId.text(syncId);
        
        // Simulating a delay and then populating
        $historyDetailsActionList.html('<li>Načítání detailů...</li>');
        setTimeout(function() {
            // Dummy details - replace with actual data from AJAX call
            const dummyDetails = [
                'Action 1: Updated product XYZ',
                'Action 2: Error syncing price for product ABC (Code: 601 - Neznámá hodnota)',
                'Action 3: Created new product LMN'
            ];
            let detailsHtml = '';
            dummyDetails.forEach(detail => {
                detailsHtml += `<li>${detail}</li>`;
            });
            $historyDetailsActionList.html(detailsHtml);
            $syncHistoryDetails.show();
        }, 500);
    }

    // Initial call if on history page
    if ($('#sync-history-page').length) {
        loadSyncHistory();
    }

    // Basic styling for wizard steps (can be moved to CSS file)
    $('<style>')
        .prop('type', 'text/css')
        .html(`
        #sync-steps li {
            padding: 8px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s ease;
        }
        #sync-steps li.active {
            background-color: #e0e0e0;
            font-weight: bold;
        }
        #sync-steps li.completed {
            background-color: #d4edda; /* Light green */
            color: #155724;
        }
        #sync-steps li.completed::before {
            content: '✓ ';
            color: green;
        }
        #sync-steps li.error {
            background-color: #f8d7da; /* Light red */
            color: #721c24;
        }
        #sync-steps li.error::before {
            content: '✗ ';
            color: red;
        }
    `)
    .appendTo('head');
}); 