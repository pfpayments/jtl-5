<input type="hidden" name="form-url" id="form-url" value="{$postUrl}">
{if $orders|@count > 0 && $orders}

    <form method="get" name="postfinancecheckout-orders" action="{$currentUrl}">
        <div class="form-row">
            <!-- Label for the search input -->
            <label class="col-sm-auto col-form-label" for="orderSearch">{$translations.jtl_postfinancecheckout_search}:</label>

            <!-- Search input field -->
            <div class="col-sm-auto mb-2">
                <input class="form-control" name="q" type="text" id="orderSearch" placeholder="Search by order number" value="{$searchQueryString}">
            </div>

            <!-- Submit button -->
            <span class="col-sm-auto">
            <button name="submitSearch" type="submit" class="btn btn-primary btn-block">
                <i class="fal fa-search"></i> Search
            </button>
        </span>
        </div>
    </form>

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

                <div class="postfinancecheckout-loader text-center" style="display: none">
                    <img src="{$adminUrl}/assets/spinner.gif"/>
                </div>
                <!-- Include the orders loop partial -->
                {include file="`$tplPath`/_orders_table.tpl" orders=$orders}
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
    var requestUrl = $('#form-url').val();

    function showDetails(requestParams) {
        $.ajax({
            url: requestUrl,
            type: 'POST',
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
