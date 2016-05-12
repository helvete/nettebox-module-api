<?php

use Phinx\Migration\AbstractMigration;

class RenameAccountNotificationTable extends AbstractMigration
{
	/**
	 * Change Method.
	 *
	 * Write your reversible migrations using this method.
	 *
	 * More information on writing migrations is available here:
	 * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
	 */
	public function change()
	{
		$tbl = $this->table('account_notification');
		$tbl->rename('account_device');
	}
}
