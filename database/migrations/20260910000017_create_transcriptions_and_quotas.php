<?php

use Phinx\Migration\AbstractMigration;

class CreateTranscriptionsAndQuotas extends AbstractMigration
{
    public function change(): void
    {
        $this->table('transcriptions')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('source_url', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('provider', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('language', 'string', ['limit' => 10, 'default' => 'pt'])
            ->addColumn('duration_seconds', 'integer', ['default' => 0])
            ->addColumn('text', 'text', ['null' => true])
            ->addColumn('words', 'json', ['null' => true])
            ->addColumn('error', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->create();

        $this->table('plans')
            ->addColumn('max_transcriptions', 'integer', ['default' => 0])
            ->update();

        $conn = $this->getAdapter()->getConnection();

        $quotas = [
            'trial' => 2,
            'essencial' => 10,
            'master' => 100,
            'premium' => -1,
        ];

        foreach ($quotas as $planId => $quota) {
            $stmt = $conn->prepare('UPDATE plans SET max_transcriptions = ? WHERE id = ?');
            $stmt->execute([$quota, $planId]);
        }
    }
}
