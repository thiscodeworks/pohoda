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
                    
                    console.log('Mismatched products found:', totalProducts);
                    
                    if (totalProducts === 0) {
                        // Show more detailed debug message
                        var errorMessage = 'No mismatched products found. ';
                        if (debug.pages_processed) {
                            errorMessage += 'Server processed ' + debug.pages_processed + ' pages. ';
                        }
                        
                        // Show status counts if available
                        if (debug.page_results && debug.page_results.length > 0) {
                            var firstPage = debug.page_results[0];
                            if (firstPage.status_counts) {
                                errorMessage += '\nProduct statuses: ';
                                for (var status in firstPage.status_counts) {
                                    errorMessage += status + ': ' + firstPage.status_counts[status] + ', ';
                                }
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
    });

    // Handle create all missing products button
    $('#create-all-missing').on('click', function() {
        if (!confirm('This will create WooCommerce products for all missing items. Continue?')) {
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

    // Make loadDbProducts available globally
    window.loadDbProducts = loadDbProducts;
}); 