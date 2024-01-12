<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

// When order has to be created after payment
if ($_SESSION['Zahlungsart']?->nWaehrendBestellung ?? null === 1) {
    if ($_SESSION['Warenkorb'] && $_SESSION['transactionId'] && $_SESSION['arrayOfPossibleMethods']) {
        $apiClient = new PostFinanceCheckoutApiClient($plugin->getId());
        $transactionService = new PostFinanceCheckoutTransactionService($apiClient->getApiClient(), $plugin);
        $transactionService->createOrderAfterPayment();
    }
} else {
    $order = new Bestellung($_SESSION['orderData']->kBestellung);
    $_SESSION['orderData'] = $order->fuelleBestellung(true);
}
$_SESSION['transactionId'] = null;
$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$linkHelper = Shop::Container()->getLinkService();
$orderId = $_SESSION['orderData']->kBestellung ?? 0;
$bestellid = $this->db->select('tbestellid', 'kBestellung', $orderId);
$controlId = $bestellid->cId ?? '';
$url = $linkHelper->getStaticRoute('bestellabschluss.php') . '?i=' . $controlId;

\header('Location: ' . $url);
exit;
