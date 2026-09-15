<?php

use Phinx\Migration\AbstractMigration;

class CreateModerationEvents extends AbstractMigration
{
    public function change(): void
    {
        $this->table('moderation_events')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('context', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('category', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('action', 'string', ['limit' => 20, 'default' => ''])
            ->addColumn('reason', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('content', 'text', ['null' => true])
            ->addColumn('clean_content', 'text', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 250, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->addIndex(['category'])
            ->addIndex(['action'])
            ->create();
    }
}
