<?php

use Phinx\Migration\AbstractMigration;

class CreateFeedback extends AbstractMigration
{
    public function change(): void
    {
        $this->table('feedback')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('type', 'string', ['limit' => 20, 'default' => 'sugestao'])
            ->addColumn('message', 'text', ['null' => true])
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'novo'])
            ->addColumn('admin_reply', 'text', ['null' => true])
            ->addColumn('replied_by', 'biginteger', ['null' => true])
            ->addColumn('replied_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['user_id', 'created_at'])
            ->addIndex(['status'])
            ->addIndex(['type'])
            ->create();
    }
}
