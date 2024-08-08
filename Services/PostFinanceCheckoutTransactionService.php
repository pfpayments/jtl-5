<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Alert\Alert;
use JTL\Cart\CartItem;
use JTL\Checkout\Nummern;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Checkout\Zahlungsart;
use JTL\Helpers\PaymentMethod;
use JTL\Helpers\Tax;
use JTL\Mail\Mailer;
use JTL\Mail\Mail\Mail;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutMailService;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{
    AddressCreate,
    Gender,
    LineItemCreate,
    LineItemType,
    TaxCreate,
    Transaction,
    TransactionCreate,
    TransactionInvoice,
    TransactionPending,
    TransactionState,
    CreationEntityState,
    CriteriaOperator,
    EntityQuery,
    EntityQueryFilter,
    EntityQueryFilterType,
    RefundState,
    TransactionInvoiceState,
    WebhookListener,
    WebhookListenerCreate,
    WebhookUrl,
    WebhookUrlCreate,
};

class PostFinanceCheckoutTransactionService
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
     * @var $spaceViewId
     */
    protected $spaceViewId;

    /**
     * @var $plugin
     */
    protected $plugin;

    /**
     * @var $mailService
     *      The email service.
     *
     * @see \Plugin\jtl_postfinancecheckout\Services\PostFinanceCheckoutMailService
     */
    protected $mailService;

    public function __construct(ApiClient $apiClient, $plugin)
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($plugin->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];

        $this->plugin = $plugin;
        $this->apiClient = $apiClient;
        $this->spaceId = $spaceId;
        $this->spaceViewId = $config[PostFinanceCheckoutHelper::SPACE_VIEW_ID];

        $mailer = Shop::Container()->get(Mailer::class);
        $mail = new Mail();
        $db = Shop::Container()->getDB();
        $this->mailService = new PostFinanceCheckoutMailService($mailer, $mail, $db, $config);
    }

    /**
     * @param Bestellung $order
     * @return Transaction
     */
    public function createTransaction(Bestellung $order): Transaction
    {
        $transactionPayload = new TransactionCreate();
        $transactionPayload->setCurrency($_SESSION['cWaehrungName']);
        $transactionPayload->setLanguage(PostFinanceCheckoutHelper::getLanguageString());
        $transactionPayload->setLineItems($this->getLineItems($order->Positionen));
        $transactionPayload->setBillingAddress($this->createBillingAddress());
        $transactionPayload->setShippingAddress($this->createShippingAddress());

        $transactionPayload->setMetaData([
            'spaceId' => $this->spaceId,
        ]);

        if (!empty($this->spaceViewId)) {
            $transactionPayload->setSpaceViewId($this->spaceViewId);
        }
        $transactionPayload->setAutoConfirmationEnabled(getenv('POSTFINANCECHECKOUT_AUTOCONFIRMATION_ENABLED') ?: false);

        $successUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['thank-you-page'][$_SESSION['cISOSprache']];
        $failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];

        $customer = $_SESSION['Kunde'];
        $customerEmail = $customer->cMail ?? '';
        $customerId = $customer->kKunde ?? '';

        $transactionPayload->setSuccessUrl($successUrl)
            ->setFailedUrl($failedUrl)
            ->setCustomerEmailAddress($customerEmail)
            ->setCustomerId($customerId);

        $createdTransaction = $this->apiClient->getTransactionService()->create($this->spaceId, $transactionPayload);

        return $createdTransaction;
    }

    /**
     * @param Transaction $transaction
     * @return void
     */
    public function confirmTransaction(Transaction $transaction): void
    {
        $transactionId = $transaction->getId();
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);
        $pendingTransaction->setVersion($transaction->getVersion());

        $lineItems = $this->getLineItems($_SESSION['Warenkorb']->PositionenArr);
        $pendingTransaction->setLineItems($lineItems);
        $pendingTransaction->setCurrency($_SESSION['cWaehrungName']);
        $pendingTransaction->setLanguage(PostFinanceCheckoutHelper::getLanguageString());

        $obj = Shop::Container()->getDB()->selectSingleRow('tzahlungsart', 'kZahlungsart', (int)$_SESSION['AktiveZahlungsart']);
        $createOrderAfterPayment = (int)$obj->nWaehrendBestellung ?? 1;

        $orderNumber = null;
        if ($createOrderAfterPayment === 1) {
            $orderId = null;
            if ($this->isPreventFromDuplicatedOrders()) {
                [$orderNr, $orderNumber] = PostFinanceCheckoutHelper::createOrderNo();
                $transactionByOrderReference = $this->getTransactionByOrderReference($orderNr);
                
                if ($transactionByOrderReference) {
                    [$orderNr, $orderNumber] = PostFinanceCheckoutHelper::createOrderNo();
                    $pendingTransaction->setVersion($transaction->getVersion() + 1);
                    $pendingTransaction->setMerchantReference($orderNr);
                    $this->apiClient->getTransactionService()->update($this->spaceId, $pendingTransaction);
                }
            } else {
                [$orderNr, $orderNumber] = PostFinanceCheckoutHelper::createOrderNo(false);
            }

            $order = new \stdClass();
            $order->transaction_id = $transactionId;
            $order->data = json_encode([]);
            $order->payment_method = $_SESSION['possiblePaymentMethodName'];
            $order->order_id = null;
            $order->space_id = $this->spaceId;
            $order->state = TransactionState::PENDING;
            $order->created_at = date('Y-m-d H:i:s');
            $this->createLocalPostFinanceCheckoutTransaction((string)$transactionId, (array)$order);
        } else {
            $orderId = $_SESSION['kBestellung'] ?? $_SESSION['oBesucher']->kBestellung;
            $orderNr = $_SESSION['BestellNr'] ?? $_SESSION['nextOrderNr'];

            $order = new \stdClass();
            $order->transaction_id = $transactionId;
            $order->data = json_encode((array)$order);
            $order->payment_method = $_SESSION['possiblePaymentMethodName'];
            $order->order_id = $orderId;
            $order->space_id = $this->spaceId;
            $order->state = TransactionState::PENDING;
            $order->created_at = date('Y-m-d H:i:s');

            $this->createLocalPostFinanceCheckoutTransaction((string)$transactionId, (array)$order);
        }

        $pendingTransaction->setMetaData([
            'orderId' => $orderId,
            'spaceId' => $this->spaceId,
            'orderAfterPayment' => $createOrderAfterPayment,
            'order_nr' => $orderNr,
            'order_no' => $orderNumber
        ]);

        $pendingTransaction->setMerchantReference($orderNr);
        $successUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['thank-you-page'][$_SESSION['cISOSprache']];
        $failedUrl = Shop::getURL() . '/' . PostFinanceCheckoutHelper::PLUGIN_CUSTOM_PAGES['fail-page'][$_SESSION['cISOSprache']];

        $pendingTransaction->setSuccessUrl($successUrl . '?tID=' . $transactionId);
        $pendingTransaction->setFailedUrl($failedUrl . '?tID=' . $transactionId);


        $this->apiClient->getTransactionService()
            ->confirm($this->spaceId, $pendingTransaction);
        
        if ($createOrderAfterPayment === 1) {
            $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId);
        }
    }

    /**
     * @param string $orderNr
     * @return array
     */
    protected function getTransactionByOrderReference(string $orderNr): array
    {
        $states = [
            TransactionState::CONFIRMED,
            TransactionState::PROCESSING,
            TransactionState::FULFILL,
        ];

        $filters = array_map(function($state) use ($orderNr) {
            return (new EntityQueryFilter())
                ->setType(EntityQueryFilterType::_AND)
                ->setChildren([
                    $this->getEntityFilter('state', $state),
                    $this->getEntityFilter('merchantReference', $orderNr),
                ]);
        }, $states);

        $entityQueryFilter = (new EntityQueryFilter())
            ->setType(EntityQueryFilterType::_OR)
            ->setChildren($filters);

        $query = (new EntityQuery())->setFilter($entityQueryFilter);

        return $this->apiClient->getTransactionService()->search($this->spaceId, $query);
    }

    /**
     * Creates and returns a new entity filter.
     *
     * @param string $fieldName
     * @param        $value
     * @param string $operator
     *
     * @return \PostFinanceCheckout\Sdk\Model\EntityQueryFilter
     */
    protected function getEntityFilter(string $fieldName, $value, string $operator = CriteriaOperator::EQUALS): EntityQueryFilter
    {
        /** @noinspection PhpParamsInspection */
        return (new EntityQueryFilter())
            ->setType(EntityQueryFilterType::LEAF)
            ->setOperator($operator)
            ->setFieldName($fieldName)
            ->setValue($value);
    }

    /**
     * @param Transaction $transaction
     * @return void
     */
    public function updateTransaction(int $transactionId)
    {
        $pendingTransaction = new TransactionPending();
        $transaction = $this->getTransactionFromPortal($transactionId);
        $statesToUpdate = [
          TransactionState::DECLINE,
          TransactionState::FAILED,
          TransactionState::VOIDED,
          TransactionState::PROCESSING
        ];
        if (empty($transaction) || empty($transaction->getVersion()) || in_array($transaction->getState(), $statesToUpdate)) {
          $_SESSION['transactionId'] = null;
          $translations = PostFinanceCheckoutHelper::getTranslations($this->plugin->getLocalization(), [
            'jtl_postfinancecheckout_transaction_timeout',
            ]);

            Shop::Container()->getAlertService()->addAlert(
            Alert::TYPE_ERROR,
            $translations['jtl_postfinancecheckout_transaction_timeout'],
            'updateTransaction_transaction_timeout'
            );

            $linkHelper = Shop::Container()->getLinkService();
            \header('Location: ' . $linkHelper->getStaticRoute('bestellvorgang.php') . '?editZahlungsart=1');
            exit;
        }

        $pendingTransaction->setId($transactionId);
        $pendingTransaction->setVersion($transaction->getVersion());

        $lineItems = $this->getLineItems($_SESSION['Warenkorb']->PositionenArr);
        $pendingTransaction->setLineItems($lineItems);
        $billingAddress = $this->createBillingAddress();
        $shippingAddress = $this->createShippingAddress();

        $pendingTransaction->setCurrency($_SESSION['cWaehrungName']);
        $pendingTransaction->setLanguage(PostFinanceCheckoutHelper::getLanguageString());
        $pendingTransaction->setBillingAddress($billingAddress);
        $pendingTransaction->setShippingAddress($shippingAddress);

        return $this->apiClient->getTransactionService()
            ->update($this->spaceId, $pendingTransaction);
    }

    /**
     * @param string $transactionId
     * @param int $spaceId
     * @return mixed|null
     */
    public function getTransactionPaymentMethod(int $transactionId, string $spaceId)
    {
        $possiblePaymentMethods = $this->apiClient
            ->getTransactionService()
            ->fetchPaymentMethods(
                $spaceId,
                $transactionId,
                'iframe'
            );

        $chosenPaymentMethod = \strtolower($_SESSION['Zahlungsart']->cModulId);
        foreach ($possiblePaymentMethods as $possiblePaymentMethod) {
            if (PostFinanceCheckoutHelper::PAYMENT_METHOD_PREFIX . '_' . $possiblePaymentMethod->getId() === $chosenPaymentMethod) {
                return $possiblePaymentMethod;
            }
        }

        return null;
    }

    public function completePortalTransaction($transactionId): void
    {
        $this->apiClient
            ->getTransactionCompletionService()
            ->completeOnline($this->spaceId, $transactionId);
    }

    public function cancelPortalTransaction($transactionId): void
    {
        $this->apiClient
            ->getTransactionVoidService()
            ->voidOnline($this->spaceId, $transactionId);
    }

    /**
     * @param $transactionId
     * @return Transaction|null
     */
    public function getTransactionFromPortal($transactionId): ?Transaction
    {
        return $this->apiClient
            ->getTransactionService()
            ->read($this->spaceId, $transactionId);
    }

    /**
     * @param string $transactionId
     * @return TransactionInvoice|null
     */
    public function getTransactionInvoiceFromPortal(string $transactionId): ?TransactionInvoice
    {
        return $this->apiClient
            ->getTransactionInvoiceService()
            ->read($this->spaceId, $transactionId);
    }

    public function fetchPossiblePaymentMethods(string $transactionId)
    {
        return $this->apiClient->getTransactionService()
            ->fetchPaymentMethods($this->spaceId, $transactionId, 'iframe');
    }

    public function updateTransactionStatus($transactionId, $newStatus)
    {
        Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['transaction_id'],
                [$transactionId],
                (object)['state' => $newStatus]
            );
    }

    /**
     * @param string $transactionId
     * @return stdClass|null
     */
    public function getLocalPostFinanceCheckoutTransactionById(string $transactionId): ?\stdClass
    {
        return Shop::Container()->getDB()->getSingleObject(
            'SELECT * FROM postfinancecheckout_transactions WHERE transaction_id = :transaction_id ORDER BY id DESC LIMIT 1',
            ['transaction_id' => $transactionId]
        );
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function downloadInvoice(string $transactionId): void
    {
        $document = $this->apiClient->getTransactionService()->getInvoiceDocument($this->spaceId, $transactionId);
        if ($document) {
            $this->downloadDocument($document);
        }
    }

    /**
     * @param string $transactionId
     * @return void
     */
    public function downloadPackagingSlip(string $transactionId): void
    {
        $document = $this->apiClient->getTransactionService()->getPackingSlip($this->spaceId, $transactionId);
        if ($document) {
            $this->downloadDocument($document);
        }
    }

    /**
     * @param array $products
     * @return array
     */
    public function getLineItems(array $products): array
    {
        $lineItems = [];
        foreach ($products as $product) {
            switch ($product->nPosTyp) {
                case \C_WARENKORBPOS_TYP_VERSANDPOS:
                    $lineItems [] = $this->createLineItemShippingItem($product);
                    break;

                case \C_WARENKORBPOS_TYP_ARTIKEL:
                case \C_WARENKORBPOS_TYP_KUPON:
                case \C_WARENKORBPOS_TYP_GUTSCHEIN:
                case \C_WARENKORBPOS_TYP_ZAHLUNGSART:
                case \C_WARENKORBPOS_TYP_VERSANDZUSCHLAG:
                case \C_WARENKORBPOS_TYP_NEUKUNDENKUPON:
                case \C_WARENKORBPOS_TYP_NACHNAHMEGEBUEHR:
                case \C_WARENKORBPOS_TYP_VERSAND_ARTIKELABHAENGIG:
                case \C_WARENKORBPOS_TYP_VERPACKUNG:
                case \C_WARENKORBPOS_TYP_GRATISGESCHENK:
                default:
                    $isDiscount = false;
                    if (\in_array($product->nPosTyp, [
                        \C_WARENKORBPOS_TYP_KUPON
                    ], true)) {
                        $isDiscount = true;
                    }
                    $lineItems[] = $this->createLineItemProductItem($product, $isDiscount);
            }
        }

        if ($_SESSION['Bestellung']->GuthabenNutzen ?? 0 === 1) {
            if ($_SESSION['Bestellung']->fGuthabenGenutzt > 0) {
                $product = new CartItem();
                $product->cName = 'Voucher';
                $product->cArtNr = 'voucher-customer-credit';
                $product->nAnzahl = 1;
                $product->fPreis = $_SESSION['Bestellung']->fGuthabenGenutzt;

                $lineItems[] = $this->createLineItemProductItem($product, true, true);
            }
        }

        return $lineItems;
    }

    /**
     * @param string $transactionId
     * @param array $orderData
     * @return void
     */
    public function createLocalPostFinanceCheckoutTransaction(string $transactionId, array $orderData): void
    {
        $newTransaction = new \stdClass();
        $newTransaction->transaction_id = $transactionId;
        $newTransaction->data = json_encode($orderData);
        $newTransaction->payment_method = $orderData['payment_method'];
        $newTransaction->order_id = $orderData['order_id'];
        $newTransaction->space_id = $this->spaceId;
        $newTransaction->state = TransactionState::PENDING;
        $newTransaction->created_at = date('Y-m-d H:i:s');

        Shop::Container()->getDB()->insert('postfinancecheckout_transactions', $newTransaction);
    }

    /**
     * @param string $transactionId
     * @param Bestellung $order
     * @param Transaction $transaction
     * @return void
     */
    public function addIncommingPayment(string $transactionId, Bestellung $order, Transaction $transaction): void
    {
        $localTransaction = $this->getLocalPostFinanceCheckoutTransactionById($transactionId);
        if ($localTransaction->state !== TransactionState::FULFILL) {
            $orderId = (int)$order->kBestellung;

            if ($orderId === 0) {
                return;
            }
            $this->updateTransactionStatus($transactionId, TransactionState::FULFILL);

            $portalTransaction = $this->getTransactionFromPortal($transactionId);
            if ($portalTransaction->getState() === TransactionState::FULFILL) {
                // tzahlungseingang - table name of incomming payments
                // kBestellung - table field which represents order ID
                $incommingPayment = Shop::Container()->getDB()->selectSingleRow('tzahlungseingang', 'kBestellung', $orderId);
                // We check if there's record for incomming payment for current order
                if (!empty($incommingPayment->kZahlungseingang)) {
                    return;
                }

                $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
                $moduleId = $paymentMethodEntity->cModulId ?? '';
                $paymentMethod = new Method($moduleId);
                $paymentMethod->setOrderStatusToPaid($order);
                $incomingPayment = new \stdClass();
                $incomingPayment->fBetrag = $transaction->getAuthorizationAmount();
                $incomingPayment->cISO = $transaction->getCurrency();
                $incomingPayment->cZahlungsanbieter = $order->cZahlungsartName;
                $incomingPayment->cHinweis = $transactionId;
                $paymentMethod->addIncomingPayment($order, $incomingPayment);
                
                // At this stage, the transaction goes directly to fulfill, so it's also authorized.
                // Even when the sendEmail is invoked here, the email will be or not sent according to several conditions.
                $this->sendEmail($orderId, 'fulfill');
            }
        }
    }

    /**
     * @param int $transactionId
     * @return int
     */
    public function createOrderAfterPayment(int $transactionId): int
    {
        $_SESSION['finalize'] = true;

        $transaction = $this->getTransactionFromPortal($transactionId);

        $orderNr = $transaction->getMetaData()['order_nr'];
        $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
        
        if ($this->isPreventFromDuplicatedOrders()) {
            // We check if order exist with such order nr. If yes, we select it's data, if not - we create it.
            // This check prevents only in these cases when webhook is triggered more than once or user refresh the page, or
            // got lost internet connection. It's more like catching edge case
            $data = Shop::Container()->getDB()->select(
              'tbestellung',
              'cBestellNr',
              $orderNr,
              null,
              null,
              null,
              null,
              false,
              'kBestellung'
            );
            if ($data !== null && isset($data->kBestellung)) {
                $order = new Bestellung((int)$data->kBestellung);
            } else {
                $order = $orderHandler->finalizeOrder($orderNr, false);
            }
        } else {
            // Updates order number for next order. Increase by 1 if is needed
            $lastOrderNo = $transaction->getMetaData()['order_no'];
            PostFinanceCheckoutHelper::createOrderNo(true, $lastOrderNo);
            $order = $orderHandler->finalizeOrder($orderNr, false);
        }
        $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId, TransactionState::AUTHORIZED, (int)$order->kBestellung);

        if ($transaction->getState() === TransactionState::FULFILL) {
            // fuelleBestellung - JTL5 native function to append all required data to order
            $orderData = $order->fuelleBestellung(true);
            $this->addIncommingPayment((string)$transactionId, $orderData, $transaction);
        }

        return (int)$order->kBestellung;
    }

    /**
     * @param string $transactionId
     * @param array $orderData
     * @return void
     */
    public function updateLocalPostFinanceCheckoutTransaction(string $transactionId, $state = null, $orderId = null): void
    {
        if ($state === null) {
            $state = TransactionState::PROCESSING;
        }

        $data = [
            'state' => $state,
            'space_id' => $this->spaceId,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $paymentMethodName = $_SESSION['possiblePaymentMethodName'];
        if ($paymentMethodName) {
            $data['payment_method'] = $paymentMethodName;
        }

        if ($orderId) {
            $data['order_id'] = $orderId;
        }

        Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['transaction_id'],
                [$transactionId],
                (object)$data
            );
        
        if ($orderId && $state === TransactionState::AUTHORIZED) {
            $this->sendEmail($orderId, 'authorization');
        }
    }

    private function downloadDocument($document)
    {
        $filename = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '_', $document->getTitle()) . '.pdf';
        $filedata = base64_decode($document->getData());
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $document->getMimeType());
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($filedata));
        ob_clean();
        flush();
        echo $filedata;
    }

    /**
     * @param CartItem $productData
     * @return LineItemCreate
     */
    private function createLineItemProductItem(CartItem $productData, $isDiscount = false, $isCustomerCredit = false): LineItemCreate
    {
        $lineItem = new LineItemCreate();
        $name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
        $lineItem->setName($name);

        $slug = strtolower(str_replace([' ', '+', '%', '[', ']', '=>'], ['-', '', '', '', '', '-'], $name));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        $uniqueName = $productData->cArtNr ?: $slug;
        $attributes = $productData->WarenkorbPosEigenschaftArr ?? [];
        if ($isDiscount || $productData->nPosTyp === \C_WARENKORBPOS_TYP_GUTSCHEIN) {
            $uniqueName = $uniqueName . '_' . rand(1, 99999);
        } elseif ($attributes) {
            foreach ($attributes as $attribute) {
                if ($attribute->cTyp !== 'FREIFELD') {
                    continue;
                }
                
                // If there's custom attribute with type TEXT (for example merchant adds his name to be printed on Jacket)
                // them this custom text will be slugified and added to unique id
                // cEigenschaftName - array of attribute name by language
                // cEigenschaftWertName - array of attribute values by language
                $attributeName = strtolower(current($attribute->cEigenschaftName));
                $attributeValue = strtolower($this->slugify((string)current($attribute->cEigenschaftWertName)));
                $uniqueName .= '_' . $attributeName . '_' . $attributeValue;
            }
        }

        $lineItem->setUniqueId($uniqueName);
        $lineItem->setSku($productData->cArtNr);
        $lineItem->setQuantity($productData->nAnzahl);

        $currencyFactor = Frontend::getCurrency()->getConversionFactor();

        if (!$isCustomerCredit) {
            // fPreis is price, nAnzahl is quantity
            $priceDecimal = Tax::getGross(
                $productData->fPreis * $productData->nAnzahl,
                CartItem::getTaxRate($productData)
            );
            $priceDecimal *= $currencyFactor;
            $priceDecimal = (float)number_format($priceDecimal, 2, '.', '');
        } else {
            // For customer credit - do not apply taxes
            $priceDecimal = $productData->fPreis * $productData->nAnzahl;
            $priceDecimal = (float)number_format($priceDecimal, 2, '.', '');
        }

        $type = LineItemType::PRODUCT;
        if ($isDiscount === true) {
            $type = LineItemType::DISCOUNT;
            if ($priceDecimal > 0) {
                $priceDecimal = -1 * $priceDecimal;
            }
        }
        $lineItem->setAmountIncludingTax($priceDecimal);
        $lineItem->setType($type);

        return $lineItem;
    }

    /**
     * @param CartItem $productData
     * @return LineItemCreate
     */
    private function createLineItemShippingItem(CartItem $productData): LineItemCreate
    {
        $lineItem = new LineItemCreate();
        $name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
        $lineItem->setName('Shipping: ' . $name);
        $lineItem->setUniqueId('shipping: ' . $name);
        $lineItem->setSku('shipping: ' . $name);
        $lineItem->setQuantity(1);
        $currencyFactor = Frontend::getCurrency()->getConversionFactor();
        $priceDecimal = Tax::getGross(
            $productData->fPreis * $productData->nAnzahl,
            CartItem::getTaxRate($productData)
        );
        $priceDecimal *= $currencyFactor;
        $priceDecimal = (float)number_format($priceDecimal, 2, '.', '');

        $lineItem->setAmountIncludingTax($priceDecimal);
        $lineItem->setType(LineItemType::SHIPPING);

        return $lineItem;
    }
    
    /**
     * @param string $text
     * @return string
     */
    private function slugify(string $text): string {
        // Replace non-letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        // Transliterate to ASCII
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);
        // Trim
        $text = trim($text, '-');
        // Remove duplicate -
        $text = preg_replace('~-+~', '-', $text);
        // Lowercase
        $text = strtolower($text);
        // Return the slug
        return !empty($text) ? $text : 'n-a';
    }

    /**
     * @return AddressCreate
     */
    private function createBillingAddress(): AddressCreate
    {
        $customer = $_SESSION['Kunde'];

        $billingAddress = new AddressCreate();
        $billingAddress->setStreet($customer->cStrasse . ' ' . $customer->cHausnummer);
        $billingAddress->setCity($customer->cOrt);
        $billingAddress->setCountry($customer->cLand);
        $billingAddress->setEmailAddress($customer->cMail);
        $billingAddress->setFamilyName($customer->cNachname);
        $billingAddress->setGivenName($customer->cVorname);
        $billingAddress->setPostCode($customer->cPLZ);
        $billingAddress->setPostalState($customer->cBundesland);
        $billingAddress->setOrganizationName($customer->cFirma);
        $billingAddress->setPhoneNumber($customer->cMobil);

        $company = $customer->cFirma ?? null;
        if ($company) {
            $billingAddress->setOrganizationName($company);
        }

        $mobile = $customer->cMobil ?? null;
        if ($mobile) {
            $billingAddress->setMobilePhoneNumber($mobile);
        }

        $phone = $customer->cTel ?? $mobile;
        if ($phone) {
            $billingAddress->setPhoneNumber($phone);
        }

        $birthDate = $customer->dGeburtstag_formatted ?? null;
        if (!empty($birthDate) && strtotime($birthDate) !== false) {
            $birthday = new \DateTime();
            $birthday->setTimestamp(strtotime($birthDate));
            $birthday = $birthday->format('Y-m-d');
            $billingAddress->setDateOfBirth($birthday);
        }

        $gender = $_SESSION['orderData']?->oKunde?->cAnrede ?? '';
        if ($gender !== null) {
            $billingAddress->setGender($gender === 'm' ? Gender::MALE : Gender::FEMALE);
            $billingAddress->setSalutation($gender === 'm' ? 'Mr' : 'Ms');
        }

        return $billingAddress;
    }

    /**
     * @return AddressCreate
     */
    private function createShippingAddress(): AddressCreate
    {
        $customer = $_SESSION['Lieferadresse'];

        $shippingAddress = new AddressCreate();
        $shippingAddress->setStreet($customer->cStrasse . ' ' . $customer->cHausnummer);
        $shippingAddress->setCity($customer->cOrt);
        $shippingAddress->setCountry($customer->cLand);
        $shippingAddress->setEmailAddress($customer->cMail);
        $shippingAddress->setFamilyName($customer->cNachname);
        $shippingAddress->setGivenName($customer->cVorname);
        $shippingAddress->setPostCode($customer->cPLZ);
        $shippingAddress->setPostalState($customer->cBundesland);
        $shippingAddress->setOrganizationName($customer->cFirma);
        $shippingAddress->setPhoneNumber($customer->cMobil);
        $shippingAddress->setSalutation($customer->cTitel);

        $gender = $_SESSION['orderData']?->oKunde?->cAnrede ?? null;
        if ($gender !== null) {
            $shippingAddress->setGender($gender === 'm' ? Gender::MALE : Gender::FEMALE);
            $shippingAddress->setSalutation($gender === 'm' ? 'Mr' : 'Ms');
        }

        return $shippingAddress;
    }
    
    /**
     * @return bool
     */
    private function isPreventFromDuplicatedOrders(): bool
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($this->plugin->getId());
        $preventFromDuplicatedOrders = $config[PostFinanceCheckoutHelper::PREVENT_FROM_DUPLICATED_ORDERS] ?? null;

        return $preventFromDuplicatedOrders === 'YES';
    }

    /**
     * Sends the email for this transaction.
     *
     * @param int $orderId
     *     The order id.
     * @param string $template
     *     The template to use. Currently only 'authorization' and 'fulfill' values are supported.
     */
    public function sendEmail(int $orderId, string $template) {
        if (!$this->mailService->isEmailSent($orderId, $template)) {
            $this->mailService->sendMail($orderId, $template);
        }
    }
}

