<?php

use Phinx\Migration\AbstractMigration;

/**
 * Corrige a feature `adspy` nos planos pagos: os planos com cota de busca
 * (max_adspy_searches != 0) precisam da feature para o guard liberar o
 * Espionar Anuncios (Ad Spy) e a tool do Socio de IA.
 */
class AddAdSpyFeatureToPaidPlans extends AbstractMigration
{
    public function up(): void
    {
        $conn = $this->getAdapter()->getConnection();

        $rows = $conn->query(
            "SELECT id, features FROM plans WHERE max_adspy_searches IS NULL OR max_adspy_searches <> 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $upd = $conn->prepare('UPDATE plans SET features = ? WHERE id = ?');

        foreach ($rows as $row) {
            $features = json_decode($row['features'] ?? '[]', true);
            if (!is_array($features)) $features = [];
            if (in_array('adspy', $features, true)) continue;

            $features[] = 'adspy';
            $upd->execute([json_encode($features), $row['id']]);
        }
    }

    public function down(): void
    {
    }
}
