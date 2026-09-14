<?php

use Phinx\Migration\AbstractMigration;

class AddCapabilityToUserAiConfigs extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_ai_configs')
            ->addColumn('capability', 'string', ['limit' => 20, 'default' => 'chat', 'after' => 'user_id'])
            ->removeIndex(['user_id'])
            ->update();

        $this->table('user_ai_configs')
            ->addIndex(['user_id', 'capability'], ['unique' => true])
            ->update();
    }

    public function down(): void
    {
    }
}
