<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$transactionId = (int)$_GET['tID'] ?? null;
if ($transactionId) {
    $apiClient = new PostFinanceCheckoutApiClient($plugin->getId());
    $transactionService = new PostFinanceCheckoutTransactionService($apiClient->getApiClient(), $plugin);

    // In case error from api, we will try to fetch transaction again
    $transaction = $transactionService->getTransactionFromPortal($transactionId);
	Shop::Container()->getLogService()->notice(
	  "Transaction found. Starting to create order."
	);
    $createAfterPayment = (int)$transaction->getMetaData()['orderAfterPayment'] ?? 1;
    if ($createAfterPayment) {
        $orderId = (int)$transaction->getMetaData()['orderId'];
        $order = new Bestellung($orderId);
        $orderId = (int)$order->kBestellung;
    } else {
		Shop::Container()->getLogService()->notice(
		  "Order was not created. We created it previously and returning the ID."
		);
        $localTransaction = $transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
        $orderId = (int) $localTransaction->order_id;
    }
} else {
    Shop::Container()->getLogService()->notice(
        "No transaction ID."
    );
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
