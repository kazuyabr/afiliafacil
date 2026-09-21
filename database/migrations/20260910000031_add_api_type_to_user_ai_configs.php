<?php

use Phinx\Migration\AbstractMigration;

/**
 * api_type: tipo de API do provider (openai|anthropic|google|azure|cloudflare) —
 * derivado do SDK do models.dev na UI, com ajuste manual.
 * local_ttl: segundos de inatividade para descarregar modelo local da VRAM (LM Studio).
 */
class AddApiTypeToUserAiConfigs extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_ai_configs')
            ->addColumn('api_type', 'string', ['limit' => 20, 'null' => true, 'default' => null, 'after' => 'provider'])
            ->addColumn('local_ttl', 'integer', ['null' => true, 'default' => null, 'after' => 'base_url'])
            ->update();
    }

    public function down(): void
    {
    }
}
