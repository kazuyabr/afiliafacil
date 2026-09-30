<?php

use Phinx\Migration\AbstractMigration;

/**
 * Publicacao unificada em redes sociais (Fase 1 do epico de automacao):
 * conexoes por rede (tokens criptografados), posts e status POR REDE
 * (falha em uma rede nao derruba as demais).
 */
class CreateSocialTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('social_connections', ['id' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('network', 'string', ['limit' => 20]) // facebook|instagram|threads|x|tiktok
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'connected']) // connected|expired|revoked
            ->addColumn('access_token', 'text') // criptografado (Crypto AES-256-GCM)
            ->addColumn('refresh_token', 'text', ['null' => true])
            ->addColumn('token_expires_at', 'datetime', ['null' => true])
            ->addColumn('account_id', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('account_name', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('account_meta', 'text', ['null' => true]) // JSON (page_id, ig_user_id, etc.)
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'network'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();

        $this->table('social_posts', ['id' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('caption', 'text')
            ->addColumn('media_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('media_kind', 'string', ['limit' => 20, 'default' => '']) // image|video|''
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'draft']) // draft|scheduled|publishing|published|partial|failed
            ->addColumn('scheduled_at', 'datetime', ['null' => true])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'manual']) // manual|agent
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'status'])
            ->addIndex(['status', 'scheduled_at'])
            ->create();

        $this->table('social_post_targets', ['id' => true])
            ->addColumn('post_id', 'biginteger')
            ->addColumn('connection_id', 'biginteger')
            ->addColumn('network', 'string', ['limit' => 20])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending']) // pending|publishing|published|failed
            ->addColumn('remote_id', 'string', ['limit' => 190, 'default' => ''])
            ->addColumn('error', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('published_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['post_id'])
            ->addIndex(['connection_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('social_post_targets')->drop();
        $this->table('social_posts')->drop();
        $this->table('social_connections')->drop();
    }
}
