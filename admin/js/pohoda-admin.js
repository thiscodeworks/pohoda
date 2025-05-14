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
}); 