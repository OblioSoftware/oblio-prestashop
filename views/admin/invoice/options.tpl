{if !$oblio.invoice_series && !$oblio.invoice_number}
<a class="btn btn-primary oblio-options-btn" role="button" data-toggle="collapse" href="#oblio_options" aria-expanded="false" aria-controls="oblio_options">
  Optiuni factura
</a>
<div class="clearfix form-horizontal oblio-form-horizontal">
  <div class="collapse" id="oblio_options">
    <div class="form-wrapper">
    {foreach from=$oblio.options item=oblio_option}
      <div class="form-group">
        <label class="control-label col-lg-3">{$oblio_option.label}</label>
        <div class="col-lg-9">
        {if $oblio_option.type == 'text'}
          <input type="text" name="{$oblio_option.name}" id="{$oblio_option.name}" value="{$oblio_option.value}" class="oblio-option form-control" size="20">
        {elseif $oblio_option.type == 'textarea'}
          <textarea name="{$oblio_option.name}" id="{$oblio_option.name}" class="textarea-autosize form-control oblio-option" style="overflow: hidden; overflow-wrap: break-word; resize: none; height: 65px;">{$oblio_option.value}</textarea>
        {elseif $oblio_option.type == 'select'}
        <select name="{$oblio_option.name}" id="{$oblio_option.name}" class="textarea-autosize form-control oblio-option{if isset($oblio_option.class)} {$oblio_option.class}{/if}">
        {foreach $oblio_option.options.query as $option}
          <option value="{$option[$oblio_option.options.id]}"{if $option[$oblio_option.options.id] == $oblio_option.value} selected="selected"{/if}>{$option[$oblio_option.options.name]}</option>
        {/foreach}
        </select>
        {/if}
        </div>
      </div>
    {/foreach}
    </div>
  </div>
</div>
{/if}