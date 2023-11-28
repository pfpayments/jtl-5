<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Cart\CartItem;
use JTL\Catalog\Currency;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Helpers\PaymentMethod;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{AddressCreate,
    LineItemCreate,
    LineItemType,
    Refund,
    RefundCreate,
    RefundType,
    Transaction,
    TransactionCreate,
    TransactionPending,
    TransactionState
};

class PostFinanceCheckoutRefundService
{
    /**
     * @var ApiClient $apiClient
     */
    protected ApiClient $apiClient;

    /**
     * @var $spaceId
     */
    protected $spaceId;

    /**
     * @var $transactionService
     */
    protected $transactionService;

    public function __construct(ApiClient $apiClient, $plugin)
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($plugin->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];

        $this->apiClient = $apiClient;
        $this->spaceId = $spaceId;
        $this->transactionService = new PostFinanceCheckoutTransactionService($apiClient, $plugin);
    }

    public function makeRefund(string $transactionId, float $amount)
    {
        if ($amount <= 0) {
            print 'Amount should be greater than 0';
            exit;
        }

        $transaction = $this->transactionService->getTransactionFromPortal($transactionId);
        $transactionAmount = $transaction->getAuthorizationAmount();
        if ($amount > $transactionAmount) {
            print 'Please make sure you are trying to refund correct amount of money';
            exit;
        }

        $state = $transaction->getState();
        if ($state === 'FULFILL') {
            try {
                $refundPayload = (new RefundCreate())
                    ->setAmount(\round($amount, 2))
                    ->setTransaction($transactionId)
                    ->setMerchantReference((string)mb_substr($transaction->getMerchantReference(), 0, 100, 'UTF-8'))
                    ->setExternalId(uniqid('refund_', true))
                    ->setType(RefundType::MERCHANT_INITIATED_ONLINE);

                if (!$refundPayload->valid()) {
                    print 'Refund payload invalid:' . json_encode($refundPayload->listInvalidProperties());
                    exit;
                }

                $this->apiClient->getRefundService()->refund($this->spaceId, $refundPayload);

                return [];
            } catch (\Exception $e) {
                $detectJsonPattern = '/
					\{              # { character
						(?:         # non-capturing group
							[^{}]   # anything that is not a { or }
							|       # OR
							(?R)    # recurses the entire pattern
						)*          # previous group zero or more times
					\}              # } character
					/x';
                preg_match_all($detectJsonPattern, $e->getMessage(), $matches);
                $jsonErrorMessage = $matches[0][0];
                $errorData = \json_decode($jsonErrorMessage);

                print $errorData->message;
                exit;
            }
        }
    }

    /**
     * @param string $refundId
     * @return Refund
     */
    public function getRefundFromPortal(string $refundId)
    {
        return $this->apiClient->getRefundService()->read($this->spaceId, $refundId);
    }

    /**
     * @param string $orderId
     * @param string $currency
     * @return array
     */
    public function getRefunds(string $orderId, string $currency = null): array
    {
        if (!$currency) {
            $currency = Frontend::getCurrency();
        }

        $refunds = Shop::Container()
            ->getDB()
            ->selectAll('postfinancecheckout_refunds', 'order_id', $orderId);

        foreach ($refunds as $refund) {
            $refund->amountText = Preise::getLocalizedPriceWithoutFactor(floatval($refund->amount), Currency::fromISO($currency), true);
        }

        return $refunds;
    }

    /**
     * @param array $refunds
     * @return float
     */
    public function getTotalRefundsAmount(array $refunds): float
    {
        $total = 0;
        foreach ($refunds as $refund) {
            $total += $refund->amount;
        }

        return round($total, 2);
    }

    /**
     * @param int $refundId
     * @param int $orderId
     * @param float $amount
     */
    public function createRefundRecord(int $refundId, int $orderId, float $amount): void
    {
        $newRefund = new \stdClass();
        $newRefund->refund_id = $refundId;
        $newRefund->order_id = $orderId;
        $newRefund->amount = $amount;
        $newRefund->created_at = date('Y-m-d H:i:s');

        Shop::Container()->getDB()->insert('postfinancecheckout_refunds', $newRefund);
    }
}

