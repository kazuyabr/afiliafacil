<?php

use Phinx\Migration\AbstractMigration;

class CreateRoles extends AbstractMigration
{
    public function change(): void
    {
        $this->table('roles')
            ->addColumn('name', 'string', ['limit' => 60])
            ->addColumn('label', 'string', ['limit' => 100])
            ->addColumn('permissions', 'json', ['null' => true])
            ->addColumn('is_system', 'boolean', ['default' => false])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }
}
