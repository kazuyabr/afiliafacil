<?php

use Phinx\Migration\AbstractMigration;

/**
 * Fluxos de automacao (Fase 3 do epico de publicacao unificada).
 * Gatilhos herdados dos templates n8n: schedule (monitor/relatorios) e
 * post_published (publicacao multi-destino). Acoes: publish_post e webhook.
 */
class CreateFlows extends AbstractMigration
{
    public function up(): void
    {
        $this->table('flows', ['id' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('trigger_kind', 'string', ['limit' => 20]) // schedule | post_published
            ->addColumn('trigger_config', 'text', ['default' => ''])
            ->addColumn('action_kind', 'string', ['limit' => 30]) // publish_post | webhook
            ->addColumn('action_config', 'text', ['default' => ''])
            ->addColumn('enabled', 'boolean', ['default' => true])
            ->addColumn('last_run_at', 'datetime', ['null' => true])
            ->addColumn('last_status', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id'])
            ->addIndex(['enabled', 'trigger_kind'])
            ->create();

        $this->table('flow_runs', ['id' => true])
            ->addColumn('flow_id', 'biginteger')
            ->addColumn('event', 'string', ['limit' => 20])
            ->addColumn('status', 'string', ['limit' => 20]) // ok | failed
            ->addColumn('detail', 'text', ['null' => true])
            ->addColumn('ran_at', 'datetime')
            ->addColumn('created_at', 'datetime')
            ->addIndex(['flow_id'])
            ->addIndex(['ran_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('flow_runs')->drop();
        $this->table('flows')->drop();
    }
}
