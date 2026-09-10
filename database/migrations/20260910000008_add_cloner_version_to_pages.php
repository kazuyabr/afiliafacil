<?php

use Phinx\Migration\AbstractMigration;

class AddClonerVersionToPages extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pages')
            ->addColumn('cloner_version', 'string', ['limit' => 20, 'default' => ''])
            ->update();
    }
}
