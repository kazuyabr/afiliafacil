<?php

use Phinx\Migration\AbstractMigration;

/**
 * State-machine de publicacao (Fase 5): containers do Instagram/Reels
 * precisam de aguardo entre create e publish (processamento de midia).
 * `pending_data` armazena o estado interno (containers, tipo, etc.) em
 * JSON para que uma queda do worker nao recrie o post.
 */
class AddSocialPostTargetPendingData extends AbstractMigration
{
    public function up(): void
    {
        $this->table('social_post_targets')
            ->addColumn('pending_data', 'text', ['null' => true])
            ->save();
    }

    public function down(): void
    {
        $this->table('social_post_targets')
            ->removeColumn('pending_data')
            ->save();
    }
}