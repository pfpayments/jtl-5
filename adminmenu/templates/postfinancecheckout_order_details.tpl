<div>
    <div class="postfinancecheckout-loader text-center">
        <img src="{$adminUrl}/assets/spinner.gif"/>
    </div>

    <div class="card">
        <div class="card-body">
            <p>{$translations.jtl_postfinancecheckout_order_number}: {$orderNo}</p>
            <p>{$translations.jtl_postfinancecheckout_transaction_id}: {$transactionId}</p>
            <p>{$translations.jtl_postfinancecheckout_transaction_state}: <b>{$transactionState}</b></p>
        </div>
    </div>

    {if $transactionState != 'FULFILL' && $transactionState != 'AUTHORIZED' && $transactionState != 'PARTIALLY REFUNDED' && $transactionState != 'REFUNDED'}
        <div class="card">
            <div class="card-body">
                {$translations.jtl_postfinancecheckout_transaction_no_possible_actions}
            </div>
        </div>
    {else}
        <div class="row">
            {if $transactionState == 'AUTHORIZED'}
                <div class="col-sm-3 col-xl-auto">
                    <button onclick="transactionAction({$transactionId}, 'complete')"
                            class="completex btn btn-success btn-block" type="button">
                        {$translations.jtl_postfinancecheckout_complete}
                    </button>
                </div>
                <div class="col-sm-3 col-xl-auto">
                    <button onclick="transactionAction({$transactionId}, 'cancel')"
                            class="cancel btn btn-danger btn-block"
                            type="button">
                        {$translations.jtl_postfinancecheckout_cancel}
                    </button>
                </div>
            {/if}
            {if $transactionState == 'FULFILL' || $transactionState == 'PARTIALLY REFUNDED' || $transactionState == 'REFUNDED'}
                <div class="col-sm-3 col-xl-auto">
                    <button class="refund btn btn-warning btn-block" data-toggle="collapse" data-target="#refund-form"
                            aria-expanded="false" aria-controls="refund-form"
                            type="button">
                        {$translations.jtl_postfinancecheckout_refunds}
                    </button>
                </div>
                <div class="col-sm-3 col-xl-auto">
                    <a href="{$postUrl}&action=download_invoice&transactionId={$transactionId}" download
                       class="btn btn-outline-primary btn-block" type="button">
                        {$translations.jtl_postfinancecheckout_download_invoice}
                    </a>
                </div>
                <div class="col-sm-3 col-xl-auto">
                    <a href="{$postUrl}&action=download_packaging_slip&transactionId={$transactionId}" download
                       class=" btn btn-outline-primary btn-block" type="button">
                        {$translations.jtl_postfinancecheckout_download_packaging_slip}
                    </a>
                </div>
            {/if}
        </div>
    {/if}
    <a class="btn btn-primary back-tab" href="{$menuId}">
        <span class="fal fa-long-arrow-left"></span>
    </a>
</div>

<div class="collapse" id="refund-form">
    <div class="card card-body">
        {if $showRefundsForm}
            <div class="mt-5">
                <h2>{$translations.jtl_postfinancecheckout_make_refund}</h2>
                <b>{$translations.jtl_postfinancecheckout_amount_to_refund}:</b> <input type="text" id="refund-amount" value="{$amountToBeRefunded}"/>
                <button onclick="transactionAction({$transactionId}, 'refund', $('#refund-amount').val())" type="button"
                        class="btn btn-success">
                    {$translations.jtl_postfinancecheckout_refund_now}
                </button>
            </div>
        {/if}

        <div class="mt-5">
            <h2>{$translations.jtl_postfinancecheckout_refunded_amount} {$totalRefundsAmountText} / {$totalAmountText}</h2>

            {if $refunds|@count > 0 && $refunds}
                <table class="table table-striped" id="refunds-details-table">
                    <thead>
                    <tr>
                        <th class="text-right">
                            <div class="grid">
                                <div class="span12"></div>
                            </div>
                        </th>
                        <th class="text-right">
                            <div class="grid">
                                <div class="span12">
                                    {$translations.jtl_postfinancecheckout_amount}:
                                </div>
                            </div>
                        </th>
                        <th class="text-right">
                            <div class="grid">
                                <div class="span12">
                                    {$translations.jtl_postfinancecheckout_refund_date}
                                </div>
                            </div>
                        </th>
                    </tr>
                    </thead>

                    <tbody>
                    {foreach $refunds as $key => $refund}
                        <tr>
                            <td class="text-right">
                                <div class="grid">
                                    <div class="span12">{$key + 1}</div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="grid">
                                    <div class="span12">{$refund->amountText}</div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="grid">
                                    <div class="span12">{$refund->created_at}</div>
                                </div>
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                    <hr/>
                    <tfooter>
                        <tr>
                            <th class="text-right">
                                <div class="grid">
                                    <div class="span12">{$translations.jtl_postfinancecheckout_total}</div>
                                </div>
                            </th>
                            <th class="text-right">
                                <div class="grid">
                                    <div class="span12">{$totalRefundsAmountText}</div>
                                </div>
                            </th>
                            <th class="text-right">
                                <div class="grid">
                                    <div class="span12"></div>
                                </div>
                            </th>
                        </tr>
                    </tfooter>
                </table>
            {else}
                <div class="card">
                    <div class="card-body">
                        {$translations.jtl_postfinancecheckout_no_refunds_info_text}
                    </div>
                </div>
            {/if}
        </div>
    </div>
</div>

<link rel="stylesheet" type="text/css" href="{$adminUrl}css/postfinancecheckout-admin.css">

<script>
    var request;

    function transactionAction(transactionId, action, amount = 0) {
        if (request) {
            request.abort();
        }

        var loader = $('.postfinancecheckout-loader');
        loader.show();
        var $button = $(this);
        $button.prop("disabled", true);

        var requestUrl = $('input[id=form-url]').val();
        request = $.ajax({
            url: requestUrl,
            type: "post",
            data: {
                'transactionId': transactionId,
                'action': action,
                'amount': amount
            }
        });

        request.done(function (response) {
            if (response) {
                loader.hide();
                $button.prop("disabled", false);
                alert(response);
            } else {
                setTimeout(function () {
                    loader.hide();
                    location.reload();
                }, 7000);
            }

            return false;
        });

        request.fail(function () {
            $button.prop("disabled", false);
        });
    };

    $('document').ready(function () {
        $('.back-tab').on('click', function () {
            $('.orders-table').show();
            $('#transaction-info').hide();
            $('.pagination-toolbar').show();
        });
    });
</script>
