<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Cart\CartItem;
use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Checkout\Zahlungsart;
use JTL\Helpers\PaymentMethod;
use JTL\Plugin\Payment\Method;
use JTL\Plugin\Plugin;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;
use stdClass;
use PostFinanceCheckout\Sdk\ApiClient;
use PostFinanceCheckout\Sdk\Model\{AddressCreate,
    LineItemCreate,
    LineItemType,
    Transaction,
    TransactionCreate,
    TransactionInvoice,
    TransactionPending,
    TransactionState
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

        $obj = Shop::Container()->getDB()->selectSingleRow('tzahlungsart', 'kZahlungsart', (int)$_SESSION['AktiveZahlungsart']);
        $createOrderBeforePayment = (int)$obj->nWaehrendBestellung ?? 0;
        if ($createOrderBeforePayment === 1) {
            $this->createLocalPostFinanceCheckoutTransaction((string)$createdTransaction->getId(), (array)$order);
        }

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

        $orderId = $_SESSION['kBestellung'];
        $orderNr = $_SESSION['BestellNr'];

        $obj = Shop::Container()->getDB()->selectSingleRow('tzahlungsart', 'kZahlungsart', (int)$_SESSION['AktiveZahlungsart']);
        $createOrderBeforePayment = (int)$obj->nWaehrendBestellung ?? 0;
        if ($createOrderBeforePayment === 1) {
            $orderId = null;
            $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
            $orderNr = $orderHandler->createOrderNo();
        } else {
            $order = new Bestellung($orderId);
            $this->createLocalPostFinanceCheckoutTransaction((string)$transactionId, (array)$order);
        }
        $_SESSION['nextOrderNr'] = $orderNr;
        $pendingTransaction->setMetaData([
            'orderId' => $orderId,
            'spaceId' => $this->spaceId,
        ]);

        $pendingTransaction->setMerchantReference($orderNr);

        $this->apiClient->getTransactionService()
            ->confirm($this->spaceId, $pendingTransaction);

        if ($createOrderBeforePayment === 1) {
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
        $pendingTransaction->setVersion($transaction->getVersion() + 1);

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
        $updated = Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['transaction_id'],
                [$transactionId],
                (object)['state' => $newStatus]
            );
        print 'Updated ' . $updated;
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
                    $lineItems[] = $this->createLineItemProductItem($product);
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
        $newTransaction->payment_method = $orderData['cZahlungsartName'];
        $newTransaction->order_id = $orderData['kBestellung'];
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
            $this->updateTransactionStatus($transactionId, TransactionState::FULFILL);
            $paymentMethodEntity = new Zahlungsart((int)$order->kZahlungsart);
            $paymentMethod = new Method($paymentMethodEntity->cModulId);
            $paymentMethod->setOrderStatusToPaid($order);
            $incomingPayment = new stdClass();
            $incomingPayment->fBetrag = $transaction->getAuthorizationAmount();
            $incomingPayment->cISO = $transaction->getCurrency();
            $incomingPayment->cZahlungsanbieter = $order->cZahlungsartName;
            $paymentMethod->addIncomingPayment($order, $incomingPayment);
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
            $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId, TransactionState::AUTHORIZED);
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
    public function updateLocalPostFinanceCheckoutTransaction(string $transactionId, $state = null): void
    {
        if ($state === null) {
            $state = TransactionState::PROCESSING;
        }

        Shop::Container()
            ->getDB()->update(
                'postfinancecheckout_transactions',
                ['transaction_id'],
                [$transactionId],
                (object)[
                    'state' => $state,
                    'payment_method' => $_SESSION['Zahlungsart']->cName,
                    'order_id' => $_SESSION['kBestellung'],
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
    private function createLineItemProductItem(CartItem $productData): LineItemCreate
    {
        $lineItem = new LineItemCreate();
        $name = \is_array($productData->cName) ? $productData->cName[$_SESSION['cISOSprache']] : $productData->cName;
        $lineItem->setName($name);
        $lineItem->setUniqueId($productData->cArtNr);
        $lineItem->setSku($productData->cArtNr);
        $lineItem->setQuantity($productData->nAnzahl);
        preg_match_all('!\d+!', $productData->cGesamtpreisLocalized[0][$_SESSION['cWaehrungName']], $price);
        $priceDecimal = number_format(floatval(($price[0][0] . '.' . $price[0][1])), 2);
        $lineItem->setAmountIncludingTax($priceDecimal);
        $lineItem->setType(LineItemType::PRODUCT);

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
        preg_match_all('!\d+!', $productData->cGesamtpreisLocalized[0][$_SESSION['cWaehrungName']], $price);
        $priceDecimal = number_format(floatval(($price[0][0] . '.' . $price[0][1])), 2);
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

        return $shippingAddress;
    }
}

