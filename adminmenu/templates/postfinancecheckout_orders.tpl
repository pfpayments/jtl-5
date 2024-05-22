<input type="hidden" name="form-url" id="form-url" value="{$postUrl}">
{if $orders|@count > 0 && $orders}
    {include file='tpl_inc/pagination.tpl' pagination=$pagination cParam_arr=['kPlugin'=>$pluginId] cAnchor=$hash}
    <form method="post" name="postfinancecheckout-orders" action="{$postUrl}">
        {$jtl_token}
        <div class="table-responsive">
            <table class="table table-striped orders-table">
                <thead>
                <tr>
                    <th>{$translations.jtl_postfinancecheckout_order_number}</th>
                    <th>{$translations.jtl_postfinancecheckout_customer}</th>
                    <th>{$translations.jtl_postfinancecheckout_payment_method}</th>
                    <th>{$translations.jtl_postfinancecheckout_order_status}</th>
                    <th>{$translations.jtl_postfinancecheckout_amount}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                {foreach $orders as $order}
                    <tr>
                        <td class="text-left">
                            <div>{$order['orderDetails']->cBestellNr}</div>
                            <small class="text-muted"><i class="far fa-calendar-alt"
                                                         aria-hidden="true"></i> {$order['orderDetails']->dErstellt}</small>
                        </td>
                        <td>
                            <div>
                                {if isset($order['orderDetails']->oKunde->cVorname) || isset($order['orderDetails']->oKunde->cNachname) || isset($order['orderDetails']->oKunde->cFirma)}
                                    {$order['orderDetails']->oKunde->cVorname} {$order['orderDetails']->oKunde->cNachname}
                                    {if isset($order['orderDetails']->oKunde->cFirma) && $order['orderDetails']->oKunde->cFirma|strlen > 0}
                                        ({$order['orderDetails']->oKunde->cFirma})
                                    {/if}
                                {else}
                                    {__('noAccount')}
                                {/if}
                            </div>
                            <small class="text-muted">
                                <i class="fa fa-user" aria-hidden="true"></i>
                                {$order['orderDetails']->oKunde->cMail}
                            </small>
                        </td>
                        <td class="text-left">{$order['orderDetails']->cZahlungsartName}</td>
                        <td class="text-left">
                            {$paymentStatus[$order['orderDetails']->cStatus]}
                        </td>
                        <td class="text-left">
                            {$order['orderDetails']->WarensummeLocalized[0]}
                        </td>
                        <td onclick="showDetails({
                            'total_amount':'{$order['total_amount']}',
                            'order_id':'{$order['orderDetails']->kBestellung}',
                            'order_no':'{$order['orderDetails']->cBestellNr}',
                            'transaction_id': '{$order['postfinancecheckout_transaction_id']}',
                            'transaction_state': '{$order['postfinancecheckout_state']}',
                            'action': 'order_details'
                            })">
                            <a href="#order-datails">
                                <i class="fa fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </form>
{else}
    <div class="alert alert-info">
        <i class="fa fa-info-circle"></i>
        {$translations.jtl_postfinancecheckout_there_are_no_orders}
    </div>
{/if}
<div id="transaction-info"></div>


<script>
    function showDetails(requestParams) {

        var requestUrl = jQuery('input[id=form-url]').val();
        $.ajax({
            url: requestUrl,
            type: 'post',
            dataType: 'html',
            data: requestParams,
            global: false,
            async: false,
            success: function (result) {
                $('.orders-table').hide();
                $('#transaction-info').html(result);
                $('#transaction-info').show();
                $('.pagination-toolbar').hide();
            }
        });
    }
</script>
