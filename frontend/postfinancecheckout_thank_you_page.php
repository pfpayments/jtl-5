<?php declare(strict_types=1);

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$_SESSION['Warenkorb'] = null;
$_SESSION['transactionId'] = null;
$_SESSION['arrayOfPossibleMethods'] = null;

$smarty
  ->assign('Bestellung', $_SESSION['orderData'])
  ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/postfinancecheckout-loader-main.css');
