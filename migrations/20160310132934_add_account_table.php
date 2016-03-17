<?php

use Phinx\Migration\AbstractMigration;

class AddAccountTable extends AbstractMigration
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
		$table = $this->table('account');
		$table
			->addColumn('email', 'string', array('limit' => 255))
			->addColumn('password', 'string', array('limit' => 255))
			->addColumn('name', 'string', array('limit' => 64, 'null' => true))
			->addColumn('date_of_birth', 'date', array('null' => true))
			->addColumn('gender', 'enum', array('values' => array('FEMALE', 'MALE'), 'null' => true))
			->addColumn('hometown', 'string', array('limit' => 64, 'null' => true))
			->addColumn('created', 'datetime')
			->addColumn('recovery_hash', 'string', array('limit' => 64, 'null' => true))
			->addColumn('recovery_expires_at', 'datetime', array('null' => true))
			->addColumn('state', 'enum', array('values' => array('NEW', 'WAITING_FOR_ACTIVATION', 'ACTIVE'), 'default' => 'NEW'))
			->addColumn('activation_hash', 'string', array('limit' => 64, 'null' => true))
			->addColumn('activation_email_sent', 'datetime', array('null' => true))
			->addColumn('referral_code', 'string', array('limit' => 8, 'null' => true))
			->addColumn('inviter_account_id', 'integer', array('null' => true))
			->addColumn('last_seen', 'datetime', array('null' => true))
			->addColumn('registration_source', 'enum', array('values' => array('APP', 'WEB'), 'default' => 'APP'))
			->addColumn('country_code', 'string', array('limit' => 2, 'null' => true))
			->addColumn('avatar_url', 'string', array('limit' => 2048, 'null' => true))
			->addColumn('facebook_id', 'string', array('limit' => 24, 'null' => true))
			->addColumn('facebook_connected', 'boolean', array('default' => false))
			->addColumn('system_notifications_enabled', 'boolean', array('default' => true))
			->addIndex('email', array('unique' => true))
			->addIndex('recovery_hash', array('unique' => true))
			->addForeignKey('inviter_account_id', 'account', 'id')
			->create();
	}
}
