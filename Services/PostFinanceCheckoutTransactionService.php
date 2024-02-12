<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Cart\CartItem;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Checkout\Zahlungsart;
use JTL\Helpers\PaymentMethod;
use JTL\Helpers\Tax;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use stdClass;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{AddressCreate,
    Gender,
    LineItemCreate,
    LineItemType,
    Transaction,
    TransactionCreate,
    TransactionInvoice,
    TransactionPending,
    TransactionState};

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

    public function __construct(ApiClient $apiClient, $plugin)
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($plugin->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];

        $this->apiClient = $apiClient;
        $this->spaceId = $spaceId;
        $this->spaceViewId = $config[PostFinanceCheckoutHelper::SPACE_VIEW_ID];
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

        $transactionPayload->setSuccessUrl($successUrl);
        $transactionPayload->setFailedUrl($failedUrl);

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

        if ($createOrderAfterPayment === 1) {
            $orderId = null;
            $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
            $orderNr = $orderHandler->createOrderNo();

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
        $_SESSION['orderId'] = $orderId;

        $_SESSION['nextOrderNr'] = $orderNr;
        $pendingTransaction->setMetaData([
            'orderId' => $orderId,
            'spaceId' => $this->spaceId,
        ]);

        $pendingTransaction->setMerchantReference($orderNr);
        $this->apiClient->getTransactionService()
            ->confirm($this->spaceId, $pendingTransaction);

        if ($createOrderAfterPayment === 1) {
            $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId);
        }
    }

    /**
     * @param Transaction $transaction
     * @return void
     */
    public function updateTransaction(int $transactionId)
    {
        $pendingTransaction = new TransactionPending();
        $pendingTransaction->setId($transactionId);

        $transaction = $this->getTransactionFromPortal($transactionId);
        if (empty($transaction) || empty($transaction->getVersion())) {
            $_SESSION['transactionId'] = null;
            $createdTransactionId = $this->createTransaction();
            $_SESSION['transactionId'] = $createdTransactionId;
            return;
        }
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
    public function getLocalPostFinanceCheckoutTransactionById(string $transactionId): ?stdClass
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
            $_SESSION['orderId'] = (int)$order->kBestellung;
            $this->updateTransactionStatus($transactionId, TransactionState::FULFILL);

            $portalTransaction = $this->getTransactionFromPortal($transactionId);
            if ($portalTransaction->getState() === TransactionState::FULFILL) {
                $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
                $moduleId = $paymentMethodEntity->cModulId ?? '';
                $paymentMethod = new Method($moduleId);
                $paymentMethod->setOrderStatusToPaid($order);
                $incomingPayment = new stdClass();
                $incomingPayment->fBetrag = $transaction->getAuthorizationAmount();
                $incomingPayment->cISO = $transaction->getCurrency();
                $incomingPayment->cZahlungsanbieter = $order->cZahlungsartName;
                $incomingPayment->cHinweis = $transactionId;
                $paymentMethod->addIncomingPayment($order, $incomingPayment);
            }
        }
    }

    /**
     * @return void
     */
    public function createOrderAfterPayment(): void
    {
        $_SESSION['finalize'] = true;
        $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
        $order = $orderHandler->finalizeOrder($_SESSION['nextOrderNr']);
        $orderData = $order->fuelleBestellung(true);
        $_SESSION['orderData'] = $orderData;

        $transactionId = $_SESSION['transactionId'] ?? null;

        if ($transactionId) {
            $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId, TransactionState::AUTHORIZED, (int)$order->kBestellung);
            $transaction = $this->getTransactionFromPortal($transactionId);
            if ($transaction->getState() === TransactionState::FULFILL) {
                $this->addIncommingPayment((string)$transactionId, $orderData, $transaction);
            }
        }
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

        if ($orderId === null) {
            $orderId = $_SESSION['orderId'];
        }

        Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['transaction_id'],
                [$transactionId],
                (object)[
                    'state' => $state,
                    'payment_method' => $_SESSION['possiblePaymentMethodName'],
                    'order_id' => $orderId,
                    'space_id' => $this->spaceId,
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );
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
    private function createLineItemProductItem(CartItem $productData, $isDiscount = false): LineItemCreate
    {

        $lineItem = new LineItemCreate();
        $name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
        $lineItem->setName($name);

        $slug = strtolower(str_replace([' ', '+', '%', '[', ']', '=>'], ['-', '', '', '', '', '-'], $name));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        $lineItem->setUniqueId($productData->cArtNr ?: $slug);
        $lineItem->setSku($productData->cArtNr);
        $lineItem->setQuantity($productData->nAnzahl);

        $currencyFactor = Frontend::getCurrency()->getConversionFactor();
        $priceDecimal = Tax::getGross(
            $productData->fPreis * $productData->nAnzahl,
            CartItem::getTaxRate($productData)
        );
        $priceDecimal *= $currencyFactor;
        $priceDecimal = (float)number_format($priceDecimal, 2, '.', '');

        if ($priceDecimal > 0 && $isDiscount) {
            $priceDecimal = -1 * $priceDecimal;
        }
        $lineItem->setAmountIncludingTax($priceDecimal);

        $type = LineItemType::PRODUCT;
        if ($isDiscount) {
            $type = LineItemType::DISCOUNT;
        }
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
     * @return AddressCreate
     */
    private function createBillingAddress(): AddressCreate
    {
        $customer = $_SESSION['Kunde'];

        $billingAddress = new AddressCreate();
        $billingAddress->setStreet($customer->cStrasse);
        $billingAddress->setCity($customer->cOrt);
        $billingAddress->setCountry($customer->cLand);
        $billingAddress->setEmailAddress($customer->cMail);
        $billingAddress->setFamilyName($customer->cNachname);
        $billingAddress->setGivenName($customer->cVorname);
        $billingAddress->setPostCode($customer->cPLZ);
        $billingAddress->setPostalState($customer->cBundesland);
        $billingAddress->setOrganizationName($customer->cFirma);
        $billingAddress->setPhoneNumber($customer->cMobil);
        $billingAddress->setSalutation($customer->cTitel);

        $gender = $_SESSION['orderData']?->oKunde?->cAnrede ?? null;
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
        $shippingAddress->setStreet($customer->cStrasse);
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
}

