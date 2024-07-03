<?php

declare(strict_types=1);

namespace Plugin\jtl_postfinancecheckout\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20240613200403 extends Migration implements IMigration
{
    protected $description = 'Add authorization and fulfill fields to the postfinancecheckout_transactions table';

    /**
     * @inheritdoc
     */
    public function up()
    {
        $this->execute("ALTER TABLE `postfinancecheckout_transactions`
                CHANGE COLUMN `confirmation_email_sent` `authorization_email_sent` TINYINT(1) NOT NULL DEFAULT 0");
        $this->execute("ALTER TABLE `postfinancecheckout_transactions`
                ADD COLUMN `fulfill_email_sent` tinyint(1) NOT NULL DEFAULT '0'
                AFTER `authorization_email_sent`;");
    }

    /**
     * @inheritdoc
     */
    public function down()
    {
        $this->execute("ALTER TABLE `postfinancecheckout_transactions`
                CHANGE COLUMN `authorization_email_sent` `confirmation_email_sent` TINYINT(1) NOT NULL DEFAULT 0");
        $this->execute("ALTER TABLE `postfinancecheckout_transactions` DROP COLUMN `fulfill_email_sent`;");
    }
}
