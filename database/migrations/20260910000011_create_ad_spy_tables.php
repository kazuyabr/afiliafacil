<?php

use Phinx\Migration\AbstractMigration;

class CreateAdSpyTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ad_spy_searches')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('kind', 'string', ['limit' => 20, 'default' => 'search'])
            ->addColumn('query', 'string', ['limit' => 255])
            ->addColumn('provider', 'string', ['limit' => 30])
            ->addColumn('results_count', 'integer', ['default' => 0])
            ->addColumn('from_cache', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->create();

        $this->table('ad_spy_cache')
            ->addColumn('cache_key', 'string', ['limit' => 64])
            ->addColumn('provider', 'string', ['limit' => 30])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('expires_at', 'timestamp', ['null' => true])
            ->addIndex(['cache_key'], ['unique' => true])
            ->addIndex(['expires_at'])
            ->create();

        $this->table('user_ai_configs')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('provider', 'string', ['limit' => 60, 'default' => 'cloudflare'])
            ->addColumn('model', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('base_url', 'string', ['limit' => 255, 'default' => ''])
            ->addColumn('api_key_encrypted', 'text', ['null' => true])
            ->addColumn('enabled', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'], ['unique' => true])
            ->create();
    }
}
