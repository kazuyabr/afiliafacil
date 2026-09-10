<?php

use Phinx\Migration\AbstractMigration;

class CreatePlans extends AbstractMigration
{
    public function change(): void
    {
        $this->table('plans', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'string', ['limit' => 40])
            ->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('label', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('features', 'json', ['null' => true])
            ->addColumn('max_pages', 'integer', ['default' => 1])
            ->addColumn('max_domains', 'integer', ['default' => 0])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->create();

        $this->table('plan_prices')
            ->addColumn('plan_id', 'string', ['limit' => 40])
            ->addColumn('cycle', 'string', ['limit' => 20])
            ->addColumn('amount', 'integer')
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['plan_id', 'cycle'], ['unique' => true])
            ->create();
    }
}
