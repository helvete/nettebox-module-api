<?php

use Phinx\Migration\AbstractMigration;

class CreateAccountTables extends AbstractMigration
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
		// account token sessions
		$table = $this->table('account_api_session', array(
			'id' => false,
			'primary_key' => array('token', 'account_id')
		));
		$table
			->addColumn('account_id', 'integer')
			->addColumn('token', 'string', array('limit' => 64))
			->addColumn('created', 'datetime')
			->addForeignKey('account_id', 'account', 'id')
			->create();

		// account notification application instances (formerly user_device)
		$table = $this->table('account_notification');
		$table
			->addColumn('account_id', 'integer')
			->addColumn('hash', 'string', array('limit' => 255))
			->addColumn('is_apple', 'boolean', array('null' => true))
			->addColumn('active', 'boolean', array('default' => true))
			->addForeignKey('account_id', 'account', 'id')
			->create();

	}
}
