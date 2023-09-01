<?php

namespace Plugin\jtl_postfinancecheckout\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20230803153120 extends Migration implements IMigration
{
	public function up()
	{
		$this->execute("CREATE TABLE `postfinancecheckout_refunds` (
		    `id` int(11) NOT NULL AUTO_INCREMENT,
		    `refund_id` int(11) NOT NULL,
		    `order_id` int(11) NOT NULL,
		    `amount` double NOT NULL,
		    `created_at` datetime NOT NULL ON UPDATE CURRENT_TIMESTAMP,
		    PRIMARY KEY (`id`),
		    UNIQUE KEY `uid_refund_id_order_id` (`refund_id`,`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
		
		$this->execute("CREATE TABLE `postfinancecheckout_transactions` (
		    `id` int(11) NOT NULL AUTO_INCREMENT,
		    `transaction_id` varchar(16) NOT NULL,
		    `amount` double NOT NULL,
		    `confirmation_email_sent` tinyint(1) NOT NULL DEFAULT '0',
		    `data` json NOT NULL,
		    `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
		    `order_id` varchar(16) NOT NULL,
		    `space_id` int unsigned NOT NULL,
		    `state` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
		    `created_at` datetime(3) NOT NULL,
		    `updated_at` datetime(3) DEFAULT NULL,
		    PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
	}
	
	public function down()
	{
		$this->execute('DROP TABLE IF EXISTS `postfinancecheckout_transactions`');
		$this->execute('DROP TABLE IF EXISTS `postfinancecheckout_refunds`');
	}
}
