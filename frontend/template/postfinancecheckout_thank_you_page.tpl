{block name='checkout-order-completed'}
    {block name='checkout-order-completed-content'}
        {block name='checkout-order-completed-heading'}
            <h2 class="container">{lang key='orderCompletedPost' section='checkout'}</h2>
        {/block}
        {block name='checkout-order-completed-main'}
            {container fluid=$Link->getIsFluid()}
                <div class="order-completed">
                    {block name='checkout-order-completed-order-completed'}
                        {block name='checkout-order-completed-include-inc-order-completed'}
                            {include file='checkout/inc_order_completed.tpl'}
                        {/block}
                    {/block}
                    {block name='checkout-order-completed-continue-shopping'}
                        {row}
                        {col md=4 lg=3 xl=2}
                        {button block=true type="link" href={$ShopURL} variant="primary"}
                        {lang key='continueShopping' section='checkout'}
                        {/button}
                        {/col}
                        {/row}
                    {/block}
                </div>
            {/container}
        {/block}
    {/block}
{/block}


<script>
    $('head').append('<link rel="stylesheet" type="text/css" href="{$mainCssUrl}">');
</script>
