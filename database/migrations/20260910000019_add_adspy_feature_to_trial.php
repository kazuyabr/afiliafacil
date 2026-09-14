<?php

use Phinx\Migration\AbstractMigration;

class AddAdSpyFeatureToTrial extends AbstractMigration
{
    public function up(): void
    {
        $conn = $this->getAdapter()->getConnection();

        $stmt = $conn->prepare('SELECT features FROM plans WHERE id = ?');
        $stmt->execute(['trial']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $features = json_decode($row['features'] ?? '[]', true);
        if (!is_array($features)) $features = [];
        if (in_array('adspy', $features, true)) return;

        $features[] = 'adspy';

        $upd = $conn->prepare('UPDATE plans SET features = ? WHERE id = ?');
        $upd->execute([json_encode($features), 'trial']);
    }

    public function down(): void
    {
    }
}
