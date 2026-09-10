<?php

use Phinx\Migration\AbstractMigration;

class CreateAuditAndLoginAttempts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('audit_log')
            ->addColumn('user_id', 'biginteger', ['null' => true])
            ->addColumn('action', 'string', ['limit' => 60])
            ->addColumn('entity', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('entity_id', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('meta', 'text', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['created_at'])
            ->addIndex(['action'])
            ->create();

        $this->table('login_attempts')
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('ip', 'string', ['limit' => 60])
            ->addColumn('success', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['email', 'created_at'])
            ->addIndex(['ip', 'created_at'])
            ->create();
    }
}
