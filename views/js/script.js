"use strict";
(function($) {
    $(document).ready(function() {
        var oblio_cui = $('#oblio_company_cui'),
            oblio_series_name = $('#oblio_company_series_name'),
            oblio_series_name_proforma = $('#oblio_company_series_name_proforma'),
            oblio_workstation = $('#oblio_company_workstation'),
            oblio_management = $('#oblio_company_management'),
            useStock = parseInt(oblio_cui.find('option:selected').data('use-stock')) === 1;
        
        showManagement(useStock);
        
        oblio_cui.change(function() {
            var self = $(this),
                data = {
                    type:'series_name',
                    cui:oblio_cui.val()
                },
                useStock = parseInt(oblio_cui.find('option:selected').data('use-stock')) === 1;

            populateOptions(data, oblio_series_name, function(data) {
                var invoiceSeries = [], proformaSeries = [];
                for (var index in data) {
                    var item = data[index];
                    switch (item.type) {
                        case 'Factura': invoiceSeries.push(item); break;
                        case 'Proforma': proformaSeries.push(item); break;
                    }
                }
                populateOptionsRender(oblio_series_name, invoiceSeries);
                populateOptionsRender(oblio_series_name_proforma, proformaSeries);
            });
            
            if (useStock) {
                data.type = 'workstation';
                populateOptions(data, oblio_workstation);
                populateOptionsRender(oblio_management, []);
            }
            showManagement(useStock);
        });
        oblio_workstation.change(function() {
            var self = $(this),
                data = {
                    type:'management',
                    name:self.val(),
                    cui:oblio_cui.val()
                };
            populateOptions(data, oblio_management);
        });
        
        function showManagement(useStock) {
            oblio_workstation.parent().parent().toggleClass('hidden', !useStock);
            oblio_management.parent().parent().toggleClass('hidden', !useStock);
        }
        
        function populateOptions(data, element, fn) {
            data.ajax = true;
            jQuery.ajax({
                type: 'post',
                dataType: 'json',
                url: ajaxurl,
                data: data,
                success: function(response) {
                    populateOptionsRender(element, response, fn);
                }
            });
        }
        
        function populateOptionsRender(element, data, fn) {
            var options = '<option value="">Selecteaza</option>';
            for (var index in data) {
                var value = data[index];
                options += '<option value="' + value.name + '">' + value.name + '</option>';
            }
            element
                .html(options)
                .trigger('chosen:updated');
            if (typeof fn === 'function') {
                fn(data);
            }
        }
    });
})(jQuery);