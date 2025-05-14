jQuery(document).ready(function($) {
    var currentPage = 1;
    var lastId = 0;
    var perPage = 10;
    var isLoading = false;

    // Initialize on page load
    loadProducts();

    // Handle search form submit
    $('#pohoda-search-form').on('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        lastId = 0;
        loadProducts();
    });

    // Pagination handlers
    $('#pohoda-next-page').on('click', function(e) {
        e.preventDefault();
        if ($(this).hasClass('disabled')) return;
        
        currentPage++;
        loadProducts();
    });

    $('#pohoda-prev-page').on('click', function(e) {
        e.preventDefault();
        if ($(this).hasClass('disabled')) return;
        
        currentPage--;
        loadProducts();
    });

    // Load products function
    function loadProducts() {
        if (isLoading) return;
        isLoading = true;
        
        $('#pohoda-product-table tbody').html('<tr><td colspan="6" class="text-center">Loading...</td></tr>');
        $('#pohoda-pagination-status').text('Loading...');
        
        // Disable pagination buttons during load
        $('.pohoda-pagination-btn').addClass('disabled');
        
        var searchTerm = $('#pohoda-search').val();
        
        var data = {
            action: 'pohoda_get_products',
            search: searchTerm,
            page: currentPage,
            per_page: perPage,
            id_from: lastId > 0 && currentPage > 1 ? lastId : 0
        };

        $.ajax({
            url: pohoda_admin_vars.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                isLoading = false;
                
                console.log('Response:', response);
                
                if (response.success && response.data && response.data.success) {
                    var products = response.data.data;
                    var pagination = response.data.pagination;
                    
                    // Store last ID for next page request
                    if (pagination && pagination.last_id) {
                        lastId = pagination.last_id;
                    }
                    
                    renderProducts(products);
                    updatePagination(pagination);
                    
                    // Show raw XML if available
                    if (response.data.raw) {
                        $('#pohoda-raw-response').html(escapeHTML(response.data.raw));
                        $('#pohoda-raw-response-wrap').show();
                    } else {
                        $('#pohoda-raw-response-wrap').hide();
                    }
                } else {
                    var errorMessage = 'Error loading products';
                    if (response.data && typeof response.data === 'string') {
                        errorMessage = response.data;
                    } else if (response.data && response.data.data && typeof response.data.data === 'string') {
                        errorMessage = response.data.data;
                    }
                    
                    $('#pohoda-product-table tbody').html('<tr><td colspan="6" class="text-danger">' + errorMessage + '</td></tr>');
                    $('#pohoda-pagination-status').text('Error loading products');
                    
                    // Show raw XML for debugging if available
                    if (response.data && response.data.raw) {
                        $('#pohoda-raw-response').html(escapeHTML(response.data.raw));
                        $('#pohoda-raw-response-wrap').show();
                    }
                }
            },
            error: function(xhr, status, error) {
                isLoading = false;
                $('#pohoda-product-table tbody').html('<tr><td colspan="6" class="text-danger">Ajax Error: ' + error + '</td></tr>');
                $('#pohoda-pagination-status').text('Error loading products');
                $('.pohoda-pagination-btn').addClass('disabled');
            }
        });
    }

    // Render products function
    function renderProducts(products) {
        var tbody = $('#pohoda-product-table tbody');
        tbody.empty();

        if (!products || products.length === 0) {
            tbody.html('<tr><td colspan="6" class="text-center">No products found</td></tr>');
            return;
        }

        $.each(products, function(index, product) {
            var row = $('<tr></tr>');
            
            // Create cells
            row.append('<td>' + (product.id || '') + '</td>');
            row.append('<td>' + (product.code || '') + '</td>');
            row.append('<td>' + (product.name || '') + '</td>');
            row.append('<td>' + (product.count || '0') + ' ' + (product.unit || '') + '</td>');
            row.append('<td>' + formatPrice(product.purchasing_price) + '</td>');
            row.append('<td>' + formatPrice(product.selling_price) + '</td>');
            
            tbody.append(row);
        });
    }

    // Update pagination function
    function updatePagination(pagination) {
        if (!pagination) {
            $('.pohoda-pagination-btn').addClass('disabled');
            $('#pohoda-pagination-status').text('No pagination information');
            return;
        }

        // Update page information
        var startRecord = pagination.from || 0;
        var endRecord = pagination.to || 0;
        var totalRecords = pagination.total || 0;
        
        $('#pohoda-pagination-status').text('Showing ' + startRecord + ' to ' + endRecord + ' of ' + totalRecords + ' records');

        // Update pagination buttons
        $('#pohoda-prev-page').toggleClass('disabled', currentPage <= 1);
        
        // If we have a full page of results or know there are more, enable next button
        var hasMore = pagination.has_more || (endRecord < totalRecords);
        $('#pohoda-next-page').toggleClass('disabled', !hasMore);
    }

    // Format price function
    function formatPrice(price) {
        if (price === undefined || price === null) return '0.00';
        return parseFloat(price).toFixed(2);
    }
    
    // Escape HTML to display raw XML safely
    function escapeHTML(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Test Connection
    $('#test-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#connection-result');

        $button.prop('disabled', true);
        $result.html('Testing connection...');

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'test_pohoda_connection',
                nonce: pohodaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html(response.data);
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Failed to test connection. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Load Products
    $('#load-products').on('click', function() {
        loadProducts(1);
    });

    // Pagination handlers
    $('#prev-page').on('click', function() {
        const currentPage = parseInt($('#page-info').data('current-page'));
        if (currentPage > 1) {
            loadProducts(currentPage - 1);
        }
    });

    $('#next-page').on('click', function() {
        const currentPage = parseInt($('#page-info').data('current-page'));
        const lastPage = parseInt($('#page-info').data('last-page'));
        if (currentPage < lastPage) {
            loadProducts(currentPage + 1);
        }
    });

    function loadProducts(page = 1) {
        const search = $('#product-search').val();
        const type = $('#product-type').val();
        const storage = $('#product-storage').val();
        const supplier = $('#product-supplier').val();
        const perPage = $('#products-per-page').val();
        const idFrom = (page === 1) ? 0 : $('#page-info').data('last-id') || 0;
        const checkWoocommerce = $('#check-woocommerce').is(':checked') ? 1 : 0;

        const $button = $('#load-products');
        const $result = $('#products-result');
        const $raw = $('#products-raw');
        const $pagination = $('.pohoda-pagination');

        $button.prop('disabled', true);
        $result.html('Loading products...');
        $raw.hide();
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_pohoda_products',
                nonce: pohodaAdmin.nonce,
                search: search,
                type: type,
                storage: storage,
                supplier: supplier,
                per_page: perPage,
                page: page,
                id_from: idFrom,
                check_woocommerce: checkWoocommerce
            },
            success: function(response) {
                // Always show raw response if available
                if (response.data && response.data.raw) {
                    $raw.find('pre').text(response.data.raw);
                    $raw.show();
                }
                
                if (response.success) {
                    // Even if the Ajax request was successful, the API might have returned an error
                    if (response.data.success) {
                        var products = response.data.data;
                        var pagination = response.data.pagination;
                        var message = response.data.message || '';
                        
                        // Store pagination info for next/previous page navigation
                        $('#page-info').text('Page ' + pagination.current_page + ' of ' + pagination.last_page)
                                       .data('current-page', pagination.current_page)
                                       .data('last-page', pagination.last_page)
                                       .data('last-id', pagination.last_id);
                        
                        // Enable/disable pagination buttons
                        $('#prev-page').prop('disabled', pagination.current_page <= 1);
                        $('#next-page').prop('disabled', pagination.current_page >= pagination.last_page || products.length < perPage);
                        
                        if (products.length === 0) {
                            var msg = message ? message : 'No products found matching your criteria.';
                            $result.html('<div class="notice notice-warning"><p>' + msg + '</p></div>');
                            $pagination.hide();
                        } else {
                            var table = '<table class="wp-list-table widefat fixed striped">';
                            table += '<thead><tr>';
                            table += '<th>ID</th>';
                            table += '<th>Code</th>';
                            table += '<th>Name</th>';
                            table += '<th>Stock</th>';
                            table += '<th>Price</th>';
                            table += '<th>WooCommerce</th>';
                            table += '</tr></thead><tbody>';
                            
                            products.forEach(function(product) {
                                var rowClass = '';
                                var wooDetails = '';
                                
                                if (checkWoocommerce) {
                                    if (product.woocommerce_exists) {
                                        if (product.comparison_status === 'match') {
                                            rowClass = 'pohoda-row-match';
                                            wooDetails = '<span class="woo-exists">Synced</span> ' +
                                                '<a href="' + product.woocommerce_url + '" target="_blank" class="button button-small">Edit</a>';
                                        } else if (product.comparison_status === 'mismatch') {
                                            rowClass = 'pohoda-row-mismatch';
                                            
                                            var mismatchDetails = '';
                                            if (product.mismatches) {
                                                product.mismatches.forEach(function(mismatch) {
                                                    if (mismatch.field === 'stock') {
                                                        mismatchDetails += '<div class="mismatch-item">Stock: ' + 
                                                            'POHODA: ' + mismatch.pohoda + ' vs WC: ' + mismatch.woocommerce + '</div>';
                                                    }
                                                    if (mismatch.field === 'price') {
                                                        mismatchDetails += '<div class="mismatch-item">Price: ' + 
                                                            'POHODA: ' + mismatch.pohoda + ' vs WC: ' + mismatch.woocommerce + '</div>';
                                                    }
                                                });
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
                                } else {
                                    wooDetails = 'Check disabled';
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
                                table += '</tr>';
                            });
                            
                            table += '</tbody></table>';
                            $result.html(table);
                            $pagination.show();
                            
                            // Show total count
                            $result.prepend('<div class="notice notice-info"><p>Products found: ' + pagination.total + '</p></div>');
                            
                            // Add some CSS for the WooCommerce status
                            $('<style>')
                                .text('.pohoda-row-match { background-color: #d4edda; } ' +
                                      '.pohoda-row-mismatch { background-color: #fff3cd; } ' +
                                      '.pohoda-row-missing { background-color: #f8d7da; } ' +
                                      '.pohoda-row-unknown { background-color: #d6d8d9; } ' +
                                      '.woo-exists { color: #1e7e34; font-weight: bold; margin-right: 10px; } ' +
                                      '.woo-missing { color: #dc3545; font-weight: bold; margin-right: 10px; } ' +
                                      '.woo-mismatch { color: #856404; font-weight: bold; margin-right: 10px; } ' +
                                      '.woo-unknown { color: #6c757d; font-weight: bold; margin-right: 10px; } ' +
                                      '.wc-data { color: #6c757d; font-size: 0.9em; } ' +
                                      '.mismatch-details { margin-top: 5px; font-size: 0.9em; color: #856404; } ' +
                                      '.mismatch-item { margin-bottom: 2px; }')
                                .appendTo('head');
                                
                            // Add alternating row colors that preserve the status colors
                            $('.wp-list-table tr:nth-child(even):not(.pohoda-row-match):not(.pohoda-row-mismatch):not(.pohoda-row-missing):not(.pohoda-row-unknown)').css('background-color', '#f9f9f9');
                            $('.wp-list-table tr:nth-child(odd):not(.pohoda-row-match):not(.pohoda-row-mismatch):not(.pohoda-row-missing):not(.pohoda-row-unknown)').css('background-color', '#ffffff');
                        }
                    } else {
                        // The API returned an error
                        $result.html('<div class="notice notice-error"><p>Error: ' + response.data.data + '</p></div>');
                        $pagination.hide();
                    }
                } else {
                    // Check if the error response has structured data
                    var errorMessage = 'Unknown error occurred';
                    
                    if (typeof response.data === 'string') {
                        errorMessage = response.data;
                    } else if (response.data && response.data.data) {
                        errorMessage = response.data.data;
                    }
                    
                    $result.html('<div class="notice notice-error"><p>Error: ' + errorMessage + '</p></div>');
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

    // Load Orders
    $('#load-orders').on('click', function() {
        var $button = $(this);
        var $result = $('#orders-result');
        var $raw = $('#orders-raw');

        $button.prop('disabled', true);
        $result.html('Loading orders...');
        $raw.hide();

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_pohoda_orders'
            },
            success: function(response) {
                if (response.success) {
                    var orders = response.data.orders;
                    var table = '<table class="wp-list-table widefat fixed striped">';
                    table += '<thead><tr>';
                    table += '<th>ID</th>';
                    table += '<th>Number</th>';
                    table += '<th>Date</th>';
                    table += '<th>Partner</th>';
                    table += '<th>Total</th>';
                    table += '<th>Status</th>';
                    table += '</tr></thead><tbody>';
                    
                    orders.forEach(function(order) {
                        table += '<tr>';
                        table += '<td>' + order.id + '</td>';
                        table += '<td>' + order.number + '</td>';
                        table += '<td>' + order.date + '</td>';
                        table += '<td>' + order.partner + '</td>';
                        table += '<td>' + order.total + '</td>';
                        table += '<td>' + order.status + '</td>';
                        table += '</tr>';
                    });
                    
                    table += '</tbody></table>';
                    $result.html(table);
                    $raw.find('pre').text(JSON.stringify(response.data.raw, null, 2));
                    $raw.show();
                } else {
                    $result.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Failed to load orders. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Send XML
    $('#send-xml').on('click', function() {
        var $button = $(this);
        var $response = $('#xml-response pre');

        $button.prop('disabled', true);
        $response.text('Sending request...');

        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'send_pohoda_xml',
                xml: encodeURIComponent($('#xml-request').val())
            },
            success: function(response) {
                if (response.success) {
                    $response.text(response.data);
                } else {
                    $response.text('Error: ' + response.data);
                }
            },
            error: function() {
                $response.text('Failed to send request. Please try again.');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
}); 