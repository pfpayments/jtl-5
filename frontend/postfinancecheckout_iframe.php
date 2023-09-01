<?php declare(strict_types=1);

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$smarty
    ->assign('integration', 'iframe')
    ->assign('paymentName', $_SESSION['possiblePaymentMethodName'])
    ->assign('paymentId', $_SESSION['possiblePaymentMethodId'])
    ->assign('iframeJsUrl', $_SESSION['javascriptUrl'])
    ->assign('appJsUrl', $_SESSION['appJsUrl'])
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/postfinancecheckout-main.css');
