<?php

use Phinx\Migration\AbstractMigration;

class CreateTrainingSamples extends AbstractMigration
{
    public function change(): void
    {
        $this->table('training_samples')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('plan', 'string', ['limit' => 40, 'default' => ''])
            ->addColumn('kind', 'string', ['limit' => 30, 'default' => ''])
            ->addColumn('payload', 'json', ['null' => true])
            ->addColumn('consent', 'boolean', ['default' => true])
            ->addColumn('redacted', 'boolean', ['default' => true])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addIndex(['kind', 'created_at'])
            ->addIndex(['user_id'])
            ->create();
    }
}
