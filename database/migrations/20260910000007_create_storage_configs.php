<?php

use Phinx\Migration\AbstractMigration;

class CreateStorageConfigs extends AbstractMigration
{
    public function change(): void
    {
        $this->table('storage_configs')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('provider', 'string', ['limit' => 20, 'default' => 'r2'])
            ->addColumn('account_id', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('access_key', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('secret_encrypted', 'text', ['null' => true])
            ->addColumn('bucket', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('public_url', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('media_mode', 'string', ['limit' => 20, 'default' => 'base64'])
            ->addColumn('enabled', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'], ['unique' => true])
            ->create();
    }
}
