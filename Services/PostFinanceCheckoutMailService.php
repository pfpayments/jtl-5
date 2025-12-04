<?php

declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Services;

use JTL\Cart\CartHelper;
use JTL\Catalog\Product\Preise;
use JTL\Checkout\Bestellung;
use JTL\Checkout\OrderHandler;
use JTL\Customer\Customer;
use JTL\DB\DbInterface;
use JTL\Helpers\Order;
use JTL\Mail\Mail\Mail;
use JTL\Mail\Mailer;
use JTL\Session\Frontend;
use JTL\Shop;
use Plugin\jtl_postfinancecheckout\PostFinanceCheckoutHelper;

/**
 * Service for sending emails related to PostFinanceCheckout.
 */
class PostFinanceCheckoutMailService {

    /**
     * @var Mailer $mailer
     */
    protected $mailer;

    /**
     * @var Mail $mail
     */
    protected $mail;

    /**
     * @var DbInterface $db
     */
    protected $db;

    /**
     * @var array $pluginConfig
     */
    protected array $pluginConfig;

    protected $emailTemplates = [
        'authorization' => \MAILTEMPLATE_BESTELLBESTAETIGUNG,
        'fulfill' => \MAILTEMPLATE_BESTELLUNG_BEZAHLT,
    ];

    /**
     * Constructor for PostFinanceCheckoutAbstractMailTemplate.
     *
     * @param Mailer $mailer The mailer instance.
     * @param Mail $mail The mail instance.
     * @param DbInterface $db The database instance.
     */
    public function __construct(Mailer $mailer, Mail $mail, DbInterface $db, array $pluginConfig) {
        $this->mailer = $mailer;
        $this->mail = $mail;
        $this->db = $db;
        $this->pluginConfig = $pluginConfig;
    }

    /**
     * Validates the template.
     *
     * @param string $template
     *     The template to validate. Currently only 'authorization' and 'fulfill' values are supported.
     * @return void
     * @throws \Exception If the template is invalid.
     */
    protected function validateTemplate(string $template) {
        if (!isset($this->emailTemplates[$template])) {
            throw new \Exception("Invalid template: $template");
        }
    }

    /**
     * Sends an email for the specified order.
     *
     * @param int $orderId The ID of the order.
     * @return void
     */
    public function sendMail(int $orderId, string $template): void {
        try {
            $data = $this->prepareData($orderId);
            $this->send($data, $template);
        } catch (\Exception $e) {
            error_log("Error sending mail for template : " . $template . " : " . $e->getMessage());
        }
    }

    /**
     * Prepares the data for the email.
     *
     * @param int $orderId The ID of the order.
     * @return stdClass The data prepared for the email.
     */
    protected function prepareData(int $orderId): \stdClass {
        $order  = (new Bestellung($orderId, false, $this->db))->fuelleBestellung(false);
        $helper = new Order($order);
        $amount = $helper->getTotal(4);

        $customer = new Customer($order->kKunde, null, $this->db);

        // ------------------------------
        // REAL availability detection
        // ------------------------------
        $availability = [
            'cArtikelName_arr' => [],
            'cHinweis'         => '',
        ];

        foreach ($order->Positionen as $pos) {

            if ($pos->nPosTyp != \C_WARENKORBPOS_TYP_ARTIKEL) {
                continue;
            }

            $art = $pos->Artikel ?? null;
            if (!$art) {
                continue;
            }

            // Stock must be observed
            if ((strtoupper($art->cLagerBeachten) ?? 'N') !== 'Y') {
                continue;
            }

            // If qty > stock → mark as unavailable
            if ($pos->nAnzahl > ($art->fLagerbestand ?? 0)) {
                $availability['cArtikelName_arr'][] = $art->cName;
            }
        }

        if (!empty($availability['cArtikelName_arr'])) {
            $availability['cHinweis'] = Shop::Lang()->get('orderExpandInventory', 'basket');
        }

        // Safety
        if (!is_array($availability['cArtikelName_arr'])) {
            $availability['cArtikelName_arr'] = [];
        }
        if (!is_string($availability['cHinweis'])) {
            $availability['cHinweis'] = '';
        }

        // ------------------------------

        $data = new \stdClass();
        $data->cVerfuegbarkeit_arr = $availability;
        $data->tkunde              = $customer;
        $data->tbestellung         = $order;
        $data->payments            = $order->getIncommingPayments(false);
        $data->totalLocalized      = Preise::getLocalizedPriceWithoutFactor(
            $amount->total[CartHelper::GROSS],
            $amount->currency,
            false
        );

        return $data;
    }

    /**
     * Sends the email using the prepared data.
     *
     * @param stdClass $data The data prepared for the email.
     * @param string $template
     *     The template to use. Currently only 'authorization' and 'fulfill' values are supported.
     * @return void
     */
    protected function send(\stdClass $data, string $template): void {
        if ($this->canSendEmail($data, $template)) {
            $this->validateTemplate($template);
            $this->mailer->send($this->mail->createFromTemplateID($this->emailTemplates[$template], $data));
        }
    }

    /**
     * Decides if an email can be sent for this template.
     *
     * @param stdClass $data
     *     The data prepared for the email.
     * @param string $template
     *     The template to use. Currently only 'authorization' and 'fulfill' values are supported.
     * @return bool True if the email can be sent, false otherwise.
     */
    protected function canSendEmail(\stdClass $data, string $template): bool {
        if ($template == 'authorization') {
            $sendEmail = $this->pluginConfig[PostFinanceCheckoutHelper::SEND_AUTHORIZATION_EMAIL] ?? null;

            return $sendEmail === 'YES' && !empty($data->tkunde->cMail);
        }
        elseif ($template == 'fulfill') {
            $sendEmail = $this->pluginConfig[PostFinanceCheckoutHelper::SEND_FULFILL_EMAIL] ?? null;

            return $sendEmail === 'YES' && !empty($data->tkunde->cMail)
                && ($data->tbestellung->Zahlungsart->nMailSenden & \ZAHLUNGSART_MAIL_EINGANG);
        }

        return FALSE;
    }

}
