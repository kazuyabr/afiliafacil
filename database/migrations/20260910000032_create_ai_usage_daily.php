<?php

use Phinx\Migration\AbstractMigration;

/**
 * Contador diario de uso de IA por usuario (protecao/transparencia da cota compartilhada).
 */
class CreateAiUsageDaily extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_usage_daily', ['id' => true])
            ->addColumn('user_id', 'integer')
            ->addColumn('usage_date', 'date')
            ->addColumn('requests', 'integer', ['default' => 0])
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'platform'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'usage_date'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
    }
}
