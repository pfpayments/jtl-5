<tbody id="ordersTableBody" style="display: contents">
{foreach $orders as $order}
    <tr>
        <td class="text-left">
            <div>{$order['orderDetails']->cBestellNr}</div>
            <small class="text-muted">
                <i class="far fa-calendar-alt" aria-hidden="true"></i> {$order['orderDetails']->dErstellt}
            </small>
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
                <i class="fa fa-user" aria-hidden="true"></i> {$order['orderDetails']->oKunde->cMail}
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
