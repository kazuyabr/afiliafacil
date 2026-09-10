<?php

use Phinx\Migration\AbstractMigration;

class CreatePayments extends AbstractMigration
{
    public function change(): void
    {
        $this->table('payments', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => false])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('user_email', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('plan_id', 'string', ['limit' => 40])
            ->addColumn('cycle', 'string', ['limit' => 20, 'default' => 'monthly'])
            ->addColumn('amount', 'integer')
            ->addColumn('gateway', 'string', ['limit' => 20, 'default' => 'pix'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('reference', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('paid_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'])
            ->addIndex(['status'])
            ->create();
    }
}
