<?php declare(strict_types=1);

use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = $_SESSION['transactionId'] ?? null;
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
    if (str_contains(strtolower($errorMessage), 'timeout')) {
        unset($_SESSION['transactionId']);
        unset($_SESSION['arrayOfPossibleMethods']);
    }
    $alertHelper = Shop::Container()->getAlertService();
    $alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, 'display error on payment page', ['saveInSession' => true]);
}

if (!function_exists('restoreCart')) {
    function restoreCart($cartItems)
    {
        foreach ($cartItems as $cartItem) {
            if ($cartItem->kArtikel === 0) {
                continue;
            }

            Shop::Container()->getDB()->update(
                'tartikel',
                'kArtikel',
                (int)$cartItem->kArtikel,
                (object)['fLagerbestand' => $cartItem->fLagerbestandVorAbschluss]
            );
        }
    }
}

if (isset($_SESSION['orderData']) && !empty($_SESSION['orderData']->Positionen)) {
    $cartItems = $_SESSION['orderData']->Positionen;
    if ($cartItems) {
        restoreCart($cartItems);
    }
}

$alertHelper = Shop::Container()->getAlertService();
$alertHelper->addAlert(Alert::TYPE_ERROR, $errorMessage, 'display error on payment page', ['saveInSession' => true]);
\header('Location:' . Shop::getURL() . '/Bestellvorgang?editVersandart=1');
exit;
