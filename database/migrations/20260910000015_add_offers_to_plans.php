<?php

use Phinx\Migration\AbstractMigration;

class AddOffersToPlans extends AbstractMigration
{
    public function up(): void
    {
        $this->table('plans')
            ->addColumn('max_offers_views', 'integer', ['default' => 0])
            ->update();

        $conn = $this->getAdapter()->getConnection();

        $config = [
            'trial' => ['quota' => 3, 'feature' => true],
            'essencial' => ['quota' => 30, 'feature' => true],
            'master' => ['quota' => 300, 'feature' => true],
            'premium' => ['quota' => -1, 'feature' => true],
        ];

        foreach ($config as $planId => $cfg) {
            $stmt = $conn->prepare('SELECT features FROM plans WHERE id = ?');
            $stmt->execute([$planId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $features = json_decode($row['features'] ?? '[]', true);
            if (!is_array($features)) $features = [];
            if ($cfg['feature'] && !in_array('offers', $features, true)) {
                $features[] = 'offers';
            }

            $upd = $conn->prepare('UPDATE plans SET max_offers_views = ?, features = ? WHERE id = ?');
            $upd->execute([$cfg['quota'], json_encode($features), $planId]);
        }
    }

    public function down(): void
    {
    }
}
