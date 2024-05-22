<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\frontend;

use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Checkout\Zahlungsart;
use JTL\DB\DbInterface;
use JTL\Helpers\PaymentMethod;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\PluginInterface;
use JTL\Session\Frontend;
use JTL\Shop;
use JTL\Smarty\JTLSmarty;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutRefundService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\TransactionState;


final class Handler
{
    /** @var PluginInterface */
    private $plugin;

    /** @var ApiClient|null */
    private $apiClient;

    /** @var DbInterface|null */
    private $db;

    /** @var PostFinanceCheckoutTransactionService */
    private $transactionService;

    /**
     * @var PostFinanceCheckoutRefundService $refundService
     */
    protected $refundService;

    /**
     * Handler constructor.
     * @param PluginInterface $plugin
     * @param DbInterface|null $db
     * @param ApiClient $apiClient
     */
    public function __construct(PluginInterface $plugin, ApiClient $apiClient, ?DbInterface $db = null)
    {
        $this->plugin = $plugin;
        $this->apiClient = $apiClient;
        $this->db = $db ?? Shop::Container()->getDB();
        $this->transactionService = new PostFinanceCheckoutTransactionService($this->apiClient, $this->plugin);
        $this->refundService = new PostFinanceCheckoutRefundService($this->apiClient, $this->plugin);
    }

    /**
     * @return string
     */
    public function createTransaction(): int
    {
        $transactionId = $_SESSION['transactionId'] ?? null;
        if (!$transactionId) {
            $order = new Bestellung();
            $order->Positionen = $_SESSION['Warenkorb']->PositionenArr;

            $randomString = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 16);
            $order->cBestellNr = $randomString;

            $createdTransaction = $this->transactionService->createTransaction($order);
            $transactionId = $createdTransaction->getId();

            $_SESSION['transactionId'] = $transactionId;
        }

