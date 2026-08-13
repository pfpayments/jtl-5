<?php declare(strict_types=1);

use JTL\Shop;
use JTL\Alert\Alert;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = $_SESSION['transactionId'] ?? null;
PostFinanceCheckoutHelper::log("failed_payment: User landed on failure page. TransactionId: " . ($transactionId ?? 'NONE'));
$translations = PostFinanceCheckoutHelper::getTranslations($plugin->getLocalization(), [
    'jtl_postfinancecheckout_payment_not_available_by_country_or_currency',
], false);
$errorMessage = $translations['jtl_postfinancecheckout_payment_not_available_by_country_or_currency'];

if ($transactionId) {
    $apiClient = new PostFinanceCheckoutApiClient($plugin->getId());
    $transactionService = new PostFinanceCheckoutTransactionService($apiClient->getApiClient(), $plugin);
    $transaction = $transactionService->getTransactionFromPortal($transactionId);
    unset($_SESSION['transactionId']);

    $errorMessage = $transaction->getUserFailureMessage() ?? '';
    $alertHelper = Shop::Container()->getAlertService();
    $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, md5($errorMessage), ['saveInSession' => true]);

    if (str_contains(strtolower($errorMessage), 'timeout')) {
        unset($_SESSION['arrayOfPossibleMethods']);
    }

    $orderId = (int)($transaction->getMetaData()['orderId'] ?? 0);
    if ($orderId === 0) {
        $localTransaction = $transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
        $orderId = (int)($localTransaction->order_id ?? 0);
    }

    if ($orderId > 0) {
        // If confirmTransaction already rolled this order back (e.g. HTTP 442 from
        // address validation), the service has set this flag. Skip cleanup so we
        // don't run cancelOrder twice and double-restore stock via the additive UPDATE.
        $alreadyRolledBack = (int)($_SESSION['pfcn_rollback_done_order_id'] ?? 0) === $orderId;
        unset($_SESSION['pfcn_rollback_done_order_id']);

        if ($alreadyRolledBack) {
            PostFinanceCheckoutHelper::log("failed_payment: Order $orderId was already rolled back by confirmTransaction. Skipping cleanup.");
        } else {
            // Native JTL cancellation.
            // This triggers the standard 'Storno' routine which releases stock reservations
            // correctly and updates the order status to 'Cancelled'.
            // We use this instead of manual stock updates to ensure data integrity.
            $orderCancelled = $transactionService->cancelOrderOnce($orderId);
            if ($orderCancelled) {
                $transactionService->restoreStock($orderId);
            }
        }
    }
}

$linkHelper = Shop::Container()->getLinkService();
\header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
exit;
