jQuery(document).ready(function($) {
    var currentPage = 1;
    var productsPerPage = 10;
    var totalPages = 1;

    // Initialize on page load for products tab
    if (window.location.href.indexOf('tab=products') > -1) {
        // Load saved preferences for per page if available
        var savedPerPage = localStorage.getItem('pohoda_products_per_page');
        if (savedPerPage) {
            $('#db-products-per-page').val(savedPerPage);
        }
    }

    // Save per page selection to localStorage
    $('#db-products-per-page').on('change', function() {
        localStorage.setItem('pohoda_products_per_page', $(this).val());
    });

    // Load products function
    function loadDbProducts() {
        var $button = $('#load-db-products');
        var $result = $('#products-result');
        var $pagination = $('.pohoda-pagination');

        $button.prop('disabled', true);
        $result.html('<div class="notice notice-info"><p>Loading products...</p></div>');
        $pagination.hide();

        var data = {
            action: 'get_db_products',
            nonce: pohodaAdmin.nonce,
            search: $('#db-product-search').val(),
            type: $('#db-product-type').val(),
            storage: $('#db-product-storage').val(),
            comparison_status: $('#db-comparison-status').val(),
            per_page: $('#db-products-per-page').val(),
            page: currentPage,
            order_by: 'id',
            order: 'ASC'
        };

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    var result = response.data;
                    
                    if (result.success) {
                        var products = result.data;
                        var pagination = result.pagination;
                        
                        if (products.length === 0) {
                            $result.html('<div class="notice notice-warning"><p>No products found.</p></div>');
                            $pagination.hide();
                        } else {
                            renderProducts(products);
                            updatePagination(pagination);
                            $pagination.show();
                        }
                    } else {
                        $result.html('<div class="notice notice-error"><p>Error: ' + result.message + '</p></div>');
                        $pagination.hide();
                    }
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    $pagination.hide();
                }
            },
            error: function(xhr, status, error) {
                $result.html('<div class="notice notice-error"><p>Ajax Error: ' + error + '</p></div>');
                $pagination.hide();
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    }

    function renderProducts(products) {
        var $container = $('#products-result');
        
        var table = '<table class="wp-list-table widefat fixed striped">';
        table += '<thead><tr>';
        table += '<th>ID</th>';
        table += '<th>Code</th>';
        table += '<th>Name</th>';
        table += '<th>Stock</th>';
        table += '<th>Price</th>';
        table += '<th>Price incl. VAT</th>';
        table += '<th>WooCommerce</th>';
        table += '<th>Actions</th>';
        table += '</tr></thead><tbody>';
        
        products.forEach(function(product) {
            var rowClass = '';
            var wooDetails = '';
            
            if (product.woocommerce_exists) {
                if (product.comparison_status === 'match') {
                    rowClass = 'pohoda-row-match';
                    wooDetails = '<span class="woo-exists">Synced</span>';
                } else if (product.comparison_status === 'mismatch') {
                    rowClass = 'pohoda-row-mismatch';
                    
                    // Determine which fields are mismatched
                    var stockDiff = Math.abs(parseFloat(product.count) - parseFloat(product.woocommerce_stock));
                    var stockMatch = stockDiff <= 0.001;
                    
                    // Calculate price with VAT for comparison
                    var vatRate = product.vat_rate || 21; // Default to 21% if not specified
                    var priceWithVat = parseFloat(product.selling_price) * (1 + (vatRate / 100));
                    priceWithVat = Math.ceil(priceWithVat);
                    
                    // Use the price with VAT for comparison with WooCommerce price
                    var priceDiff = Math.abs(priceWithVat - parseFloat(product.woocommerce_price));
                    var priceMatch = priceDiff <= 0.01;
                    
                    var mismatchDetails = '';
                    if (!stockMatch) {
                        mismatchDetails += '<div class="mismatch-item">Stock: ' + 
                            'POHODA: ' + product.count + ' vs WC: ' + product.woocommerce_stock + '</div>';
                    }
                    if (!priceMatch) {
                        mismatchDetails += '<div class="mismatch-item">Price: ' + 
                            'POHODA: ' + priceWithVat.toFixed(2) + ' vs WC: ' + product.woocommerce_price + '</div>';
                    }
                    
                    wooDetails = '<span class="woo-mismatch">Mismatch</span>' +
                        '<div class="mismatch-details">' + mismatchDetails + '</div>';
                } else {
                    rowClass = 'pohoda-row-unknown';
                    wooDetails = '<span class="woo-unknown">Unknown</span>';
                }
            } else {
                rowClass = 'pohoda-row-missing';
                wooDetails = '<span class="woo-missing">Missing</span>';
            }
            
            // Calculate price with VAT
            var vatRate = product.vat_rate || 21; // Default to 21% if not specified
            var priceWithVat = parseFloat(product.selling_price) * (1 + (vatRate / 100));
            // Round to whole number (ceiling)
            priceWithVat = Math.ceil(priceWithVat);
            
            table += '<tr class="' + rowClass + '">';
            table += '<td>' + (product.id || '') + '</td>';
            table += '<td>' + (product.code || '') + '</td>';
            table += '<td>' + (product.name || '') + '</td>';
            table += '<td>' + (product.count !== undefined ? product.count : '') + '</td>';
            table += '<td>' + (product.selling_price !== undefined ? product.selling_price : '') + '</td>';
            table += '<td>' + priceWithVat + ' (' + vatRate + '%)</td>';
            table += '<td>' + wooDetails + '</td>';
            
            // Add actions column with buttons
            var actionButtons = '';
            if (product.woocommerce_exists) {
                actionButtons = '<button class="button button-primary sync-db-product" data-product-id="' + (product.id || '') + '" data-vat-rate="' + vatRate + '">Sync</button>';
                
                // Extract product ID from the edit URL
                if (product.woocommerce_url) {
                    var productId = product.woocommerce_url.match(/post=(\d+)/);
                    if (productId && productId[1]) {
                        // Create frontend link with eye icon
                        var frontendUrl = '/?p=' + productId[1];
                        actionButtons += ' <a href="' + frontendUrl + '" target="_blank" class="button view-woo-button" title="View product on frontend"><span class="dashicons dashicons-visibility"></span></a>';
                        
                        // Add edit button with pen icon
                        actionButtons += ' <a href="' + product.woocommerce_url + '" target="_blank" class="button edit-woo-button" title="Edit in WooCommerce admin"><span class="dashicons dashicons-edit"></span></a>';
                    } else {
                        // Fallback to just the edit link if we can't extract the ID
                        actionButtons += ' <a href="' + product.woocommerce_url + '" target="_blank" class="button edit-woo-button" title="Edit in WooCommerce admin"><span class="dashicons dashicons-edit"></span></a>';
                    }
                }
            } else {
                // Create button for missing products
                actionButtons = '<button class="button button-primary create-in-wc" ' +
                    'data-product-id="' + (product.id || '') + '" ' +
                    'data-product-code="' + (product.code || '') + '" ' +
                    'data-product-name="' + encodeURIComponent(product.name || '') + '" ' + 
                    'data-product-price="' + priceWithVat + '" ' +
                    'data-product-stock="' + (product.count || '') + '" ' +
                    'data-vat-rate="' + vatRate + '"' +
                    '>Create in WC</button>';
            }
            table += '<td>' + actionButtons + '</td>';
            
            table += '</tr>';
        });
        
        table += '</tbody></table>';
        $container.html(table);
        
        // Add CSS styles
        $('<style>')
            .text('.pohoda-row-match { background-color: #d4edda !important; } ' +
                  '.pohoda-row-mismatch { background-color: #fff3cd !important; } ' +
                  '.pohoda-row-missing { background-color: #f8d7da !important; } ' +
                  '.pohoda-row-unknown { background-color: #d6d8d9 !important; } ' +
                  '.woo-exists { color: #1e7e34; font-weight: bold; margin-right: 10px; } ' +
                  '.woo-missing { color: #dc3545; font-weight: bold; margin-right: 10px; } ' +
                  '.woo-mismatch { color: #856404; font-weight: bold; margin-right: 10px; } ' +
                  '.woo-unknown { color: #6c757d; font-weight: bold; margin-right: 10px; } ' +
                  '.wc-data { color: #6c757d; font-size: 0.9em; } ' +
                  '.mismatch-details { margin-top: 5px; font-size: 0.9em; color: #856404; } ' +
                  '.mismatch-item { margin-bottom: 2px; }' +
                  '.view-woo-button, .edit-woo-button { padding: 4px !important; line-height: 1 !important; height: auto !important; min-height: 30px !important; vertical-align: middle !important; }' +
                  '.dashicons { line-height: 1.5 !important; width: 18px !important; height: 18px !important; font-size: 18px !important; vertical-align: middle !important; }')
            .appendTo('head');
    }

    function updatePagination(pagination) {
        if (!pagination) return;
        
        currentPage = pagination.current_page;
        totalPages = pagination.last_page;
        
        // Handle "Show All" case - hide pagination if only one page
        if (totalPages <= 1) {
            $('.pohoda-pagination').hide();
            return;
        } else {
            $('.pohoda-pagination').show();
        }
        
        $('#page-info').text('Page ' + currentPage + ' of ' + totalPages);
        $('#prev-page').prop('disabled', currentPage <= 1);
        $('#next-page').prop('disabled', currentPage >= totalPages);
    }

    // Handle pagination
    $('#prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadDbProducts();
        }
    });

    $('#next-page').on('click', function() {
        if (currentPage < totalPages) {
            currentPage++;
            loadDbProducts();
        }
    });

    // Handle filter button click
    $('#load-db-products').on('click', function() {
        currentPage = 1;
        loadDbProducts();
    });

    // Handle sync all products button
    $('#sync-all-products').on('click', function() {
        if (!confirm('This will sync all products from Pohoda to the local database. Continue?')) {
            return;
        }
        
        var $button = $(this);
        var $progress = $('#sync-progress');
        var $progressBar = $progress.find('.progress-bar-inner');
        var $progressCurrent = $('#progress-current');
        var $progressTotal = $('#progress-total');
        
        $button.prop('disabled', true);
        $progress.show();
        
        var batchSize = 25;
        var startId = 0;
        var totalProcessed = 0;
        var estimatedTotal = 1000; // Initial guess
        
        syncBatch();
        
        function syncBatch() {
            $.ajax({
                url: pohodaAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sync_db_products',
                    nonce: pohodaAdmin.nonce,
                    batch_size: batchSize,
                    start_id: startId
                },
                success: function(response) {
                    if (response.success) {
                        var result = response.data;
                        
                        // Update progress
                        totalProcessed += result.total_fetched;
                        startId = result.last_id;
                        
                        // Update estimated total if more than we initially thought
                        if (totalProcessed > estimatedTotal * 0.8) {
                            estimatedTotal = Math.max(estimatedTotal, totalProcessed * 1.5);
                        }
                        
                        var percentComplete = Math.min(100, Math.round((totalProcessed / estimatedTotal) * 100));
                        $progressBar.css('width', percentComplete + '%');
                        $progressCurrent.text(totalProcessed);
                        $progressTotal.text(estimatedTotal);
                        
                        // If there's more to sync, continue
                        if (result.has_more) {
                            syncBatch();
                        } else {
                            // All done
                            $progressBar.css('width', '100%');
                            $progressCurrent.text(totalProcessed);
                            $progressTotal.text(totalProcessed);
                            
                            setTimeout(function() {
                                $progress.hide();
                                $button.prop('disabled', false);
                                loadDbProducts(); // Reload products
                                alert('Sync completed! ' + totalProcessed + ' products processed.');
                            }, 1000);
                        }
                    } else {
                        alert('Error syncing products: ' + (response.data || 'Unknown error'));
                        $button.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Ajax Error: ' + error);
                    $button.prop('disabled', false);
                }
            });
        }
    });

    // Handle refresh WooCommerce data button
    $('#refresh-wc-data').on('click', function() {
        if (!confirm('This will refresh WooCommerce data for all products in the database. Continue?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Refreshing...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'refresh_wc_data',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var result = response.data;
                    
                    if (result.success) {
                        alert('WooCommerce data refreshed for ' + result.updated + ' products.');
                        loadDbProducts(); // Reload products
                    } else {
                        alert('Error: ' + result.message);
                    }
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Refresh WooCommerce Data');
            }
        });
    });

    // Find WooCommerce products not in Pohoda
    $('#find-orphan-wc-products').on('click', function() {
        var $button = $(this);
        var $result = $('#orphan-products-result');
        
        $button.prop('disabled', true).text('Finding...');
        $result.html('<div class="notice notice-info"><p>Looking for WooCommerce products not in Pohoda...</p></div>');
        
        // Log the request data
        console.log('Sending AJAX request with data:', {
            action: 'find_orphan_wc_products',
            nonce: pohodaAdmin.nonce
        });
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'find_orphan_wc_products',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                console.log('Response received:', response);
                
                // Process the response
                if (response && response.success) {
                    if (response.products && response.products.length > 0) {
                        console.log('Found ' + response.products.length + ' orphaned products');
                        renderOrphanProducts(response.products);
                    } else {
                        $result.html('<div class="notice notice-success"><p>No WooCommerce products found that are not in Pohoda.</p></div>');
                    }
                } else {
                    console.error('Error response:', response);
                    $result.html('<div class="notice notice-error"><p>Error: ' + (response ? response.message : 'Unknown error') + '</p></div>');
                    
                    // If debug info is available, display it
                    if (response && response.debug) {
                        var debugHtml = '<div class="notice notice-warning"><p>Debug Information:</p><pre>' + 
                            JSON.stringify(response.debug, null, 2) + '</pre></div>';
                        $result.append(debugHtml);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {xhr: xhr, status: status, error: error});
                
                var responseText = xhr.responseText || '';
                var errorMessage = 'Ajax Error: ' + error;
                
                // Try to extract json from the response if it's embedded in other content
                var jsonMatch = responseText.match(/\{.*\}/s);
                if (jsonMatch) {
                    try {
                        var extractedJson = jsonMatch[0];
                        console.log('Extracted JSON:', extractedJson);
                        var jsonResponse = JSON.parse(extractedJson);
                        
                        if (jsonResponse && jsonResponse.message) {
                            errorMessage += '<br>Server says: ' + jsonResponse.message;
                        }
                    } catch (e) {
                        console.error('Error parsing extracted JSON:', e);
                    }
                }
                
                // Show the raw response for debugging
                errorMessage += '<br><br>Raw response:<br><pre style="max-height:200px;overflow:auto;background:#f5f5f5;padding:10px;border:1px solid #ddd;">' + 
                    responseText + '</pre>';
                    
                $result.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
            },
            complete: function() {
                console.log('AJAX request complete');
                $button.prop('disabled', false).text('Find WooCommerce Products Not In Pohoda');
            }
        });
    });
    
    function renderOrphanProducts(products) {
        var $container = $('#orphan-products-result');
        
        // Clear any existing content
        $container.empty();
        
        // Add count message
        $container.append('<div class="notice notice-warning"><p>Found ' + products.length + ' WooCommerce products not in Pohoda database.</p></div>');
        
        // Add bulk action buttons
        var bulkActions = '<div class="tablenav top" style="margin-bottom: 10px;">' +
            '<div class="alignleft actions bulkactions">' +
            '<button id="select-all-orphans" class="button">Select All</button> ' +
            '<button id="select-zero-stock" class="button">Select Zero Stock</button> ' +
            '<button id="delete-selected-orphans" class="button button-primary delete-wc-product-bulk">Delete Selected</button>' +
            '</div>' +
            '<div class="tablenav-pages" style="margin: 0 0 0 auto">' +
            '<span class="displaying-num">' + products.length + ' items</span>' +
            '</div>' +
            '</div>';
        $container.append(bulkActions);
        
        // Create table
        var table = '<table class="wp-list-table widefat fixed striped">';
        table += '<thead><tr>';
        table += '<th class="check-column"><input type="checkbox" id="cb-select-all-orphans"></th>';
        table += '<th>ID</th>';
        table += '<th>SKU</th>';
        table += '<th>Name</th>';
        table += '<th>Price</th>';
        table += '<th>Stock</th>';
        table += '<th>Actions</th>';
        table += '</tr></thead><tbody>';
        
        products.forEach(function(product) {
            var isZeroStock = product.stock === 0 || product.stock === '0' || product.stock === 'n/a';
            var stockClass = isZeroStock ? 'zero-stock' : '';
            
            table += '<tr class="' + stockClass + '">';
            table += '<td><input type="checkbox" class="cb-select-orphan" data-product-id="' + product.id + '"></td>';
            table += '<td>' + product.id + '</td>';
            table += '<td>' + (product.sku || '') + '</td>';
            table += '<td>' + product.name + '</td>';
            table += '<td>' + product.price + '</td>';
            table += '<td>' + product.stock + '</td>';
            table += '<td>';
            table += '<button class="button button-small delete-wc-product" data-product-id="' + product.id + '">Delete</button> ';
            table += '<button class="button button-small hide-wc-product" data-product-id="' + product.id + '">Hide</button> ';
            table += '<a href="' + product.edit_url + '" target="_blank" class="button button-small edit-wc-button"><span class="dashicons dashicons-edit"></span></a>';
            table += '</td>';
            table += '</tr>';
        });
        
        table += '</tbody></table>';
        $container.append(table);
        
        // Add bulk actions at the bottom too for convenience
        $container.append(bulkActions);
        
        // Add event handlers for checkbox controls
        $('#cb-select-all-orphans').on('change', function() {
            $('.cb-select-orphan').prop('checked', $(this).prop('checked'));
        });
        
        // Select all button
        $('#select-all-orphans').on('click', function() {
            $('.cb-select-orphan').prop('checked', true);
            $('#cb-select-all-orphans').prop('checked', true);
        });
        
        // Select zero stock button
        $('#select-zero-stock').on('click', function() {
            $('.cb-select-orphan').prop('checked', false);
            $('.zero-stock .cb-select-orphan').prop('checked', true);
            // Update the "select all" checkbox state based on if all checkboxes are selected
            var allChecked = $('.cb-select-orphan').length === $('.cb-select-orphan:checked').length;
            $('#cb-select-all-orphans').prop('checked', allChecked);
        });
        
        // Delete selected button
        $('.delete-wc-product-bulk').on('click', function() {
            var selectedIds = [];
            $('.cb-select-orphan:checked').each(function() {
                selectedIds.push($(this).data('product-id'));
            });
            
            if (selectedIds.length === 0) {
                alert('Please select at least one product to delete.');
                return;
            }
            
            if (!confirm('Are you sure you want to delete ' + selectedIds.length + ' selected products? This cannot be undone.')) {
                return;
            }
            
            deleteBulkProducts(selectedIds);
        });
        
        // Log completion for debugging
        console.log('Rendered table for ' + products.length + ' products');
    }
    
    // Function to delete multiple products in bulk
    function deleteBulkProducts(productIds) {
        if (!productIds || productIds.length === 0) {
            return;
        }
        
        var $progressBar = $('<div class="progress-bar-container"><div class="progress-bar-inner" style="width: 0%"></div></div>');
        var $progressText = $('<div class="progress-text">Processing 0 of ' + productIds.length + ' products</div>');
        var $progressContainer = $('<div class="notice notice-info"></div>').append($progressBar, $progressText);
        
        // Insert progress bar at the top of the results
        $('#orphan-products-result').prepend($progressContainer);
        
        var processed = 0;
        var successful = 0;
        var failed = 0;
        
        // Process each product one by one to avoid overwhelming the server
        function processNext(index) {
            if (index >= productIds.length) {
                // All done
                $progressBar.find('.progress-bar-inner').css('width', '100%');
                $progressText.text('Completed: ' + successful + ' deleted, ' + failed + ' failed');
                
                // Update the product list
                setTimeout(function() {
                    if (successful > 0) {
                        // Remove deleted products from the table
                        productIds.forEach(function(id) {
                            $('.cb-select-orphan[data-product-id="' + id + '"]').closest('tr').remove();
                        });
                        
                        // Update product count
                        var remainingCount = $('#orphan-products-result table tbody tr').length;
                        $('.displaying-num').text(remainingCount + ' items');
                        
                        // If no products left, show success message
                        if (remainingCount === 0) {
                            $('#orphan-products-result').html('<div class="notice notice-success"><p>All orphaned products have been removed.</p></div>');
                        } else {
                            // Replace the progress with a success message
                            $progressContainer.removeClass('notice-info').addClass('notice-success')
                                .html('<p>' + successful + ' products deleted successfully. ' + failed + ' failed.</p>');
                        }
                    } else {
                        // Show error message
                        $progressContainer.removeClass('notice-info').addClass('notice-error')
                            .html('<p>Failed to delete any products. Please try again.</p>');
                    }
                }, 500);
                return;
            }
            
            var productId = productIds[index];
            var percent = Math.round(index / productIds.length * 100);
            
            $progressBar.find('.progress-bar-inner').css('width', percent + '%');
            $progressText.text('Processing ' + (index+1) + ' of ' + productIds.length + ' products');
            
            $.ajax({
                url: pohodaAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'delete_orphan_wc_product',
                    nonce: pohodaAdmin.nonce,
                    product_id: productId
                },
                success: function(response) {
                    processed++;
                    
                    if (response.success) {
                        successful++;
                    } else {
                        failed++;
                        console.error('Failed to delete product ID ' + productId + ':', response);
                    }
                    
                    // Process next product
                    processNext(index + 1);
                },
                error: function(xhr, status, error) {
                    processed++;
                    failed++;
                    console.error('AJAX error while deleting product ID ' + productId + ':', error);
                    
                    // Process next product
                    processNext(index + 1);
                }
            });
        }
        
        // Start processing
        processNext(0);
    }

    // Handle deletion of orphan WooCommerce products
    $(document).on('click', '.delete-wc-product', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        
        if (!confirm('Are you sure you want to permanently delete this WooCommerce product (ID: ' + productId + ')?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Deleting...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_orphan_wc_product',
                nonce: pohodaAdmin.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    // Remove row from table
                    $button.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        
                        // Update count message
                        var newCount = $('#orphan-products-result table tbody tr').length;
                        $('#orphan-products-result .notice p').text('Found ' + newCount + ' WooCommerce products not in Pohoda database.');
                        
                        // If no products left, show success message
                        if (newCount === 0) {
                            $('#orphan-products-result').html('<div class="notice notice-success"><p>All orphaned products have been removed.</p></div>');
                        }
                    });
                } else {
                    alert('Error: ' + (response.data || 'Failed to delete product'));
                    $button.prop('disabled', false).text('Delete');
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
                $button.prop('disabled', false).text('Delete');
            }
        });
    });
    
    // Handle hiding orphan WooCommerce products (set to private)
    $(document).on('click', '.hide-wc-product', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        
        if (!confirm('Are you sure you want to hide this WooCommerce product (ID: ' + productId + ')? It will be set to private.')) {
            return;
        }
        
        $button.prop('disabled', true).text('Hiding...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'hide_orphan_wc_product',
                nonce: pohodaAdmin.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    // Update the row to indicate it's now hidden
                    $button.closest('tr').css('opacity', '0.5');
                    $button.text('Hidden').prop('disabled', true);
                } else {
                    alert('Error: ' + (response.data || 'Failed to hide product'));
                    $button.prop('disabled', false).text('Hide');
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
                $button.prop('disabled', false).text('Hide');
            }
        });
    });

    // Handle sync db product button clicks (delegated)
    $(document).on('click', '.sync-db-product', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        var vatRate = $button.data('vat-rate') || 21; // Default to 21% if not specified
        
        $button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_db_product',
                nonce: pohodaAdmin.nonce,
                product_id: productId,
                vat_rate: vatRate
            },
            success: function(response) {
                if (response.success) {
                    // Update row appearance
                    var $row = $button.closest('tr');
                    $row.removeClass('pohoda-row-mismatch pohoda-row-unknown')
                        .addClass('pohoda-row-match');
                    
                    // Update WooCommerce status cell
                    var $statusCell = $row.find('td:nth-child(7)');
                    $statusCell.html('<span class="woo-exists">Synced</span>');
                    
                    // Update stock value if available
                    if (response.data.stock !== undefined) {
                        $row.find('td:nth-child(4)').text(response.data.stock);
                    }
                    
                    // Update price display with VAT
                    if (response.data.price !== undefined) {
                        // Get the base price from the existing cell
                        var basePrice = $row.find('td:nth-child(5)').text();
                        // Update the VAT price cell
                        $row.find('td:nth-child(6)').html(response.data.price + ' (' + response.data.vat_rate + '%)');
                    }
                    
                    $button.prop('disabled', false).text('Synced!');
                    setTimeout(function() {
                        $button.text('Sync');
                    }, 3000);
                } else {
                    alert('Error syncing product: ' + response.data);
                    $button.prop('disabled', false).text('Retry Sync');
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
                $button.prop('disabled', false).text('Retry Sync');
            }
        });
    });

    // Handle create in WooCommerce button
    $(document).on('click', '.create-in-wc', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        var productCode = $button.data('product-code');
        var productName = $button.data('product-name');
        var productPrice = $button.data('product-price');
        var productStock = $button.data('product-stock');
        var vatRate = $button.data('vat-rate');
        
        if (!confirm('Create product "' + decodeURIComponent(productName) + '" in WooCommerce?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Creating...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'create_wc_product',
                nonce: pohodaAdmin.nonce,
                product_id: productId,
                product_code: productCode,
                product_name: productName,
                product_price: productPrice,
                product_stock: productStock,
                vat_rate: vatRate
            },
            success: function(response) {
                if (response.success) {
                    // Update row in the DOM
                    var $row = $button.closest('tr');
                    $row.removeClass('pohoda-row-missing')
                        .addClass('pohoda-row-match');
                    
                    // Update status cell
                    $row.find('td:nth-child(7)').html('<span class="woo-exists">Synced</span>');
                    
                    // Replace action buttons
                    var actionHtml = '<button class="button button-primary sync-db-product" data-product-id="' + 
                        productId + '" data-vat-rate="' + vatRate + '">Sync</button>';
                    
                    // Add view and edit links if we have the URLs
                    if (response.data && response.data.product_id) {
                        var frontendUrl = '/?p=' + response.data.product_id;
                        var editUrl = response.data.edit_url || 'post.php?post=' + response.data.product_id + '&action=edit';
                        
                        actionHtml += ' <a href="' + frontendUrl + '" target="_blank" class="button view-woo-button" ' +
                            'title="View product on frontend"><span class="dashicons dashicons-visibility"></span></a>';
                        actionHtml += ' <a href="' + editUrl + '" target="_blank" class="button edit-woo-button" ' +
                            'title="Edit in WooCommerce admin"><span class="dashicons dashicons-edit"></span></a>';
                    }
                    
                    $row.find('td:last-child').html(actionHtml);
                    
                    alert('Product created successfully!');
                } else {
                    alert('Error: ' + response.data);
                    $button.prop('disabled', false).text('Create in WC');
                }
            },
            error: function() {
                alert('Failed to create product. Please try again.');
                $button.prop('disabled', false).text('Create in WC');
            }
        });
    });

    // Ensure product details modal shows properly
    $(document).on('click', '.show-product-details', function() {
        var $data = $(this).siblings('.product-details-data');
        var files = JSON.parse($data.attr('data-files') || '[]');
        var pictures = JSON.parse($data.attr('data-pictures') || '[]');
        var categories = JSON.parse($data.attr('data-categories') || '[]');
        var related = JSON.parse($data.attr('data-related') || '[]');
        var alternatives = JSON.parse($data.attr('data-alternatives') || '[]');
        var productName = $data.attr('data-product-name');
        
        // Create modal if it doesn't exist
        if ($('#product-details-modal').length === 0) {
            $('body').append(`
                <div id="product-details-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:999999;">
                    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:20px; border-radius:5px; width:80%; max-width:800px; max-height:80vh; overflow-y:auto;">
                        <h2 id="modal-product-name"></h2>
                        <div class="modal-tabs">
                            <button class="modal-tab active" data-tab="files">Files</button>
                            <button class="modal-tab" data-tab="pictures">Pictures</button>
                            <button class="modal-tab" data-tab="categories">Categories</button>
                            <button class="modal-tab" data-tab="related">Related Products</button>
                            <button class="modal-tab" data-tab="alternatives">Alternative Products</button>
                        </div>
                        <div class="modal-content">
                            <div id="tab-files" class="tab-content active"></div>
                            <div id="tab-pictures" class="tab-content"></div>
                            <div id="tab-categories" class="tab-content"></div>
                            <div id="tab-related" class="tab-content"></div>
                            <div id="tab-alternatives" class="tab-content"></div>
                        </div>
                        <button id="close-details-modal" class="button button-primary" style="margin-top:20px;">Close</button>
                    </div>
                </div>
            `);
            
            // Add styles for modal
            $('<style>')
                .text(`
                    .modal-tabs { margin-bottom: 15px; border-bottom: 1px solid #ccc; }
                    .modal-tab { border: none; background: none; padding: 8px 15px; cursor: pointer; margin-right: 5px; }
                    .modal-tab.active { font-weight: bold; border-bottom: 2px solid #2271b1; }
                    .tab-content { display: none; }
                    .tab-content.active { display: block; }
                    .file-item, .picture-item, .category-item, .related-item, .alternative-item { 
                        margin-bottom: 10px; 
                        padding: 10px; 
                        background: #f9f9f9; 
                        border: 1px solid #eee; 
                    }
                    .hidden { display: none; }
                `)
                .appendTo('head');
            
            // Close modal
            $(document).on('click', '#close-details-modal', function() {
                $('#product-details-modal').hide();
            });
            
            // Tab switching
            $(document).on('click', '.modal-tab', function() {
                $('.modal-tab').removeClass('active');
                $(this).addClass('active');
                
                var tab = $(this).data('tab');
                $('.tab-content').removeClass('active');
                $('#tab-' + tab).addClass('active');
            });
        }
        
        $('#modal-product-name').text(productName);
        
        // Populate files tab
        var filesHtml = '';
        if (files.length > 0) {
            files.forEach(function(file) {
                filesHtml += '<div class="file-item">';
                filesHtml += '<div><strong>Filename:</strong> ' + file.filepath + '</div>';
                if (file.description) {
                    filesHtml += '<div><strong>Description:</strong> ' + file.description + '</div>';
                }
                filesHtml += '</div>';
            });
        } else {
            filesHtml = '<p>No related files found.</p>';
        }
        $('#tab-files').html(filesHtml);
        
        // Populate pictures tab
        var picturesHtml = '';
        if (pictures.length > 0) {
            pictures.forEach(function(picture) {
                picturesHtml += '<div class="picture-item">';
                picturesHtml += '<div><strong>Filename:</strong> ' + picture.filepath + '</div>';
                if (picture.description) {
                    picturesHtml += '<div><strong>Description:</strong> ' + picture.description + '</div>';
                }
                if (picture.default) {
                    picturesHtml += '<div><strong>Default picture</strong></div>';
                }
                picturesHtml += '</div>';
            });
        } else {
            picturesHtml = '<p>No pictures found.</p>';
        }
        $('#tab-pictures').html(picturesHtml);
        
        // Populate categories tab
        var categoriesHtml = '';
        if (categories.length > 0) {
            categoriesHtml = '<ul>';
            categories.forEach(function(category) {
                categoriesHtml += '<li>Category ID: ' + category + '</li>';
            });
            categoriesHtml += '</ul>';
        } else {
            categoriesHtml = '<p>No categories found.</p>';
        }
        $('#tab-categories').html(categoriesHtml);
        
        // Populate related products tab
        var relatedHtml = '';
        if (related.length > 0) {
            relatedHtml = '<ul>';
            related.forEach(function(stockId) {
                relatedHtml += '<li>Related Stock ID: ' + stockId + '</li>';
            });
            relatedHtml += '</ul>';
        } else {
            relatedHtml = '<p>No related products found.</p>';
        }
        $('#tab-related').html(relatedHtml);
        
        // Populate alternative products tab
        var alternativesHtml = '';
        if (alternatives.length > 0) {
            alternativesHtml = '<ul>';
            alternatives.forEach(function(stockId) {
                alternativesHtml += '<li>Alternative Stock ID: ' + stockId + '</li>';
            });
            alternativesHtml += '</ul>';
        } else {
            alternativesHtml = '<p>No alternative products found.</p>';
        }
        $('#tab-alternatives').html(alternativesHtml);
        
        // Show the first tab
        $('.modal-tab').removeClass('active');
        $('.modal-tab[data-tab="files"]').addClass('active');
        $('.tab-content').removeClass('active');
        $('#tab-files').addClass('active');
        
        // Display modal
        $('#product-details-modal').show();
    });

    // Handle sync all mismatched products button
    $('#sync-all-mismatched').on('click', function() {
        if (!confirm('This will sync all mismatched products from Pohoda to WooCommerce. Continue?')) {
            return;
        }
        
        var $button = $(this);
        var $progress = $('#sync-progress');
        var $progressBar = $progress.find('.progress-bar-inner');
        var $progressCurrent = $('#progress-current');
        var $progressTotal = $('#progress-total');
        
        $button.prop('disabled', true);
        $progress.show();
        $progressBar.css('width', '0%');
        $progressCurrent.text('0');
        $progressTotal.text('Loading...');
        
        // First, get all mismatched products directly from the current page 
        // to see if there are any before making the AJAX request
        var mismatchedRowsInDOM = $('tr.pohoda-row-mismatch').length;
        console.log('Mismatched rows found in DOM:', mismatchedRowsInDOM);
        
        if (mismatchedRowsInDOM > 0) {
            // Collect products from visible rows
            var visibleProducts = [];
            $('tr.pohoda-row-mismatch').each(function() {
                var $row = $(this);
                var productId = $row.find('td:first-child').text();
                var vatRate = 21; // Default
                
                // Extract VAT rate from the displayed VAT cell
                var vatText = $row.find('td:nth-child(6)').text();
                var vatMatch = vatText.match(/\(([^)]+)%\)/);
                if (vatMatch) {
                    vatRate = parseFloat(vatMatch[1]);
                }
                
                visibleProducts.push({
                    id: productId,
                    vat_rate: vatRate
                });
            });
            
            console.log('Visible mismatched products:', visibleProducts.length);
            
            // Function to sync a batch of products
            function syncProducts(products) {
                var totalToSync = products.length;
                var processed = 0;
                
                $progressTotal.text(totalToSync);
                $progressCurrent.text(processed);
                $progressBar.css('width', '0%');
                
                function syncNext(index) {
                    if (index >= totalToSync) {
                        // All done
                        $button.prop('disabled', false);
                        alert('All ' + totalToSync + ' visible mismatched products have been synced!');
                        $progress.hide();
                        return;
                    }
                    
                    var product = products[index];
                    console.log('Syncing product ID:', product.id);
                    
                    $.ajax({
                        url: pohodaAdmin.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'sync_db_product',
                            nonce: pohodaAdmin.nonce,
                            product_id: product.id,
                            vat_rate: product.vat_rate
                        },
                        success: function(syncResponse) {
                            processed++;
                            $progressCurrent.text(processed);
                            $progressBar.css('width', (processed / totalToSync * 100) + '%');
                            
                            // Update row in DOM
                            var $row = $('tr').filter(function() {
                                return $(this).find('td:first-child').text() == product.id;
                            });
                            
                            if ($row.length) {
                                // Update row appearance
                                $row.removeClass('pohoda-row-mismatch pohoda-row-unknown')
                                    .addClass('pohoda-row-match');
                                
                                // Update status cell
                                $row.find('td:nth-child(7)').html('<span class="woo-exists">Synced</span>');
                                
                                // Update stock and price display if available in response
                                if (syncResponse.success && syncResponse.data) {
                                    if (syncResponse.data.stock !== undefined) {
                                        $row.find('td:nth-child(4)').text(syncResponse.data.stock);
                                    }
                                    
                                    if (syncResponse.data.price !== undefined) {
                                        $row.find('td:nth-child(6)').html(
                                            syncResponse.data.price + ' (' + syncResponse.data.vat_rate + '%)'
                                        );
                                    }
                                }
                            }
                            
                            // Continue with next product
                            setTimeout(function() {
                                syncNext(index + 1);
                            }, 50);
                        },
                        error: function(xhr, status, error) {
                            console.error('Error syncing product:', product.id, error);
                            
                            // Continue anyway
                            processed++;
                            $progressCurrent.text(processed);
                            $progressBar.css('width', (processed / totalToSync * 100) + '%');
                            
                            setTimeout(function() {
                                syncNext(index + 1);
                            }, 50);
                        }
                    });
                }
                
                // Start syncing
                syncNext(0);
            }
            
            // Start syncing the visible products
            syncProducts(visibleProducts);
        } else {
            // No visible mismatched products, try to get them from the server
            console.log('No visible mismatched products, checking server...');
            
            // First, get ALL mismatched products across all pages
            $.ajax({
                url: pohodaAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_all_mismatched_products',
                    nonce: pohodaAdmin.nonce
                },
                success: function(response) {
                    console.log('Full response from get_all_mismatched_products:', response);
                    
                    if (response.success && response.data) {
                        var debug = response.data.debug || {};
                        console.log('Debug info:', debug);
                        
                        // Check if products array exists
                        if (!response.data.products || !Array.isArray(response.data.products)) {
                            console.error('Invalid products data:', response.data);
                            alert('Error: Invalid product data returned from server. Check console for details.');
                            $button.prop('disabled', false);
                            $progress.hide();
                            return;
                        }
                        
                        var mismatchedProducts = response.data.products;
                        var totalProducts = mismatchedProducts.length;
                        
                        console.log('Mismatched products found on server:', totalProducts);
                        
                        if (totalProducts === 0) {
                            // Show more detailed debug message
                            var errorMessage = 'No mismatched products found on server. ';
                            if (debug.pages_processed) {
                                errorMessage += 'Server processed ' + debug.pages_processed + ' pages. ';
                            }
                            
                            // Show status counts if available
                            if (debug.all_status_counts) {
                                errorMessage += '\nProduct statuses: ';
                                for (var status in debug.all_status_counts) {
                                    errorMessage += status + ': ' + debug.all_status_counts[status] + ', ';
                                }
                            }
                            
                            alert(errorMessage);
                            $button.prop('disabled', false);
                            $progress.hide();
                            return;
                        }
                        
                        var processedProducts = 0;
                        $progressTotal.text(totalProducts);
                        $progressCurrent.text(processedProducts);
                        
                        // Process products one by one
                        function syncNextProduct(index) {
                            if (index >= totalProducts) {
                                // All products processed
                                $button.prop('disabled', false);
                                alert('All ' + totalProducts + ' mismatched products have been synced!');
                                $progress.hide();
                                return;
                            }
                            
                            var product = mismatchedProducts[index];
                            console.log('Syncing product:', product.id, product.name);
                            
                            $.ajax({
                                url: pohodaAdmin.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'sync_db_product',
                                    nonce: pohodaAdmin.nonce,
                                    product_id: product.id,
                                    vat_rate: product.vat_rate || 21
                                },
                                success: function(syncResponse) {
                                    processedProducts++;
                                    $progressCurrent.text(processedProducts);
                                    $progressBar.css('width', (processedProducts / totalProducts * 100) + '%');
                                    
                                    console.log('Product synced:', product.id, 'Progress:', processedProducts + '/' + totalProducts);
                                    
                                    // Update row in DOM if it exists
                                    var $row = $('tr').filter(function() {
                                        return $(this).find('td:first-child').text() == product.id;
                                    });
                                    
                                    if ($row.length) {
                                        // Update row appearance
                                        $row.removeClass('pohoda-row-mismatch pohoda-row-unknown')
                                            .addClass('pohoda-row-match');
                                        
                                        // Update status cell
                                        $row.find('td:nth-child(7)').html('<span class="woo-exists">Synced</span>');
                                        
                                        // Update stock and price display if available in response
                                        if (syncResponse.success && syncResponse.data) {
                                            if (syncResponse.data.stock !== undefined) {
                                                $row.find('td:nth-child(4)').text(syncResponse.data.stock);
                                            }
                                            
                                            if (syncResponse.data.price !== undefined) {
                                                $row.find('td:nth-child(6)').html(
                                                    syncResponse.data.price + ' (' + syncResponse.data.vat_rate + '%)'
                                                );
                                            }
                                        }
                                    }
                                    
                                    // Process next product after a small delay to avoid overwhelming the server
                                    setTimeout(function() {
                                        syncNextProduct(index + 1);
                                    }, 50);
                                },
                                error: function(xhr, status, error) {
                                    console.error('Error syncing product:', product.id, error);
                                    
                                    // Continue with next product even if this one fails
                                    processedProducts++;
                                    $progressCurrent.text(processedProducts);
                                    $progressBar.css('width', (processedProducts / totalProducts * 100) + '%');
                                    
                                    setTimeout(function() {
                                        syncNextProduct(index + 1);
                                    }, 50);
                                }
                            });
                        }
                        
                        // Start processing
                        syncNextProduct(0);
                    } else {
                        console.error('Failed to retrieve mismatched products:', response);
                        
                        // Show more detailed error message
                        var errorMessage = 'Failed to retrieve mismatched products. ';
                        if (response.data && response.data.debug) {
                            errorMessage += 'Debug info: ' + JSON.stringify(response.data.debug);
                        }
                        
                        alert(errorMessage);
                        $button.prop('disabled', false);
                        $progress.hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error, xhr.responseText);
                    alert('Error: ' + error);
                    $button.prop('disabled', false);
                    $progress.hide();
                }
            });
        }
    });

    // Handle create all missing products button
    $('#create-all-missing').on('click', function() {
        console.log('Pohoda DB JS: Create All Missing button clicked, proceeding (confirmation skipped).');
        
        var $button = $(this);
        var $progress = $('#sync-progress');
        var $progressBar = $progress.find('.progress-bar-inner');
        var $progressCurrent = $('#progress-current');
        var $progressTotal = $('#progress-total');
        
        $button.prop('disabled', true);
        $progress.show();
        $progressBar.css('width', '0%');
        $progressCurrent.text('0');
        $progressTotal.text('Loading...');
        
        // Get all missing products first
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_all_missing_products',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data && response.data.products) {
                    var missingProducts = response.data.products;
                    var totalProducts = missingProducts.length;
                    
                    if (totalProducts === 0) {
                        alert('No missing products found.');
                        $button.prop('disabled', false);
                        $progress.hide();
                        return;
                    }
                    
                    $progressTotal.text(totalProducts);
                    
                    // Track the results
                    var totalCreated = 0;
                    var totalFailed = 0;
                    var allErrors = [];
                    
                    // Process products in batches
                    function processBatch(startIndex) {
                        if (startIndex >= totalProducts) {
                            // All products processed
                            $button.prop('disabled', false);
                            $progressBar.css('width', '100%');
                            
                            var message = 'Created ' + totalCreated + ' products. Failed: ' + totalFailed;
                            if (totalFailed > 0 && allErrors.length > 0) {
                                message += '\n\nErrors:\n' + allErrors.join('\n');
                            }
                            
                            alert(message);
                            
                            // Update rows in the DOM for successfully created products
                            updateRowsInDom();
                            
                            $progress.hide();
                            return;
                        }
                        
                        var batchSize = 20; // Process in smaller batches to avoid timeouts
                        
                        $.ajax({
                            url: pohodaAdmin.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'create_all_missing_products',
                                nonce: pohodaAdmin.nonce,
                                products: missingProducts,
                                start_index: startIndex,
                                batch_size: batchSize,
                                total: totalProducts
                            },
                            success: function(batchResponse) {
                                if (batchResponse.success) {
                                    var result = batchResponse.data;
                                    
                                    // Accumulate results
                                    totalCreated += result.created;
                                    totalFailed += result.failed;
                                    
                                    if (result.errors && result.errors.length > 0) {
                                        allErrors = allErrors.concat(result.errors);
                                    }
                                    
                                    // Update progress
                                    $progressCurrent.text(result.end_index);
                                    $progressBar.css('width', (result.end_index / totalProducts * 100) + '%');
                                    
                                    // Process next batch after a small delay
                                    setTimeout(function() {
                                        processBatch(result.end_index);
                                    }, 100);
                                } else {
                                    alert('Error processing batch: ' + (batchResponse.data || 'Unknown error'));
                                    $button.prop('disabled', false);
                                    $progress.hide();
                                }
                            },
                            error: function(xhr, status, error) {
                                alert('Error: ' + error);
                                $button.prop('disabled', false);
                                $progress.hide();
                            }
                        });
                    }
                    
                    // Function to update DOM for created products
                    function updateRowsInDom() {
                        if (totalCreated === 0) return;
                        
                        // Get product IDs that were successfully created
                        var createdIds = [];
                        for (var i = 0; i < missingProducts.length; i++) {
                            var productId = missingProducts[i].id;
                            // If the product isn't in the error list, assume it was created
                            var hasError = allErrors.some(function(error) {
                                return error.indexOf('Product ID ' + productId + ':') >= 0 ||
                                       error.indexOf('(code: ' + missingProducts[i].code + ')') >= 0;
                            });
                            
                            if (!hasError) {
                                createdIds.push(productId);
                            }
                        }
                        
                        // Update visible rows
                        $('tr.pohoda-row-missing').each(function() {
                            var $row = $(this);
                            var productId = $row.find('td:first-child').text();
                            
                            // If this was one of the created products
                            if (createdIds.indexOf(productId) !== -1) {
                                // Update status
                                $row.removeClass('pohoda-row-missing').addClass('pohoda-row-match');
                                $row.find('td:nth-child(7)').html('<span class="woo-exists">Synced</span>');
                                
                                // Update actions cell with sync button
                                var vatRate = 21; // Default
                                if ($row.find('td:nth-child(6)').text().match(/\(([^)]+)%\)/)) {
                                    vatRate = $row.find('td:nth-child(6)').text().match(/\(([^)]+)%\)/)[1];
                                }
                                
                                var actionHtml = '<button class="button button-primary sync-db-product" ' +
                                    'data-product-id="' + productId + '" data-vat-rate="' + vatRate + '">Sync</button>';
                                
                                $row.find('td:last-child').html(actionHtml);
                            }
                        });
                    }
                    
                    // Start processing batches
                    processBatch(0);
                } else {
                    alert('Error: ' + (response.data || 'Failed to retrieve missing products'));
                    $button.prop('disabled', false);
                    $progress.hide();
                }
            },
            error: function(xhr, status, error) {
                alert('Error: ' + error);
                $button.prop('disabled', false);
                $progress.hide();
            }
        });
    });

    // After the sync-all-products button
    $('#sync-all-products').after(' <button id="check-db-status" class="button">Check DB Status</button>');

    // Handle check DB status button
    $('#check-db-status').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Checking...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'check_db_status',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var debugInfo = response.data;
                    
                    // Create modal dialog to display the info
                    var $dialog = $('<div id="db-status-dialog" title="Database Status"></div>');
                    var html = '<div style="max-height: 500px; overflow-y: auto;">';
                    
                    // Products table
                    html += '<h3>Products Table</h3>';
                    html += '<p>Name: ' + debugInfo.products_table.name + '</p>';
                    html += '<p>Exists: ' + (debugInfo.products_table.exists ? 'Yes' : 'No') + '</p>';
                    
                    if (debugInfo.products_table.exists) {
                        html += '<p>Count: ' + debugInfo.products_table.count + ' products</p>';
                        html += '<p>Columns: ' + debugInfo.products_table.columns.join(', ') + '</p>';
                    }
                    
                    // Images table
                    html += '<h3>Images Table</h3>';
                    html += '<p>Name: ' + debugInfo.images_table.name + '</p>';
                    html += '<p>Exists: ' + (debugInfo.images_table.exists ? 'Yes' : 'No') + '</p>';
                    
                    if (debugInfo.images_table.exists) {
                        html += '<p>Count: ' + debugInfo.images_table.count + ' images</p>';
                        html += '<p>Products with images: ' + debugInfo.images_table.products_with_images + '</p>';
                        html += '<p>Columns: ' + debugInfo.images_table.columns.join(', ') + '</p>';
                        
                        // Status counts
                        html += '<h4>Image Sync Status</h4>';
                        html += '<ul>';
                        for (var status in debugInfo.images_table.status_counts) {
                            html += '<li>' + status + ': ' + debugInfo.images_table.status_counts[status] + '</li>';
                        }
                        html += '</ul>';
                    }
                    
                    // Create table result
                    html += '<h3>Create Images Table Result</h3>';
                    html += '<p>' + (debugInfo.create_images_table_result ? 'Table was created' : 'Table already exists') + '</p>';
                    
                    html += '<h3>Raw Data</h3>';
                    html += '<pre style="max-height: 200px; overflow: auto; background: #f5f5f5; padding: 10px; font-size: 12px;">' + 
                             JSON.stringify(debugInfo, null, 2) + '</pre>';
                    
                    html += '</div>';
                    
                    $dialog.html(html);
                    
                    // Create and open dialog
                    $('body').append($dialog);
                    $dialog.dialog({
                        modal: true,
                        width: 600,
                        height: 600,
                        buttons: {
                            Close: function() {
                                $(this).dialog('close');
                            }
                        },
                        close: function() {
                            $(this).remove();
                        }
                    });
                } else {
                    alert('Error checking DB status: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Check DB Status');
            }
        });
    });

    // After the check DB status button
    $('#check-db-status').after(' <button id="force-create-tables" class="button button-warning">Force Create Tables</button>');

    // Handle force create tables button
    $('#force-create-tables').on('click', function() {
        if (!confirm('This will force create database tables. Continue?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Creating...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'force_create_tables',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var result = response.data;
                    
                    // Create modal dialog to display the info
                    var $dialog = $('<div id="force-create-tables-dialog" title="Force Create Tables Result"></div>');
                    var html = '<div style="max-height: 500px; overflow-y: auto;">';
                    
                    // Results
                    html += '<h3>Products Table</h3>';
                    html += '<p>Created: ' + (result.products_table ? 'Yes' : 'No (table already exists)') + '</p>';
                    
                    html += '<h3>Images Table</h3>';
                    html += '<p>Created: ' + (result.images_table ? 'Yes' : 'No (table already exists)') + '</p>';
                    
                    // Status
                    html += '<h3>Current Status</h3>';
                    html += '<pre style="max-height: 200px; overflow: auto; background: #f5f5f5; padding: 10px; font-size: 12px;">' + 
                             JSON.stringify(result.status, null, 2) + '</pre>';
                    
                    html += '</div>';
                    
                    $dialog.html(html);
                    
                    // Create and open dialog
                    $('body').append($dialog);
                    $dialog.dialog({
                        modal: true,
                        width: 600,
                        height: 600,
                        buttons: {
                            Close: function() {
                                $(this).dialog('close');
                            }
                        },
                        close: function() {
                            $(this).remove();
                        }
                    });
                    
                    alert('Database tables created or updated!');
                } else {
                    alert('Error creating tables: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Ajax Error: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Force Create Tables');
            }
        });
    });

    // Make loadDbProducts available globally
    window.loadDbProducts = loadDbProducts;
}); 