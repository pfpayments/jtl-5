<?php declare(strict_types=1);

use Plugin\jtl_postfinancecheckout\Webhooks\PostFinanceCheckoutWebhookManager;

/** @global JTL\Plugin\PluginInterface $plugin */
$webhookManager = new PostFinanceCheckoutWebhookManager($plugin);
$webhookManager->listenForWebhooks();
exit;
