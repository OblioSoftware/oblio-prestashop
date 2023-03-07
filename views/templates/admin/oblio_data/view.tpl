
<div id="oblio_message"></div>

<div class="panel">
    <div class="panel-heading">Sincronizare manuala</div>
    <p>Sincronizarea manuala iti permite sa sincronizezi stocul imediat.</p>
    <p>Daca folosesti sincronizarea automata folosind Cron Jobs, stocul se actualizeaza automat la fiecare ora.</p>
    <a id="oblio_update_stock" class="btn btn-default" href="">
      <i class="icon-file"></i>
      {$btnName}
    </a>
</div>

<div class="panel">
    <div class="panel-heading">Sincronizare folosind Cron Jobs</div>
    <div class="panel-body">
        <p>Pentru a sincroniza stocul in fiecare ora adaugati comanda urmatoare in Crontab:</p>
        <pre>{$cron_minute} 	* 	* 	* 	*	php {$smarty.const._PS_ROOT_DIR_}/modules/oblio/cron.php {$secret}</pre>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">Generare cuduri de referinta automate</div>
    <a id="oblio_generate_reference" class="btn btn-default" href="">
      <i class="icon-file"></i>
      Generare coduri de referinta
    </a>
</div>

<div class="panel">
    <div class="panel-heading">Export stoc initial csv</div>
    <a id="oblio_export" class="btn btn-default" href="{$link->getAdminLink('AdminOblioData')|escape:'UTF-8'}&amp;action=ajax&amp;type=export">
      <i class="icon-file"></i>
      Export
    </a>
</div>

<script type="text/javascript">
"use strict";
var ajaxLink = "{$link->getAdminLink('AdminOblioData')|escape:'UTF-8'}&action=ajax";
{literal}
$(document).ready(function() {
    $('#oblio_update_stock').click(function(e) {
        var self = $(this);
        self.find('i').attr('class', 'icon-circle-o-notch icon-spin');
        e.preventDefault();
        $.ajax({
            url: ajaxLink,
            dataType: 'json',
            success: function(data) {
                if (data[1]) {
                    addMessage(data[1], 'danger');
                } else {
                    addMessage(`Au fost importate ${data[0]} produse`, 'success');
                }
                self.find('i').attr('class', 'icon-file');
            }
        });
    });
    
    $('#oblio_generate_reference').click(function(e) {
        var self = $(this);
        self.find('i').attr('class', 'icon-circle-o-notch icon-spin');
        e.preventDefault();
        $.ajax({
            url: ajaxLink + '&type=generate_reference',
            dataType: 'json',
            success: function(data) {
                if (data[1]) {
                    addMessage(data[1], 'danger');
                } else {
                    addMessage('Au fost genarate codurile de referinta lipsa', 'success');
                }
                self.find('i').attr('class', 'icon-file');
            }
        });
    });
    
    function addMessage(message, type) {
        var response = $('#oblio_message'), html = '';
        html = '<div class="alert alert-' + type + ' alert-dismissible" role="alert">\
          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>\
          ' + message + '\
        </div>';
        response.html(html);
    }
});
{/literal}
</script>