<?php

use Phinx\Migration\AbstractMigration;

class CreatePages extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pages', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'biginteger', ['identity' => false])
            ->addColumn('user_id', 'biginteger', ['default' => 1])
            ->addColumn('name', 'string', ['limit' => 190])
            ->addColumn('slug', 'string', ['limit' => 190])
            ->addColumn('type', 'string', ['limit' => 30, 'default' => 'landing'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
            ->addColumn('domain', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('affiliate_link', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('source_domain', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('views', 'integer', ['default' => 0])
            ->addColumn('failed_assets', 'json', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
