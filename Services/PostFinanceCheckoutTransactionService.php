<?php declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Alert\Alert;
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
    TaxCreate,
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
 
    /**
     * @var $plugin
     */
    protected $plugin;

    public function __construct(ApiClient $apiClient, $plugin)
    {
        $config = PostFinanceCheckoutHelper::getConfigByID($plugin->getId());
        $spaceId = $config[PostFinanceCheckoutHelper::SPACE_ID];

        $this->plugin = $plugin;
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

        $customer = $_SESSION['Kunde'];
        $customerEmail = $customer->cMail ?? '';
        $customerId = $customer->kKunde ?? '';

        $transactionPayload->setSuccessUrl($successUrl)
          ->setFailedUrl($failedUrl)
          ->setCustomerEmailAddress($customerEmail)
          ->setCustomerId($customerId);

        $orderNr = PostFinanceCheckoutHelper::getNextOrderNr();
        $transactionPayload->setMerchantReference($orderNr);

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
            $orderNr = PostFinanceCheckoutHelper::getNextOrderNr();

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
            'orderAfterPayment' => $createOrderAfterPayment
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
     * @param Transaction $transaction
     * @return void
     */
    public function updateTransaction(int $transactionId)
    {
        $pendingTransaction = new TransactionPending();
        $transaction = $this->getTransactionFromPortal($transactionId);
        $failedStates = [
          TransactionState::DECLINE,
          TransactionState::FAILED,
          TransactionState::VOIDED,
        ];
        if (empty($transaction) || empty($transaction->getVersion()) || in_array($transaction->getState(), $failedStates)) {
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
     * @param int $transactionId
     * @return int
     */
    public function createOrderAfterPayment(int $transactionId): int
    {
        $_SESSION['finalize'] = true;
        $orderHandler = new OrderHandler(Shop::Container()->getDB(), Frontend::getCustomer(), Frontend::getCart());
        $orderNr = $orderHandler->createOrderNo();
        $order = $orderHandler->finalizeOrder($orderNr);

        $this->updateLocalPostFinanceCheckoutTransaction((string)$transactionId, TransactionState::AUTHORIZED, (int)$order->kBestellung);
        $transaction = $this->getTransactionFromPortal($transactionId);
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
        if ($isDiscount || $productData->nPosTyp === \C_WARENKORBPOS_TYP_GUTSCHEIN) {
            $uniqueName = $uniqueName . '_' . rand(1, 99999);
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
        if ($birthDate) {
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
}

