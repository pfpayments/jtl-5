<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Webhooks\Strategies;

use JTL\Checkout\Bestellung;
use JTL\Customer\Customer;
use JTL\Mail\Mail\Mail;
use JTL\Mail\Mailer;
use JTL\Plugin\Plugin;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutOrderService;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutTransactionService;
use Plugin\jtl_postfinancecheckout\Webhooks\Strategies\Interfaces\PostFinanceCheckoutOrderUpdateStrategyInterface;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use stdClass;
use PostFinanceCheckout\Sdk\Model\Transaction;
use PostFinanceCheckout\Sdk\Model\TransactionState;

class PostFinanceCheckoutNameOrderUpdateTransactionStrategy implements PostFinanceCheckoutOrderUpdateStrategyInterface
{
    /**
     * @var Plugin $plugin
     */
    private $plugin;

    /**
     * @var PostFinanceCheckoutTransactionService $transactionService
     */
    private $transactionService;

    /**
     * @var PostFinanceCheckoutOrderService $orderService
     */
    private $orderService;

    public function __construct(PostFinanceCheckoutTransactionService $transactionService, Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->transactionService = $transactionService;
        $this->orderService = new PostFinanceCheckoutOrderService();
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function updateOrderStatus(string $entityId): void
    {
        $transaction = $this->transactionService->getTransactionFromPortal($entityId);
        $transactionId = $transaction->getId();

        $orderId = (int)$transaction->getMetaData()['orderId'];
        $transactionState = $transaction->getState();

        switch ($transactionState) {
            case TransactionState::FULFILL:
                // First we try update if order was created
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_BEZAHLT);
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                print 'Order ' . $orderId . ' status was updated to paid. Triggered by Transaction webhook.';
                break;

            case TransactionState::PROCESSING:
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_OFFEN, \BESTELLUNG_STATUS_IN_BEARBEITUNG);
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                print 'Order ' . $orderId . ' status was updated to processing. Triggered by Transaction Invoice webhook.';
                break;

            case TransactionState::AUTHORIZED:
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                if ($this->isSendConfirmationEmail()) {
                    $this->sendConfirmationMail($orderId);
                }
                print 'Order ' . $orderId . ' was authorized';
                break;

            case TransactionState::DECLINE:
            case TransactionState::VOIDED:
            case TransactionState::FAILED:
                $this->orderService->updateOrderStatus($orderId, \BESTELLUNG_STATUS_IN_BEARBEITUNG, \BESTELLUNG_STATUS_STORNO);
                $this->transactionService->updateTransactionStatus($transactionId, $transactionState);
                print 'Order ' . $orderId . ' status was updated to cancelled';
                break;
        }
    }

    private function isSendConfirmationEmail()
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($this->plugin->getId());
        $sendConfirmationEmail = $config[PostFinanceCheckoutHelper::SEND_CONFIRMATION_EMAIL] ?? null;

        return $sendConfirmationEmail === 'YES';
    }

    /**
     * @param int $orderId
     * @return void
     */
    private function sendConfirmationMail(int $orderId): void
    {
        Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['order_id'],
                [$orderId],
                (object)[
                    'confirmation_email_sent' => 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );

        $order = new Bestellung($orderId);
        $order->fuelleBestellung(false);
        $customer = new Customer($order->kKunde);
        $data = new stdClass();
        $mailer = Shop::Container()->get(Mailer::class);
        $mail = new Mail();

        $data->tkunde = $customer;
        $data->tbestellung = $order;
        if ($customer->cMail !== '') {
            $mailer->send($mail->createFromTemplateID(\MAILTEMPLATE_BESTELLBESTAETIGUNG, $data));
        }
    }
}