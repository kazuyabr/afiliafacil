<?php

use Phinx\Migration\AbstractMigration;

/**
 * Credenciais de app OAuth do proprio usuario (BYOK de app — Fase 4).
 * O usuario cria o app na plataforma (Meta/Threads/X/TikTok) — que ja e
 * obrigatorio para gerar token — e col aqui App ID + Secret; assim o OAuth
 * oficial funciona SEM App Review da plataforma (app em modo dev + papel
 * proprio). Secret sempre criptografado (AES-256-GCM via Crypto).
 */
class CreateSocialAppCredentials extends AbstractMigration
{
    public function up(): void
    {
        $this->table('social_app_credentials', ['id' => true])
            ->addColumn('user_id', 'biginteger')
            ->addColumn('provider', 'string', ['limit' => 20]) // meta | threads | x | tiktok
            ->addColumn('app_id', 'string', ['limit' => 190])
            ->addColumn('app_secret', 'string', ['limit' => 255])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id', 'provider'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('social_app_credentials')->drop();
    }
}
