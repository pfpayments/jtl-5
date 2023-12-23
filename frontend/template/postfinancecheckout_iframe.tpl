<div class="flashbags"></div>

<div class="postfinancecheckout-block">
    <div class="card checkout-card">
        <div class="card-body">
            <div class="card-title">
                <b>{$paymentName}</b>
            </div>
            <hr/>
            <div id="postfinancecheckout-payment-panel"
                 class="postfinancecheckout-payment-panel"
                 data-postfinancecheckout-checkout-plugin="true"
                 data-id="{$paymentId}">
                <div id="postfinancecheckoutLoader">
                    <div></div>
                </div>
                <input value="false" type="hidden" name="postfinancecheckout_payment_handler_validation_status"
                       form="confirmOrderForm">
                <div id="postfinancecheckout-payment-iframe"
                     class="postfinancecheckout-payment-iframe"></div>
            </div>
        </div>
    </div>

    <hr/>

    <div class="checkout-aside-action">
        <form name="confirmOrderForm" id="confirmOrderForm">
            <input type="hidden" id="cartRecreateUrl" value="/"/>
            <input type="hidden" id="checkoutUrl" value="/postfinancecheckout-payment-page"/>
            <button id="confirmFormSubmit"
                    class="btn btn-primary btn-block btn-lg"
                    form="confirmOrderForm"
                    disabled
                    type="submit">
                {$translations.jtl_postfinancecheckout_pay}
            </button>
            <button style="visibility: hidden" type="button"
                    class="btn btn-outline-primary header-minimal-back-to-shop-button"
                    id="postfinancecheckoutOrderCancel">{$translations.jtl_postfinancecheckout_cancel}
            </button>
        </form>
    </div>
</div>

<script src="{$iframeJsUrl}"></script>
<script src="{$appJsUrl}"></script>
<script>
    $('head').append('<link rel="stylesheet" type="text/css" href="{$mainCssUrl}">');
    $("#header-top-bar > div > ul").hide();
</script>
