{if $oblio["`$oblioDocType`_series"] && $oblio["`$oblioDocType`_number"]}
    <a id="oblio_{$oblioDocType}_button_view" class="btn btn-default btn-outline-secondary" href="{$link->getAdminLink('AdminOblioInvoice')|escape:'html':'UTF-8'}&amp;oblio_doc_type={$oblioDocType}&amp;id_order={$id_order}&amp;redirect=1" target="_blank">
      <i class="icon-file"></i>
      <span>Vezi {$oblioDocName} {$oblio["`$oblioDocType`_series"]} {$oblio["`$oblioDocType`_number"]}</span>
    </a>
{else}
    <a id="oblio_{$oblioDocType}_button" class="btn btn-default btn-outline-secondary oblio-generate-{$oblioDocType}" href="{$link->getAdminLink('AdminOblioInvoice')|escape:'html':'UTF-8'}&amp;oblio_doc_type={$oblioDocType}&amp;id_order={$id_order}&amp;useStock={if $oblio.has_stock_active && $oblioDocType!='proforma'}1{else}0{/if}" target="_blank">
      <i class="icon-file"></i>
      <span>Emite {$oblioDocName} cu Oblio</span>
    </a>
    {if $oblio.has_stock_active && $oblioDocType!='proforma'}
    <a id="oblio_{$oblioDocType}_button_no_stock" class="btn btn-default btn-outline-secondary oblio-generate-{$oblioDocType}" href="{$link->getAdminLink('AdminOblioInvoice')|escape:'html':'UTF-8'}&amp;oblio_doc_type={$oblioDocType}&amp;id_order={$id_order}" target="_blank">
      <i class="icon-file"></i>
      <span>Emite {$oblioDocName} cu Oblio fara descarcare</span>
    </a>
    {/if}
{/if}
<a class="btn btn-danger oblio-delete-{$oblioDocType}-btn hidden" href="{$link->getAdminLink('AdminOblioInvoice')|escape:'html':'UTF-8'}&amp;oblio_doc_type={$oblioDocType}&amp;id_order={$id_order}&amp;delete-doc={$oblioDocType}">
    <i class="icon-minus-sign"></i> Sterge {$oblioDocName}
</a>

{literal}
<style type="text/css">
body.page-is-loading * {cursor:wait!important;}
.oblio-form-horizontal {margin:15px 0 0;}
.hidden {display:none;}
</style>
<script type="text/javascript">
"use strict";
(function($) {
    $(document).ready(function() {
        var buttons = $('.oblio-generate-{/literal}{$oblioDocType}{literal}'),
            message = $('.oblio-response'),
            deleteButton = $('.oblio-delete-{/literal}{$oblioDocType}{literal}-btn');
        buttons.click(function(e) {
            var self = $(this), postData = {};
            if (self.hasClass('disabled')) {
                return false;
            }
            if (!self.hasClass('oblio-generate-{/literal}{$oblioDocType}{literal}')) {
                return true;
            }
            
            e.preventDefault();
            self.addClass('disabled');
            $(document.body).addClass('page-is-loading');
            
            $('.oblio-option').each(function() {
                var th = $(this);
                postData[th.attr('name')] = th.val();
            });
            
            jQuery.ajax({
                method: 'POST',
                dataType: 'json',
                url: self.attr('href') + '&ajax=true',
                data: postData,
                success: function(response) {
                    var alert = '';
                    self.removeClass('disabled');
                    $(document.body).removeClass('page-is-loading');
                    
                    if ('link' in response) {
                        buttons
                            .not(self)
                            .hide()
                        self
                            .attr('href', response.link)
                            .removeClass('oblio-generate-{/literal}{$oblioDocType}{literal}')
                            .text(`Vezi {/literal}{$oblioDocType}{literal} ${response.seriesName} ${response.number}`);
                        alert = '<div class="alert alert-success alert-dismissible" role="alert">\
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
                          {/literal}{$oblioDocName|ucfirst}{literal} a fost emisa\
                        </div>';
                        
                        $('.oblio-options-btn, .oblio-form-horizontal').addClass('hidden');
                        deleteButton.removeClass('hidden');
                    } else if ('error' in response) {
                        alert = '<div class="alert alert-danger alert-dismissible" role="alert">\
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
                          ' + response.error + '\
                        </div>';
                    }
                    message.html(alert);
                }
            });
        });
        
        deleteButton.click(function(e) {
            var self = $(this);
            if (self.hasClass('disabled')) {
                return false;
            }
            e.preventDefault();
            self.addClass('disabled');
            jQuery.ajax({
                dataType: 'json',
                url: self.attr('href') + '&ajax=true',
                data: {},
                success: function(response) {
                    var alert = '';
                    if (response.type == 'success') {
                        location.reload();
                    } else {
                        alert = '<div class="alert alert-danger alert-dismissible" role="alert">\
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
                          ' + response.message + '\
                        </div>';
                        message.html(alert);
                        self.removeClass('disabled');
                    }
                }
            });
        });
        
        {/literal}
        {if $oblio["`$oblioDocType`_is_last"]}
        deleteButton.removeClass('hidden');
        {/if}
        {literal}
    });
})(jQuery);
</script>
{/literal}