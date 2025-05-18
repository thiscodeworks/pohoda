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
        // Clear previous error messages for this step
        $currentStepLi.next('.step-error-message').remove();

        // ALWAYS Use AJAX for the current step
        console.log(`Running AJAX for step: ${currentStep}`);
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pohoda_run_sync_step',
                nonce: pohodaAdmin.nonce,
                step_name: currentStep
            },
            success: function(response) {
                if (response.success) {
                    $currentStepLi.removeClass('active').addClass('completed');
                    console.log(`Completed step: ${currentStep}`, response.data ? response.data.message : '');
                    updateProgressBar(currentStepIndex);
                    
                    if (currentStepIndex < syncSteps.length - 1) {
                        runSyncWizard(currentStepIndex + 1);
                    } else {
                        $wizardProgressBar.css('width', '100%').text('100% - Dokončeno');
                        $startSyncButton.prop('disabled', false);
                        console.log('Sync wizard successfully completed all AJAX steps.');
                    }
                } else {
                    $currentStepLi.removeClass('active').addClass('error');
                    let errorMessage = `Chyba v kroku ${currentStep}`;
                    if (response.data && response.data.message) {
                        errorMessage += `: ${response.data.message}`;
                    }
                    console.error(errorMessage, response.data);
                    $currentStepLi.after(`<li class="step-error-message" style="color: red; padding-left: 20px;">${errorMessage}</li>`);
                    $startSyncButton.prop('disabled', false);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $currentStepLi.removeClass('active').addClass('error');
                const errorText = jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message 
                                ? jqXHR.responseJSON.data.message 
                                : errorThrown;
                let detailedError = `AJAX Chyba v kroku ${currentStep}: ${errorText || textStatus}`;
                console.error(detailedError, jqXHR.responseText);
                $currentStepLi.after(`<li class="step-error-message" style="color: red; padding-left: 20px;">${detailedError}</li>`);
                $startSyncButton.prop('disabled', false);
            }
        });
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