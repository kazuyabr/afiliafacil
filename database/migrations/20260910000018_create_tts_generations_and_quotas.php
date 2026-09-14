<?php

use Phinx\Migration\AbstractMigration;

class CreateTtsGenerationsAndQuotas extends AbstractMigration
{
    public function change(): void
    {
        $this->table('tts_generations')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('provider', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('model', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('voice', 'string', ['limit' => 80, 'default' => ''])
            ->addColumn('format', 'string', ['limit' => 10, 'default' => 'mp3'])
            ->addColumn('text', 'text', ['null' => true])
            ->addColumn('chars', 'integer', ['default' => 0])
            ->addColumn('file_path', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('error', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->create();

        $this->table('plans')
            ->addColumn('max_tts', 'integer', ['default' => 0])
            ->update();

        $conn = $this->getAdapter()->getConnection();

        $quotas = [
            'trial' => 2,
            'essencial' => 10,
            'master' => 100,
            'premium' => -1,
        ];

        foreach ($quotas as $planId => $quota) {
            $stmt = $conn->prepare('UPDATE plans SET max_tts = ? WHERE id = ?');
            $stmt->execute([$quota, $planId]);
        }
    }
}
