<?php

use Phinx\Migration\AbstractMigration;

/**
 * Quotas de publicacao social por plano + feature `social`.
 * Transparencia: limites sempre visiveis/obrigatorios (0 = sem acesso).
 */
class AddSocialQuotasToPlans extends AbstractMigration
{
    public function up(): void
    {
        $this->table('plans')
            ->addColumn('max_social_connections', 'integer', ['default' => 0])
            ->addColumn('max_posts_month', 'integer', ['default' => 0])
            ->update();

        // Feature `social` nos planos que terao acesso (trial tambem: validacao de mercado exige gosto gratis)
        $conn = $this->getAdapter()->getConnection();
        $rows = $conn->query(
            "SELECT id, features FROM plans WHERE id IN ('trial', 'vsl', 'essencial', 'master', 'premium')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $upd = $conn->prepare('UPDATE plans SET features = ? WHERE id = ?');
        foreach ($rows as $row) {
            $features = json_decode($row['features'] ?? '[]', true);
            if (!is_array($features)) $features = [];
            if (in_array('social', $features, true)) continue;
            $features[] = 'social';
            $upd->execute([json_encode($features), $row['id']]);
        }

        // Seed de quotas (nao destrutivo: so preenche planos ainda com 0/0)
        $seed = [
            'trial' => [1, 10],
            'vsl' => [1, 10],
            'essencial' => [3, 30],
            'master' => [5, 100],
            'premium' => [-1, -1],
        ];
        $updQuota = $conn->prepare(
            'UPDATE plans SET max_social_connections = ?, max_posts_month = ?
             WHERE id = ? AND max_social_connections = 0 AND max_posts_month = 0'
        );
        foreach ($seed as $id => [$conn2, $posts]) {
            $updQuota->execute([$conn2, $posts, $id]);
        }
    }

    public function down(): void
    {
        $this->table('plans')
            ->removeColumn('max_social_connections')
            ->removeColumn('max_posts_month')
            ->update();
    }
}
