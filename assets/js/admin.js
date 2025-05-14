jQuery(document).ready(function($) {
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
                action: 'test_pohoda_connection'
            },
            success: function(response) {
                if (response.success) {
                    $result.html(response.data);
                } else {
                    $result.html('Error: ' + response.data);
                }
            },
            error: function() {
                $result.html('Error: Failed to connect to server');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Load Products
    var currentPage = 1;
    var itemsPerPage = 10;
    var allProducts = [];
    var filteredProducts = [];

    function updateProductsTable() {
        var start = (currentPage - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        var pageProducts = filteredProducts.slice(start, end);
        var table = createProductsTable(pageProducts);
        $('#products-result').html(table);
        
        // Update pagination
        var totalPages = Math.ceil(filteredProducts.length / itemsPerPage);
        $('#page-info').text('Page ' + currentPage + ' of ' + totalPages);
        $('.pohoda-pagination').toggle(totalPages > 1);
        $('#prev-page').prop('disabled', currentPage === 1);
        $('#next-page').prop('disabled', currentPage === totalPages);
    }

    function filterProducts() {
        var searchTerm = $('#product-search').val().toLowerCase();
        var typeFilter = $('#product-type').val();
        var storageFilter = $('#product-storage').val();
        var supplierFilter = $('#product-supplier').val();

        filteredProducts = allProducts.filter(function(product) {
            var matchesSearch = !searchTerm || 
                product.name.toLowerCase().includes(searchTerm) || 
                product.code.toLowerCase().includes(searchTerm);
            
            var matchesType = !typeFilter || product.stockType === typeFilter;
            var matchesStorage = !storageFilter || product.storage === storageFilter;
            var matchesSupplier = !supplierFilter || product.supplier === supplierFilter;

            return matchesSearch && matchesType && matchesStorage && matchesSupplier;
        });

        currentPage = 1;
        updateProductsTable();
    }

    $('#product-search, #product-type, #product-storage, #product-supplier').on('change keyup', function() {
        filterProducts();
    });

    $('#products-per-page').on('change', function() {
        itemsPerPage = parseInt($(this).val());
        currentPage = 1;
        updateProductsTable();
    });

    $('#prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            updateProductsTable();
        }
    });

    $('#next-page').on('click', function() {
        var totalPages = Math.ceil(filteredProducts.length / itemsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updateProductsTable();
        }
    });

    $('#load-products').on('click', function() {
        var $button = $(this);
        var $result = $('#products-result');
        var $raw = $('#products-raw');
        
        $button.prop('disabled', true);
        $result.html('Loading products...');
        $raw.hide();
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'load_pohoda_products'
            },
            success: function(response) {
                if (response.success) {
                    var products = response.data.data;
                    
                    if (products && products.length > 0) {
                        allProducts = products;
                        var storages = new Set();
                        var suppliers = new Set();
                        
                        products.forEach(function(product) {
                            if (product.storage) storages.add(product.storage);
                            if (product.supplier) suppliers.add(product.supplier);
                        });
                        
                        var $storageSelect = $('#product-storage');
                        var $supplierSelect = $('#product-supplier');
                        
                        $storageSelect.empty().append($('<option>', {
                            value: '',
                            text: 'All Storages'
                        }));
                        
                        $supplierSelect.empty().append($('<option>', {
                            value: '',
                            text: 'All Suppliers'
                        }));
                        
                        storages.forEach(function(storage) {
                            $storageSelect.append($('<option>', {
                                value: storage,
                                text: storage
                            }));
                        });
                        
                        suppliers.forEach(function(supplier) {
                            $supplierSelect.append($('<option>', {
                                value: supplier,
                                text: supplier
                            }));
                        });

                        filteredProducts = allProducts;
                        updateProductsTable();
                        $raw.find('pre').text(JSON.stringify(response.data, null, 2));
                        $raw.show();
                    } else {
                        $result.html('No products found');
                    }
                } else {
                    $result.html('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = 'Error: ';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage += xhr.responseJSON.data;
                } else if (error) {
                    errorMessage += error;
                } else {
                    errorMessage += 'Failed to connect to server';
                }
                $result.html(errorMessage);
                console.error('Pohoda API Error:', {xhr: xhr, status: status, error: error});
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    function getElementValue(parent, path) {
        var parts = path.split('/');
        var element = parent;
        for (var i = 0; i < parts.length; i++) {
            element = element.getElementsByTagName(parts[i])[0];
            if (!element) return '';
        }
        return element ? element.textContent : '';
    }

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
                    var table = createTable(response.data.orders, ['ID', 'Number', 'Date', 'Partner', 'Total', 'Status']);
                    $result.html(table);
                    $raw.find('pre').text(response.data.raw);
                    $raw.show();
                } else {
                    $result.html('Error: ' + response.data);
                }
            },
            error: function() {
                $result.html('Error: Failed to connect to server');
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
        var xml = $('#xml-request').val();
        
        $button.prop('disabled', true);
        $response.text('Sending request...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'send_pohoda_xml',
                xml: encodeURIComponent(xml)
            },
            success: function(response) {
                if (response.success) {
                    $response.html(formatXml(response.data));
                } else {
                    $response.html('Error: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                $response.html('Error: ' + error + '<br>Status: ' + status + '<br>Response: ' + xhr.responseText);
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Pohyby Form
    $('#pohyby-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $response = $('#pohyby-response pre');
        var formData = {};
        
        $form.find('input, select').each(function() {
            if ($(this).val()) {
                formData[$(this).attr('name')] = $(this).val();
            }
        });

        var xml = '<?xml version="1.0" encoding="Windows-1250"?>' +
            '<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" ' +
            'xmlns:mov="http://www.stormware.cz/schema/version_2/movement.xsd" ' +
            'xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" ' +
            'id="Za001" ico="' + pohodaAdmin.ico + '" application="StwTest" version="2.0" note="Pohyb">' +
            '<dat:dataPackItem id="a55" version="2.0">' +
            '<mov:movement version="2.0">' +
            '<mov:movementHeader>' +
            '<typ:agenda>' + formData.agenda + '</typ:agenda>' +
            (formData.stockType ? '<typ:stockType>' + formData.stockType + '</typ:stockType>' : '') +
            '<typ:stockItem>' + formData.stockItem + '</typ:stockItem>' +
            (formData.unit ? '<typ:unit>' + formData.unit + '</typ:unit>' : '') +
            '<typ:date>' + formData.date + '</typ:date>' +
            '<mov:movementType>' + formData.movementType + '</mov:movementType>' +
            '<typ:quantity>' + formData.quantity + '</typ:quantity>' +
            (formData.unitPrice ? '<typ:unitPrice>' + formData.unitPrice + '</typ:unitPrice>' : '') +
            (formData.price ? '<typ:price>' + formData.price + '</typ:price>' : '') +
            '<typ:number>' + formData.number + '</typ:number>' +
            '<typ:regNumber>' + formData.regNumber + '</typ:regNumber>' +
            '</mov:movementHeader>' +
            '</mov:movement>' +
            '</dat:dataPackItem>' +
            '</dat:dataPack>';

        $response.text('Sending request...');
        
        $.ajax({
            url: pohodaAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'send_pohoda_xml',
                xml: encodeURIComponent(xml)
            },
            success: function(response) {
                if (response.success) {
                    $response.html(formatXml(response.data));
                } else {
                    $response.html('Error: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                $response.html('Error: ' + error + '<br>Status: ' + status + '<br>Response: ' + xhr.responseText);
            }
        });
    });

    // Helper function to create table
    function createTable(data, headers) {
        if (!data || !data.length) {
            return 'No data available';
        }

        var table = '<table class="wp-list-table widefat fixed striped">';
        
        // Headers
        table += '<thead><tr>';
        headers.forEach(function(header) {
            table += '<th>' + header + '</th>';
        });
        table += '</tr></thead>';
        
        // Body
        table += '<tbody>';
        data.forEach(function(row) {
            table += '<tr>';
            headers.forEach(function(header) {
                var key = header.toLowerCase();
                table += '<td>' + (row[key] || '') + '</td>';
            });
            table += '</tr>';
        });
        table += '</tbody></table>';
        
        return table;
    }

    // Helper function to format XML
    function formatXml(xml) {
        var formatted = '';
        var reg = /(>)(<)(\/*)/g;
        xml = xml.replace(reg, '$1\r\n$2$3');
        var pad = 0;
        xml.split('\r\n').forEach(function(node, index) {
            var indent = 0;
            if (node.match(/.+<\/\w[^>]*>$/)) {
                indent = 0;
            } else if (node.match(/^<\/\w/)) {
                if (pad != 0) {
                    pad -= 1;
                }
            } else if (node.match(/^<\w([^>]*[^\/])?>.*$/)) {
                indent = 1;
            } else {
                indent = 0;
            }
            var spacing = new Array(pad + 1).join('  ');
            formatted += spacing + node + '\r\n';
            pad += indent;
        });
        return formatted.replace(/&/g, '&amp;')
                       .replace(/</g, '&lt;')
                       .replace(/>/g, '&gt;')
                       .replace(/"/g, '&quot;')
                       .replace(/'/g, '&#039;');
    }

    function createProductsTable(products) {
        if (!products || products.length === 0) {
            return '<p>No products found</p>';
        }

        var table = '<table class="wp-list-table widefat fixed striped">' +
            '<thead>' +
            '<tr>' +
            '<th>Code</th>' +
            '<th>Name</th>' +
            '<th>Type</th>' +
            '<th>EAN</th>' +
            '<th>Unit</th>' +
            '<th>Count</th>' +
            '<th>Purchase Price</th>' +
            '<th>Selling Price</th>' +
            '<th>Storage</th>' +
            '<th>Supplier</th>' +
            '<th>Producer</th>' +
            '<th>Guarantee</th>' +
            '<th>Availability</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';

        products.forEach(function(product) {
            table += '<tr>' +
                '<td>' + (product.code || '') + '</td>' +
                '<td>' + (product.name || '') + '</td>' +
                '<td>' + (product.stockType || '') + '</td>' +
                '<td>' + (product.EAN || '') + '</td>' +
                '<td>' + (product.unit || '') + '</td>' +
                '<td>' + (product.count || '') + '</td>' +
                '<td>' + (product.purchasingPrice || '') + '</td>' +
                '<td>' + (product.sellingPrice || '') + '</td>' +
                '<td>' + (product.storage || '') + '</td>' +
                '<td>' + (product.supplier || '') + '</td>' +
                '<td>' + (product.producer || '') + '</td>' +
                '<td>' + (product.guarantee || '') + '</td>' +
                '<td>' + (product.availability || '') + '</td>' +
                '</tr>';
        });

        table += '</tbody></table>';
        return table;
    }
}); 