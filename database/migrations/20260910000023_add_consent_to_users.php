<?php

use Phinx\Migration\AbstractMigration;

class AddConsentToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('training_consent', 'boolean', ['default' => false])
            ->addColumn('terms_accepted_at', 'timestamp', ['null' => true])
            ->update();
    }
}
