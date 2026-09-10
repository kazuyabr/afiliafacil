<?php

use Phinx\Migration\AbstractMigration;

class CreateSettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('settings', ['id' => false, 'primary_key' => 'key'])
            ->addColumn('key', 'string', ['limit' => 100])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->create();
    }
}
