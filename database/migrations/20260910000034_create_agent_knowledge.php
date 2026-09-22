<?php

use Phinx\Migration\AbstractMigration;

class CreateAgentKnowledge extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('agent_knowledge');
        $table->addColumn('user_id', 'integer')
            ->addColumn('subagent_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('title', 'string', ['limit' => 120])
            ->addColumn('content', 'text')
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id'])
            ->addIndex(['user_id', 'subagent_id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('agent_knowledge')->drop()->save();
    }
}