        return (int)$transactionId;
    }

    public function fetchPossiblePaymentMethods(string $transactionId)
    {
        return $this->transactionService->fetchPossiblePaymentMethods($transactionId);
    }

    public function getPaymentMethodsForForm(JTLSmarty $smarty): array
    {
        $this->handleTransaction();
        $arrayOfPossibleMethods = $_SESSION['arrayOfPossibleMethods'] ?? [];
        $paymentMethods = $smarty->getTemplateVars('Zahlungsarten');
        foreach ($paymentMethods as $key => $paymentMethod) {
            if (empty($paymentMethod->cAnbieter) || strtolower($paymentMethod->cAnbieter) !== 'postfinancecheckout') {
                continue;
            }

            if (!\in_array($paymentMethod->cModulId, $arrayOfPossibleMethods, true)) {
                unset($paymentMethods[$key]);
            }
        }
        return $paymentMethods;
    }

    /**
     * @return void
     */
    private function handleTransaction(): void
    {
        $createdTransactionId = $_SESSION['transactionId'] ?? null;
        $arrayOfPossibleMethods = $_SESSION['arrayOfPossibleMethods'] ?? [];
        $addressCheck = $_SESSION['addressCheck'] ?? null;
        $currencyCheck = $_SESSION['currencyCheck'] ?? null;
        $lineItemsCheck = $_SESSION['lineItemsCheck'] ?? null;

        $md5LieferadresseCheck = isset($_SESSION['Lieferadresse']) && is_array($_SESSION['Lieferadresse']) ? md5(json_encode($_SESSION['Lieferadresse'])) : null;
        $md5LineItemsCheck = md5(json_encode((array)$_SESSION['Warenkorb']->PositionenArr));
        if ($addressCheck !== $md5LieferadresseCheck || $lineItemsCheck !== $md5LineItemsCheck || $currencyCheck !== $_SESSION['cWaehrungName']
        ) {
            $arrayOfPossibleMethods = null;
            if ($createdTransactionId) {
                $this->transactionService->updateTransaction($createdTransactionId);
            }

            $_SESSION['addressCheck'] = md5(json_encode((array)$_SESSION['Lieferadresse']));
            $_SESSION['lineItemsCheck'] = md5(json_encode((array)$_SESSION['Warenkorb']->PositionenArr));
            $_SESSION['currencyCheck'] = $_SESSION['cWaehrungName'];
        }

        if (!$createdTransactionId || !$arrayOfPossibleMethods) {
          if (!$createdTransactionId) {
            $this->resetTransaction();
          } else {
            $config = PostFinanceCheckoutHelper::getConfigByID($this->plugin->getId());
            $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];
            $transaction = $this->apiClient->getTransactionService()->read($spaceId, $createdTransactionId);
            
            $failedStates = [
              TransactionState::DECLINE,
              TransactionState::FAILED,
              TransactionState::VOIDED,
            ];
            
            if (empty($transaction) || empty($transaction->getVersion()) || in_array($transaction->getState(), $failedStates)) {
              $this->resetTransaction();
            } else {
              $this->transactionService->updateTransaction($createdTransactionId);
            }
          }
      
          $possiblePaymentMethods = $this->fetchPossiblePaymentMethods((string)$createdTransactionId);
          $arrayOfPossibleMethods = [];
          foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
              $arrayOfPossibleMethods[] = PostFinanceCheckoutHelper::PAYMENT_METHOD_PREFIX . '_' . $possiblePaymentMethod->getId();
          }
          $_SESSION['arrayOfPossibleMethods'] = $arrayOfPossibleMethods;
        }
    }

    /**
     * @param array $args
     * @return void
     */
    public function completeOrderAfterWawi(array $args): void
    {
        $order = $args['oBestellung'] ?? [];
        if (!$order || (int)$args['status'] !== \BESTELLUNG_STATUS_BEZAHLT) {
            return;
        }

        $obj = Shop::Container()->getDB()->selectSingleRow('postfinancecheckout_transactions', 'order_id', $order->kBestellung);
        $transactionId = $obj->transaction_id ?? '';
        if (empty($transactionId)) {
            return;
        }

        $transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);
        if ($transaction->state === TransactionState::AUTHORIZED) {
            $this->transactionService->completePortalTransaction($transactionId);
        }
    }

    /**
     * @param array $args
     * @return void
     */
    public function cancelOrderAfterWawi(array $args): void
    {
        $order = $args['oBestellung'] ?? [];

        $obj = Shop::Container()->getDB()->selectSingleRow('postfinancecheckout_transactions', 'order_id', $order->kBestellung);
        $transactionId = $obj->transaction_id ?? '';
        if (empty($transactionId)) {
            return;
        }

        $transaction = $this->transactionService->getLocalPostFinanceCheckoutTransactionById((string)$transactionId);

        switch ((int)$order->cStatus) {
            case \BESTELLUNG_STATUS_IN_BEARBEITUNG:
                if ($transaction->state === TransactionState::AUTHORIZED) {
                    $this->transactionService->cancelPortalTransaction($transactionId);
                }
                break;

            case \BESTELLUNG_STATUS_BEZAHLT:
                try {
                    $portalTransaction = $this->transactionService->getTransactionFromPortal($transactionId);
                    $this->refundService->makeRefund((string)$transactionId, (float)$portalTransaction->getAuthorizationAmount());
                } catch (\Exception $e) {

                }
                break;
        }
    }

    /**
     * @param string $spaceId
     * @param int $transactionId
     * @return void
     */
    public function confirmTransaction(string $spaceId, int $transactionId): void
    {
      $transaction = $this->apiClient->getTransactionService()->read($spaceId, $transactionId);
      
      $failedStates = [
        TransactionState::DECLINE,
        TransactionState::FAILED,
        TransactionState::VOIDED,
      ];

      if (empty($transaction) || empty($transaction->getVersion()) || in_array($transaction->getState(), $failedStates)) {
        $_SESSION['transactionId'] = null;
        $linkHelper = Shop::Container()->getLinkService();
        \header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
        exit;
      }
      
      $this->transactionService->confirmTransaction($transaction);
    }

    public function getRedirectUrlAfterCreatedTransaction($orderData): string
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($this->plugin->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];

        $createdTransactionId = (int)$_SESSION['transactionId'] ?? null;

        if (empty($createdTransactionId)) {
            $failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
            header("Location: " . $failedUrl);
            exit;
        }

        // TODO create setting with options ['payment_page', 'iframe'];
        $integration = 'iframe';
        $_SESSION['transactionId'] = $createdTransactionId;

        $_SESSION['javascriptUrl'] = $this->apiClient->getTransactionIframeService()
            ->javascriptUrl($spaceId, $createdTransactionId);
        $_SESSION['appJsUrl'] = $this->plugin->getPaths()->getBaseURL() . 'frontend/js/postfinancecheckout-app.js?' . time();

        $paymentMethod = $this->transactionService->getTransactionPaymentMethod($createdTransactionId, $spaceId);
        if (empty($paymentMethod)) {
            $failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];
            header("Location: " . $failedUrl);
            exit;
        }

        $_SESSION['possiblePaymentMethodId'] = $paymentMethod->getId();
        $_SESSION['possiblePaymentMethodName'] = $paymentMethod->getName();
        $_SESSION['orderData'] = $orderData;

        $this->confirmTransaction($spaceId, $createdTransactionId);

        if ($integration == 'payment_page') {
            $redirectUrl = $this->apiClient->getTransactionPaymentPageService()
                ->paymentPageUrl($spaceId, $createdTransactionId);

            return $redirectUrl;
        }

        return PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['payment-page'][$_SESSION['cISOSprache']];
    }

    public function contentUpdate(array $args): void
    {
        global $step;

        switch (Shop::getPageType()) {
            case \PAGE_BESTELLVORGANG:
                if ($step !== 'accountwahl') {
                    $this->handleTransaction();
                }
                $this->setPaymentMethodLogoSize();
                break;

            case \PAGE_BESTELLABSCHLUSS:
                if ($_SESSION['Zahlungsart']?->nWaehrendBestellung ?? null === 1) {
                    $smarty = $args['smarty'];
                    $order = $smarty->getTemplateVars('Bestellung');

                    if (!empty($order)) {
                        $redirectUrl = $this->getRedirectUrlAfterCreatedTransaction($order);
                        header("Location: " . $redirectUrl);
                        exit;
                    }
                }
                break;
        }
    }

    public function setPaymentMethodLogoSize(): void
    {
        global $step;

        if (\in_array($step, ['Zahlung', 'Versand'])) {
            $paymentMethodsCss = '<link rel="stylesheet" href="' . $this->plugin->getPaths()->getBaseURL() . 'frontend/css/checkout-payment-methods.css">';
            pq('head')->append($paymentMethodsCss);
        }
    }
	
	/**
	 * @return void
	 */
	private function resetTransaction(): void
	{
		$_SESSION['transactionId'] = null;
		$createdTransactionId = $this->createTransaction();
		$_SESSION['transactionId'] = $createdTransactionId;
	}

}
