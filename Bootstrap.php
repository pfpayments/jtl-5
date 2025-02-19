<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout;

if (file_exists(dirname(__DIR__) . '/jtl_postfinancecheckout/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/jtl_postfinancecheckout/vendor/autoload.php';
}

use JTL\Checkout\Bestellung;
use JTL\Checkout\Zahlungsart;
use JTL\Events\Dispatcher;
use JTL\Plugin\Bootstrapper;
use JTL\Plugin\Payment\Method;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_paypal\paymentmethod\PendingPayment;
use Plugin\jtl_postfinancecheckout\adminmenu\AdminTabProvider;
use Plugin\jtl_postfinancecheckout\frontend\Handler as FrontendHandler;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutPaymentService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutWebhookService;
use PostFinanceCheckout\Sdk\ApiException;
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
     * @var PostFinanceCheckoutTransactionService|null
     */
    private ?PostFinanceCheckoutTransactionService $transactionService = null;

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
        $cartUpdateListener = function () use ($handler) {
            $transactionId = $_SESSION['transactionId'] ?? null;
            if ($transactionId) {
                $lastCartItemsHash = $_SESSION['lastCartItemHash'] ?? null;
                $lineItems = $_SESSION['Warenkorb']?->PositionenArr;

                if ($lineItems === null) {
                    return;
                }

                $cartItemsHash = md5(json_encode($lineItems));

                if ($lastCartItemsHash !== $cartItemsHash) {
                    $_SESSION['lastCartItemHash'] = $cartItemsHash;
                    $transactionService = $this->getTransactionService();
                    $transactionService->updateTransaction($transactionId);
                }
            }
        };

        $cartUpdateHooks = [\HOOK_BESTELLVORGANG_PAGE, \HOOK_WARENKORB_PAGE, \HOOK_WARENKORB_CLASS_FUEGEEIN, \HOOK_WARENKORB_LOESCHE_POSITION, \HOOK_WARENKORB_LOESCHE_ALLE_SPEZIAL_POS];
        foreach ($cartUpdateHooks as $cartUpdateHook) {
            $dispatcher->listen('shop.hook.' . $cartUpdateHook, $cartUpdateListener);
        }

        $dispatcher->listen('shop.hook.' . \HOOK_SMARTY_OUTPUTFILTER, [$handler, 'contentUpdate']);
        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLABSCHLUSS_INC_BESTELLUNGINDB_ENDE, function ($args) use ($handler) {
            $obj = Shop::Container()->getDB()->selectSingleRow('tzahlungsart', 'kZahlungsart', (int)$_SESSION['AktiveZahlungsart']);
            $createOrderAfterPayment = (int)$obj->nWaehrendBestellung ?? 1;
            if ($createOrderAfterPayment === 0) {
                if (isset($_SESSION['Zahlungsart']->cModulId) && str_contains(\strtolower($_SESSION['Zahlungsart']->cModulId), 'postfinancecheckout')) {
                    $redirectUrl = $handler->getRedirectUrlAfterCreatedTransaction($args['oBestellung']);
                    header("Location: " . $redirectUrl);
                    exit;
                }
            }
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLVORGANG_PAGE_STEPZAHLUNG, function () use ($handler) {
            $smarty = Shop::Smarty();
            $paymentMethods = $handler->getPaymentMethodsForForm($smarty);
            $smarty->assign('Zahlungsarten', $paymentMethods);
        });

        $transactionService = $this->getTransactionService();
        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BEARBEITESET, function ($args) use ($handler, $transactionService) {
            $order = $args['oBestellung'] ?? null;
            if ($order === null) {
                return;
            }

            $orderStatus = $order->cStatus ?? null;
            if ($orderStatus === null) {
                return;
            }

            if ((int)$orderStatus === \BESTELLUNG_STATUS_BEZAHLT) {
                $orderId = $args['oBestellung']->kBestellung ?? null;
                if ($orderId === null) {
                    return;
                }

                $order = new Bestellung($orderId);
                if (empty($order->kZahlungsart)) {
                    return;
                }
                $paymentMethodEntity = new Zahlungsart($order->kZahlungsart);

                if ($order->cStatus != \BESTELLUNG_STATUS_VERSANDT && $paymentMethodEntity->cAnbieter === 'PostFinanceCheckout') {
                    $moduleId = $paymentMethodEntity->cModulId ?? '';
                    $paymentMethod = new Method($moduleId);
                    $paymentMethod->setOrderStatusToPaid($order);
                    $transactionService->updateWawiSyncFlag($orderId, $transactionService::NOT_SYNC_TO_WAWI);
                }
            }
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BESTELLSTATUS, function ($args) use ($handler) {
            $handler->completeOrderAfterWawi($args);
        });

        $dispatcher->listen('shop.hook.' . \HOOK_BESTELLUNGEN_XML_BEARBEITESTORNO, function ($args) use ($handler) {
            $handler->cancelOrderAfterWawi($args);
        });
    }

    /**
     * @param Dispatcher $dispatcher
     * @return void
     */
    private function listenPluginSaveOptionsHook(Dispatcher $dispatcher): void
    {
        $dispatcher->listen('shop.hook.' . \HOOK_PLUGIN_SAVE_OPTIONS, function ($args_arr) {
            if ($this->isValidFormData($args_arr)) {
                $this->installPaymentMethodsOnSettingsSave();
                $this->registerWebhooksOnSettingsSave();
            }
            $args_arr['continue'] = false;
        });
    }

    /**
     * @param array $args
     * @return bool
     */
    private function isValidFormData(array $args): bool {
        $errors = [];

        // Validation rules configuration
        $validationRules = [
          'jtl_postfinancecheckout_space_id' => ['type' => 'numeric', 'message' => 'Space ID must be a valid number.'],
          'jtl_postfinancecheckout_user_id' => ['type' => 'numeric', 'message' => 'User ID must be a valid number.'],
          'jtl_postfinancecheckout_application_key' => ['type' => 'string', 'message' => 'Application Key cannot be empty.'],
        ];

        // Validate form data
        foreach ($args['options'] as $option) {
            $rule = $validationRules[$option->valueID] ?? null;
            if ($rule) {
                // Perform validation check
                $errorFound = false;
                if ($rule['type'] === 'numeric' && (!is_numeric($option->value) || empty($option->value))) {
                    $errorFound = true;
                } elseif ($rule['type'] === 'string' && empty($option->value)) {
                    $errorFound = true;
                }

                if ($errorFound) {
                    $this->addValidationError($rule['message'], $errors);
                }
            }
        }

        // Add further validation for space access only if no errors in basic validation
        if (empty($errors)) {
            $apiClient = $this->getApiClient();
            if ($apiClient !== null) {
                $this->validateSpaceAccess($apiClient, $errors);
            }
        }

        return empty($errors);
    }

    private function addValidationError(string $message, array &$errors): void {
        $errors[] = $message;
        // Second parameter is key. We want to display all errors at once, so let's make it dynamic
        Shop::Container()->getAlertService()->addDanger($message, 'isValidFormData' . md5($message));
    }

    /**
     * @param ApiClient|null $apiClient
     * @param array $errors
     * @return void
     */
    private function validateSpaceAccess(ApiClient $apiClient, array &$errors): void {
        $config = PostFinanceCheckoutHelper::getConfigByID($this->getPlugin()->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID] ?? null;

        try {
            $spaceData = $apiClient->getSpaceService()->read($spaceId);
            if (is_null($spaceData) || is_null($spaceData->getAccount())) {
                $this->addValidationError('The space does not exist or you do not have access to it.', $errors);
            }
        } catch (ApiException $e) {
            $this->addValidationError($e->getResponseBody()->message, $errors);
        }
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
     * @return PostFinanceCheckoutTransactionService|null
     */
    private function getTransactionService(): ?PostFinanceCheckoutTransactionService
    {
        $apiClient = $this->getApiClient();
        if ($apiClient === null) {
            return null;
        }

        if ($this->transactionService === null) {
            $this->transactionService = new PostFinanceCheckoutTransactionService($apiClient, $this->getPlugin());
        }

        return $this->transactionService;
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
