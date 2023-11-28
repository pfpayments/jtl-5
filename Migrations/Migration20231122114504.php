<?php

namespace Plugin\jtl_postfinancecheckout\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20231122114504 extends Migration implements IMigration
{
    public function up()
    {
        $this->execute("ALTER TABLE `postfinancecheckout_transactions` DROP COLUMN `amount`;");
    }

    public function down()
    {
        $this->execute("ALTER TABLE `postfinancecheckout_transactions`
                    ADD COLUMN `amount` double NOT NULL AFTER `order_id`;");
    }
}
