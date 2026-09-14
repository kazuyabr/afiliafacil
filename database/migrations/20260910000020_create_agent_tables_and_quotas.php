<?php

use Phinx\Migration\AbstractMigration;

class CreateAgentTablesAndQuotas extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agent_conversations')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('title', 'string', ['limit' => 190, 'default' => 'Nova conversa'])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'updated_at'])
            ->create();

        $this->table('agent_messages')
            ->addColumn('conversation_id', 'biginteger')
            ->addColumn('role', 'string', ['limit' => 20, 'default' => 'user'])
            ->addColumn('content', 'text', ['null' => true])
            ->addColumn('tool_name', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('tool_args', 'json', ['null' => true])
            ->addColumn('tool_result', 'json', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['conversation_id', 'id'])
            ->create();

        $this->table('agent_profiles')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('niche', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('budget', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('experience', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('goals', 'json', ['null' => true])
            ->addColumn('preferences', 'json', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id'], ['unique' => true])
            ->create();

        $this->table('plans')
            ->addColumn('max_agent_messages', 'integer', ['default' => 0])
            ->update();

        $conn = $this->getAdapter()->getConnection();

        $quotas = [
            'trial' => 10,
            'essencial' => 100,
            'master' => 500,
            'premium' => -1,
        ];

        foreach ($quotas as $planId => $quota) {
            $stmt = $conn->prepare('SELECT features FROM plans WHERE id = ?');
            $stmt->execute([$planId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $features = json_decode($row['features'] ?? '[]', true);
            if (!is_array($features)) $features = [];
            if (!in_array('agent', $features, true)) {
                $features[] = 'agent';
            }

            $upd = $conn->prepare('UPDATE plans SET max_agent_messages = ?, features = ? WHERE id = ?');
            $upd->execute([$quota, json_encode($features), $planId]);
        }
    }
}
