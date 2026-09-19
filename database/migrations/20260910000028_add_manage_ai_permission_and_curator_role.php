<?php

use Phinx\Migration\AbstractMigration;

class AddManageAiPermissionAndCuratorRole extends AbstractMigration
{
    public function up(): void
    {
        $conn = $this->getAdapter()->getConnection();

        foreach (['master', 'admin'] as $roleName) {
            $stmt = $conn->prepare('SELECT permissions FROM roles WHERE name = ?');
            $stmt->execute([$roleName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $perms = json_decode($row['permissions'] ?? '[]', true);
            if (!is_array($perms)) $perms = [];
            if (!in_array('manage_ai', $perms, true)) {
                $perms[] = 'manage_ai';
            }

            $upd = $conn->prepare('UPDATE roles SET permissions = ?, updated_at = ? WHERE name = ?');
            $upd->execute([json_encode($perms), date('Y-m-d H:i:s'), $roleName]);
        }

        $stmt = $conn->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute(['curador']);
        if (!$stmt->fetch()) {
            $ins = $conn->prepare('INSERT INTO roles (name, label, permissions, is_system, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $ins->execute(['curador', 'Curador de IA', json_encode(['manage_ai']), 'false', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    }

    public function down(): void
    {
    }
}
