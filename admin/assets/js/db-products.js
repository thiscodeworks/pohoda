jQuery(document).ready(function($) {
    var currentPage = 1;
    var productsPerPage = 10;
    var totalPages = 1;

    // Initialize on page load for DB products tab
    if (window.location.href.indexOf('tab=db_products') > -1) {
        loadDbProducts();
    }

    // Load products function
    function loadDbProducts() {
        var $button = $('#load-db-products');
        var $result = $('#db-products-result');
        var $pagination = $('.db-pohoda-pagination');

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
        var $container = $('#db-products-result');
        
        var table = '<table class="wp-list-table widefat fixed striped">';
        table += '<thead><tr>';
        table += '<th>ID</th>';
        table += '<th>Code</th>';
        table += '<th>Name</th>';
        table += '<th>Stock</th>';
        table += '<th>Price</th>';
        table += '<th>WooCommerce</th>';
        table += '<th>Actions</th>';
        table += '<th>Details</th>';
        table += '</tr></thead><tbody>';
        
        products.forEach(function(product) {
            var rowClass = '';
            var wooDetails = '';
            
            if (product.woocommerce_exists) {
                if (product.comparison_status === 'match') {
                    rowClass = 'pohoda-row-match';
                    wooDetails = '<span class="woo-exists">Synced</span> ' +
                        '<a href="' + product.woocommerce_url + '" target="_blank" class="button button-small">Edit</a>';
                } else if (product.comparison_status === 'mismatch') {
                    rowClass = 'pohoda-row-mismatch';
                    
                    // Determine which fields are mismatched
                    var stockDiff = Math.abs(parseFloat(product.count) - parseFloat(product.woocommerce_stock));
                    var stockMatch = stockDiff <= 0.001;
                    
                    var priceDiff = Math.abs(parseFloat(product.selling_price) - parseFloat(product.woocommerce_price));
                    var priceMatch = priceDiff <= 0.01;
                    
                    var mismatchDetails = '';
                    if (!stockMatch) {
                        mismatchDetails += '<div class="mismatch-item">Stock: ' + 
                            'POHODA: ' + product.count + ' vs WC: ' + product.woocommerce_stock + '</div>';
                    }
                    if (!priceMatch) {
                        mismatchDetails += '<div class="mismatch-item">Price: ' + 
                            'POHODA: ' + product.selling_price + ' vs WC: ' + product.woocommerce_price + '</div>';
                    }
                    
                    wooDetails = '<span class="woo-mismatch">Mismatch</span> ' +
                        '<a href="' + product.woocommerce_url + '" target="_blank" class="button button-small">Edit</a>' +
                        '<div class="mismatch-details">' + mismatchDetails + '</div>';
                } else {
                    rowClass = 'pohoda-row-unknown';
                    wooDetails = '<span class="woo-unknown">Unknown</span> ' +
                        '<a href="' + product.woocommerce_url + '" target="_blank" class="button button-small">Edit</a>';
                }
            } else {
                rowClass = 'pohoda-row-missing';
                wooDetails = '<span class="woo-missing">Missing</span> ' + 
                    '<a href="post-new.php?post_type=product&sku=' + (product.code || '') + 
                    '&name=' + encodeURIComponent(product.name || '') + 
                    '&regular_price=' + (product.selling_price || '') + 
                    '&stock_quantity=' + (product.count || '') + 
                    '" target="_blank" class="button button-small">Create</a>';
            }
            
            table += '<tr class="' + rowClass + '">';
            table += '<td>' + (product.id || '') + '</td>';
            table += '<td>' + (product.code || '') + '</td>';
            table += '<td>' + (product.name || '') + '</td>';
            table += '<td>' + (product.count !== undefined ? product.count : '') + 
                (product.woocommerce_stock !== undefined && product.woocommerce_stock !== '' ? 
                ' <span class="wc-data">(WC: ' + product.woocommerce_stock + ')</span>' : '') + '</td>';
            table += '<td>' + (product.selling_price !== undefined ? product.selling_price : '') + 
                (product.woocommerce_price !== undefined && product.woocommerce_price !== '' ? 
                ' <span class="wc-data">(WC: ' + product.woocommerce_price + ')</span>' : '') + '</td>';
            table += '<td>' + wooDetails + '</td>';
            
            // Add new column with sync button (only for products that exist in WooCommerce)
            var syncButton = '';
            if (product.woocommerce_exists) {
                syncButton = '<button class="button button-primary sync-db-product" data-product-id="' + (product.id || '') + '">Sync</button>';
                
                // Add eye icon button to view product in WooCommerce
                if (product.woocommerce_url) {
                    syncButton += ' <a href="' + product.woocommerce_url + '" target="_blank" class="button button-secondary" title="View in WooCommerce"><span class="dashicons dashicons-visibility" style="margin-top: 2px;"></span></a>';
                }
            } else {
                syncButton = '-';
            }
            table += '<td>' + syncButton + '</td>';
            
            // Add new column for related files and details button
            var detailsButton = '';
            var hasDetails = (product.related_files && product.related_files.length > 0) || 
                           (product.pictures && product.pictures.length > 0) || 
                           (product.categories && product.categories.length > 0) ||
                           (product.related_stocks && product.related_stocks.length > 0) ||
                           (product.alternative_stocks && product.alternative_stocks.length > 0);
            
            if (hasDetails) {
                detailsButton = '<button class="button show-product-details" data-product-id="' + (product.id || '') + '">Details</button>';
                
                // Store details in data attribute to retrieve later
                detailsButton += '<div class="hidden product-details-data" ' +
                    'data-files=\'' + JSON.stringify(product.related_files || []) + '\' ' +
                    'data-pictures=\'' + JSON.stringify(product.pictures || []) + '\' ' +
                    'data-categories=\'' + JSON.stringify(product.categories || []) + '\' ' +
                    'data-related=\'' + JSON.stringify(product.related_stocks || []) + '\' ' +
                    'data-alternatives=\'' + JSON.stringify(product.alternative_stocks || []) + '\' ' +
                    'data-product-name=\'' + (product.name || '') + '\'></div>';
            } else {
                detailsButton = '-';
            }
            table += '<td>' + detailsButton + '</td>';
            
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
                  '.mismatch-item { margin-bottom: 2px; }')
            .appendTo('head');
    }

    function updatePagination(pagination) {
        if (!pagination) return;
        
        currentPage = pagination.current_page;
        totalPages = pagination.last_page;
        
        $('#db-page-info').text('Page ' + currentPage + ' of ' + totalPages);
        $('#db-prev-page').prop('disabled', currentPage <= 1);
        $('#db-next-page').prop('disabled', currentPage >= totalPages);
    }

    // Handle pagination
    $('#db-prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadDbProducts();
        }
    });

    $('#db-next-page').on('click', function() {
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

    // Handle sync db product button clicks (delegated)
    $(document).on('click', '.sync-db-product', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        
        $button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_db_product',
                nonce: pohodaAdmin.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    // Update row appearance
                    $button.closest('tr').removeClass('pohoda-row-mismatch pohoda-row-unknown')
                        .addClass('pohoda-row-match');
                    $button.closest('tr').find('.woo-mismatch, .woo-unknown').removeClass('woo-mismatch woo-unknown')
                        .addClass('woo-exists').text('Synced');
                    $button.closest('tr').find('.mismatch-details').remove();
                    
                    // Update the displayed values
                    if (response.data.stock !== undefined) {
                        $button.closest('tr').find('td:nth-child(4)').html(
                            response.data.stock + ' <span class="wc-data">(WC: ' + response.data.stock + ')</span>'
                        );
                    }
                    
                    if (response.data.price !== undefined) {
                        $button.closest('tr').find('td:nth-child(5)').html(
                            $button.closest('tr').find('td:nth-child(5)').text().split('(')[0] + 
                            ' <span class="wc-data">(WC: ' + response.data.price + ' with ' + response.data.vat_rate + '% VAT)</span>'
                        );
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
}); 