<?php declare(strict_types=1);

use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;

/** @global \JTL\Smarty\JTLSmarty $smarty */
/** @global JTL\Plugin\PluginInterface $plugin */

$translations = PostFinanceCheckoutHelper::getTranslations($plugin->getLocalization(), [
  'jtl_postfinancecheckout_pay',
  'jtl_postfinancecheckout_cancel',
], false);

$smarty
    ->assign('translations', $translations)
    ->assign('integration', 'iframe')
    ->assign('paymentName', $_SESSION['Zahlungsart']->angezeigterName[PostFinanceCheckoutHelper::getLanguageIso(false)])
    ->assign('paymentId', $_SESSION['possiblePaymentMethodId'])
    ->assign('iframeJsUrl', $_SESSION['javascriptUrl'])
    ->assign('appJsUrl', $_SESSION['appJsUrl'])
    ->assign('mainCssUrl', $plugin->getPaths()->getBaseURL() . 'frontend/css/postfinancecheckout-main.css');
