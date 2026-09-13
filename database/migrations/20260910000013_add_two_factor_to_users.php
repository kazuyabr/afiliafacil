<?php

use Phinx\Migration\AbstractMigration;

class AddTwoFactorToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('two_factor_secret', 'text', ['null' => true])
            ->addColumn('two_factor_enabled', 'boolean', ['default' => false])
            ->addColumn('two_factor_recovery_codes', 'text', ['null' => true])
            ->update();
    }
}
