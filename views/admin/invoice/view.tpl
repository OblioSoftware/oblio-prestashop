<div class="panel{if $_ps_new_style} card mt-2{/if}">
    <div class="panel-heading{if $_ps_new_style} card-header{/if}">Oblio</div>
    <div class="{if $_ps_new_style}card-body{/if}">
        <div class="oblio-response"></div>
        {include file='./buttons.tpl' oblioDocType='invoice' oblioDocName='factura'}
        {include file='./buttons.tpl' oblioDocType='proforma' oblioDocName='proforma'}
        {include file='./options.tpl'}
    </div>
</div>