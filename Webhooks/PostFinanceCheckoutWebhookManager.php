<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks;

use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutPaymentService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutRefundService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\PostFinanceCheckoutNameOrderUpdateRefundStrategy;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\PostFinanceCheckoutNameOrderUpdateTransactionInvoiceStrategy;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\PostFinanceCheckoutNameOrderUpdateTransactionStrategy;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutApiClient;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{TransactionInvoiceState, TransactionState};

/**
 * Class PostFinanceCheckoutWebhookManager
 * @package Plugin\jtl_postfinancecheckout
 */
class PostFinanceCheckoutWebhookManager
{
    /**
     * @var array $data
     */
    protected $data;

    /**
     * @var ApiClient $apiClient
     */
    protected ApiClient $apiClient;

    /**
     * @var Plugin $plugin
     */
    protected $plugin;

    /**
     * @var PostFinanceCheckoutTransactionService $transactionService
     */
    protected $transactionService;

    /**
     * @var PostFinanceCheckoutRefundService $refundService
     */
    protected $refundService;

    /**
     * @var PostFinanceCheckoutOrderService $orderService
     */
    protected $orderService;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->data = json_decode(file_get_contents('php://input'), true);
        $this->apiClient = (new PostFinanceCheckoutApiClient($plugin->getId()))->getApiClient();
        $this->transactionService = new PostFinanceCheckoutTransactionService($this->apiClient, $this->plugin);
        $this->refundService = new PostFinanceCheckoutRefundService($this->apiClient, $this->plugin);
    }

    public function listenForWebhooks(): void
    {
        $listenerEntityTechnicalName = $this->data['listenerEntityTechnicalName'] ?? null;
        if (!$listenerEntityTechnicalName) {
            return;
        }

        $orderUpdater = new PostFinanceCheckoutOrderUpdater(new PostFinanceCheckoutNameOrderUpdateTransactionStrategy($this->transactionService, $this->plugin));
        $entityId = (string)$this->data['entityId'];

        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
        if (!empty($signature)) {
            try {
                $this->apiClient->getWebhookEncryptionService()->isContentValid($signature, file_get_contents('php://input'));
            } catch (\Exception $e) {
                header('Content-Type: application/json', true, 400);
                echo json_encode([
                    'error' => 'Webhook validation failed: ' . $e->getMessage(),
                    'entityId' => $entityId ?? 'unknown'
                ]);
                exit;
            }
        }

        switch ($listenerEntityTechnicalName) {
            case PostFinanceCheckoutHelper::TRANSACTION:
                $orderUpdater->updateOrderStatus($entityId);
                $transactionStateFromWebhook = $this?->data['state'] ?? null;
                if ($transactionStateFromWebhook === TransactionState::AUTHORIZED) {
                    $transaction = $this->transactionService->getTransactionFromPortal($entityId);
                    $orderId = (int)$transaction->getMetaData()['orderId'];
                    $this->transactionService->sendEmail($orderId, 'authorization');
                }
                break;

            case PostFinanceCheckoutHelper::TRANSACTION_INVOICE:
                $orderUpdater->setStrategy(new PostFinanceCheckoutNameOrderUpdateTransactionInvoiceStrategy($this->transactionService));
                $orderUpdater->updateOrderStatus($entityId);
                break;

            case PostFinanceCheckoutHelper::REFUND:
                $orderUpdater->setStrategy(new PostFinanceCheckoutNameOrderUpdateRefundStrategy($this->refundService, $this->transactionService));
                $orderUpdater->updateOrderStatus($entityId);
                break;

            case PostFinanceCheckoutHelper::PAYMENT_METHOD_CONFIGURATION:
                $paymentService = new PostFinanceCheckoutPaymentService($this->apiClient, $this->plugin->getId());
                $paymentService->syncPaymentMethods();
                break;
        }
    }
}

