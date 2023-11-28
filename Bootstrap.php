<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout;

if (file_exists(dirname(__DIR__) . '/jtl_postfinancecheckout/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/jtl_postfinancecheckout/vendor/autoload.php';
}

use JTL\Events\Dispatcher;
use JTL\phpQuery\phpQuery;
use JTL\Plugin\Bootstrapper;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use JTL\Plugin\Helper;
use Plugin\jtl_paypal\paymentmethod\PendingPayment;
use Plugin\jtl_postfinancecheckout\adminmenu\AdminTabProvider;
use Plugin\jtl_postfinancecheckout\frontend\Handler as FrontendHandler;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutPaymentService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutWebhookService;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\PaymentMethodConfiguration;

/**
 * Class Bootstrap
 * @package Plugin\jtl_postfinancecheckout
 */
class Bootstrap extends Bootstrapper
{
    /**
     * @var PostFinanceCheckoutPaymentService|null
     */
    private ?PostFinanceCheckoutPaymentService $paymentService = null;

    /**
     * @var ApiClient|null
     */
    private ?ApiClient $apiClient = null;

    /**
     * @inheritdoc
     */
    public function boot(Dispatcher $dispatcher)
    {
        parent::boot($dispatcher);
        $plugin = $this->getPlugin();

        if (Shop::isFrontend()) {
            $apiClient = PostFinanceCheckoutHelper::getApiClient($plugin->getId());
            if (empty($apiClient)) {
                // Need to run composer install
                return;
            }
            $handler = new FrontendHandler($plugin, $apiClient, $this->getDB());
            $this->listenFrontendHooks($dispatcher, $handler);
        } else {
            $this->listenPluginSaveOptionsHook($dispatcher);
        }
    }

    /**
     * @inheritdoc
     */
    public function uninstalled(bool $deleteData = true)
    {
        parent::uninstalled($deleteData);
        $this->updatePaymentMethodStatus(PostFinanceCheckoutPaymentService::STATUS_DISABLED);
    }

    /**
     * @inheritDoc
     */
    public function enabled(): void
    {
        parent::enabled();
        $this->updatePaymentMethodStatus();
    }

    /**
     * @inheritDoc
     */
    public function disabled(): void
    {
        parent::disabled();
        $this->updatePaymentMethodStatus(PostFinanceCheckoutPaymentService::STATUS_DISABLED);
    }

    /**
     * @inheritDoc
     */
    public function renderAdminMenuTab(string $tabName, int $menuID, JTLSmarty $smarty): string
    {
        $tabsProvider = new AdminTabProvider($this->getPlugin(), $this->getDB(), $smarty);
        return $tabsProvider->createOrdersTab($menuID);
    }

    /**
     * @return void
     */
    protected function installPaymentMethodsOnSettingsSave(): void
    {
        $paymentService = $this->getPaymentService();
        $paymentService?->syncPaymentMethods();
    }

    /**
     * @return void
     */
    protected function registerWebhooksOnSettingsSave(): void
    {
        $apiClient = $this->getApiClient();
        if ($apiClient === null) {
            return;
        }

        $webhookService = new PostFinanceCheckoutWebhookService($apiClient, $this->getPlugin()->getId());
        $webhookService->install();
    }

    /**
     * @param Dispatcher $dispatcher
     * @param FrontendHandler $handler
     * @return void
     */
    private function listenFrontendHooks(Dispatcher $dispatcher, FrontendHandler $handler): void
    {
        $dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$handler, 'contentUpdate']);
        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, function ($args) use ($handler) {
            if (isset($_SESSION['Zahlungsart']->cModulId) && str_contains(\strtolower($_SESSION['Zahlungsart']->cModulId), 'postfinancecheckout')) {
                $redirectUrl = $handler->getRedirectUrlAfterCreatedTransaction($args['oBestellung']);
                header("Location: " . $redirectUrl);
                exit;
            }
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPZAHLUNG, function () use ($handler) {
            $smarty = Shop::Smarty();
            $paymentMethods = $handler->getPaymentMethodsForForm($smarty);
            $smarty->assign('Zahlungsarten', $paymentMethods);
        });
    }

    /**
     * @param Dispatcher $dispatcher
     * @return void
     */
    private function listenPluginSaveOptionsHook(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('shop.hook.' . \HOOK_PLUGIN_SAVE_OPTIONS, function () {
            $this->installPaymentMethodsOnSettingsSave();
            $this->registerWebhooksOnSettingsSave();
        });
    }

    /**
     * @return PostFinanceCheckoutPaymentService|null
     */
    private function getPaymentService(): ?PostFinanceCheckoutPaymentService
    {
        $apiClient = $this->getApiClient();
        if ($apiClient === null) {
            return null;
        }

        if ($this->paymentService === null) {
            $this->paymentService = new PostFinanceCheckoutPaymentService($apiClient, $this->getPlugin()->getId());
        }

        return $this->paymentService;
    }

    /**
     * @return ApiClient|null
     */
    private function getApiClient(): ?ApiClient
    {
        if ($this->apiClient === null) {
            $this->apiClient = PostFinanceCheckoutHelper::getApiClient($this->getPlugin()->getId());
        }

        return $this->apiClient;
    }

    /**
     * @param int $status
     * @return void
     */
    private function updatePaymentMethodStatus(int $status = PostFinanceCheckoutPaymentService::STATUS_ENABLED): void
    {
        $paymentService = $this->getPaymentService();
        $paymentService?->updatePaymentMethodStatus($status);
    }
}
