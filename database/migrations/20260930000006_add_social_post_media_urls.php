<?php

use Phinx\Migration\AbstractMigration;

/**
 * Carrossel (Fase 5): um post pode ter múltiplas mídias (2 a 10 no
 * Instagram). `media_urls` é um JSON array de {"url":..,"kind":..} —
 * quando vazio, usa-se o campo legado `media_url` (midia unica).
 */
class AddSocialPostMediaUrls extends AbstractMigration
{
    public function up(): void
    {
        $this->table('social_posts')
            ->addColumn('media_urls', 'text', ['null' => true])
            ->save();
    }

    public function down(): void
    {
        $this->table('social_posts')
            ->removeColumn('media_urls')
            ->save();
    }
}