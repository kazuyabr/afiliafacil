<?php

use Phinx\Migration\AbstractMigration;

/**
 * Metricas por post/rede (Fase 2 do epico de automacao — dashboard unificado).
 * Coletadas das APIs de cada rede para o dono do post (insights proprios,
 * sem App Review); guardadas por (post_id, network) com collected_at para
 * evitar recolecao a cada polling.
 */
class CreateSocialPostMetrics extends AbstractMigration
{
    public function up(): void
    {
        $this->table('social_post_metrics', ['id' => true])
            ->addColumn('post_id', 'biginteger')
            ->addColumn('connection_id', 'biginteger', ['null' => true])
            ->addColumn('network', 'string', ['limit' => 20])
            ->addColumn('likes', 'integer', ['default' => 0])
            ->addColumn('comments', 'integer', ['default' => 0])
            ->addColumn('shares', 'integer', ['default' => 0]) // shares/reposts/retweets/quotes
            ->addColumn('replies', 'integer', ['default' => 0])
            ->addColumn('impressions', 'integer', ['default' => 0])
            ->addColumn('reach', 'integer', ['default' => 0])
            ->addColumn('views', 'integer', ['default' => 0])
            ->addColumn('collected_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['post_id', 'network'], ['unique' => true])
            ->addIndex(['post_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('social_post_metrics')->drop();
    }
}
