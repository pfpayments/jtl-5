<?php declare(strict_types=1);

use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Session\Frontend;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$orderData = $_SESSION['orderData'];
// When order has to be created after payment
if ($_SESSION['Zahlungsart']?->nWaehrendBestellung ?? null === 1) {
    if ($_SESSION['Warenkorb'] && $_SESSION['transactionId'] && $_SESSION['arrayOfPossibleMethods']) {
        $_SESSION['finalize'] = true;
        $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
        $order = $orderHandler->finalizeOrder();
        $orderData = $order->fuelleBestellung(true);
        $_SESSION['orderData'] = $orderData;
    }
} else {
    $order = new Bestellung($orderData->kBestellung);
    $orderData = $order->fuelleBestellung(true);
}

$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$smarty
    ->assign('Bestellung', $orderData)
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/postfinancecheckout-loader-main.css');
