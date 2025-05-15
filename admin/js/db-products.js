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
          '.dashicons { line-height: 1.5 !important; width: 18px !important; height: 18px !important; font-size: 18px !important; vertical-align: middle !important; }' +
          '.pohoda-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #ddd; }' +
          '.delete-wc-product { background-color: #d9534f !important; color: white !important; border-color: #d43f3a !important; }' +
          '.hide-wc-product { background-color: #5bc0de !important; color: white !important; border-color: #46b8da !important; }')
    .appendTo('head'); 