<?php

require_once __DIR__ . '/Database.php';

class Audit
{
    public static function log(string $action, ?string $entity = null, ?string $entityId = null, array $meta = [], ?int $userId = null): void
    {
        if (!Database::available()) return;

        try {
            \AfiliaFacil\Models\AuditLog::create([
                'user_id' => $userId ?? (int)($_SESSION['user_id'] ?? 0) ?: null,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip' => self::clientIp(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
