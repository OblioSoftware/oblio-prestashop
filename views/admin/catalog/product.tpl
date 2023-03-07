<h2>Tip produs Oblio</h2>
<select name="oblio[product_type]" data-toggle="select2" data-minimumresultsforsearch="7" class="custom-select select2-hidden-accessible" tabindex="-1" aria-hidden="true">
{foreach from=$oblio_product_types item=oblio_product_type}
    <option value="{$oblio_product_type.value}"{if $oblio_product_type.selected} selected{/if}>{$oblio_product_type.name}</option>
{/foreach}
</select>

<script type="text/javascript">
{literal}
(function oblioMessage() {
    var message = {/literal}"{$oblio_message}"{literal};
    $('<div class="alert alert-info oblio-reference-message"><p class="alert-text">' + message + '</p></div>')
        .insertAfter($('.product-header'));
})();
{/literal}
</script>
<style type="text/css">
{literal}
.oblio-reference-message {width:100%;margin-right:15px;margin-left:15px;}
{/literal}
</style>