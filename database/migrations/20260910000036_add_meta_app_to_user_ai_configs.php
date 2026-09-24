<?php

use Phinx\Migration\AbstractMigration;

class AddMetaAppToUserAiConfigs extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_ai_configs')
            ->addColumn('meta_app_id', 'string', ['limit' => 60, 'default' => ''])
            ->addColumn('meta_app_secret_encrypted', 'text', ['null' => true, 'default' => null])
            ->update();
    }
}
