<?php

use Phinx\Migration\AbstractMigration;

class CreateAgentSubagents extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agent_subagents')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('name', 'string', ['limit' => 80])
            ->addColumn('specialty', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('instructions', 'text', ['null' => true])
            ->addColumn('tools', 'json', ['null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('created_by', 'string', ['limit' => 20, 'default' => 'user'])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'])
            ->create();

        $this->table('agent_conversations')
            ->addColumn('subagent_id', 'biginteger', ['null' => true])
            ->update();

        $this->table('plans')
            ->addColumn('max_subagents', 'integer', ['default' => 0])
            ->update();

        $conn = $this->getAdapter()->getConnection();

        $quotas = [
            'essencial' => 2,
            'master' => 5,
            'premium' => -1,
        ];

        foreach ($quotas as $planId => $quota) {
            $stmt = $conn->prepare('UPDATE plans SET max_subagents = ? WHERE id = ?');
            $stmt->execute([$quota, $planId]);
        }
    }
}
