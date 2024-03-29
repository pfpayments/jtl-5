<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = (int)$_GET['tID'] ?? null;

if ($transactionId) {
    $apiClient = new PostFinanceCheckoutApiClient($plugin->getId());
    $transactionService = new PostFinanceCheckoutTransactionService($apiClient->getApiClient(), $plugin);
    $localTransaction = $transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
    $orderId = (int) $localTransaction->order_id;
    $transaction = $transactionService->getTransactionFromPortal($transactionId);
    $createAfterPayment = (int)$transaction->getMetaData()['orderAfterPayment'] ?? 1;
    if ($createAfterPayment) {
        if (empty($orderId)) {
            $orderId = $transactionService->createOrderAfterPayment($transactionId);
        }
    }
}

$_SESSION['transactionId'] = null;
$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$linkHelper = Shop::Container()->getLinkService();
if ($orderId > 0) {
    $bestellid = $this->db->select('tbestellid', 'kBestellung', $orderId);
}
$controlId = $bestellid->cId ?? '';
$url = $linkHelper->getStaticRoute('bestellabschluss.php') . '?i=' . $controlId;

\header('Location: ' . $url);
exit;
