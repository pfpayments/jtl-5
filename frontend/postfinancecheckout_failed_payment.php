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
        // Native JTL cancellation. 
        // This triggers the standard 'Storno' routine which releases stock reservations 
        // correctly and updates the order status to 'Cancelled'. 
        // We use this instead of manual stock updates to ensure data integrity.
        $order = new \JTL\Checkout\Bestellung($orderId);
        if (!empty($order->kZahlungsart)) {
            $paymentMethodEntity = new \JTL\Checkout\Zahlungsart((int)$order->kZahlungsart);
            $paymentMethod = new \JTL\Plugin\Payment\Method($paymentMethodEntity->cModulId);
            
            PostFinanceCheckoutHelper::log("failed_payment: Cancelling Order $orderId via native cancelOrder routine.");
            $paymentMethod->cancelOrder($orderId);

            // Since JTL's native cancelOrder doesn't release stock, we handle it manually but safely.
            // We use an additive UPDATE to avoid race conditions with other orders.
            $order->fuelleBestellung(false);
            foreach ($order->Positionen as $pos) {
                if ((int)$pos->nPosTyp === \C_WARENKORBPOS_TYP_ARTIKEL && (int)$pos->kArtikel > 0) {
                    PostFinanceCheckoutHelper::log("failed_payment: Restoring stock for Product $pos->kArtikel (Qty: $pos->nAnzahl)");
                    Shop::Container()->getDB()->queryPrepared(
                        "UPDATE tartikel SET fLagerbestand = fLagerbestand + :qty WHERE kArtikel = :id",
                        ['qty' => (float)$pos->nAnzahl, 'id' => (int)$pos->kArtikel]
                    );
                }
            }
        }
    }
}

$linkHelper = Shop::Container()->getLinkService();
\header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
exit;
