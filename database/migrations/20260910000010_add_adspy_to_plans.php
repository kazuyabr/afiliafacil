<?php

use Phinx\Migration\AbstractMigration;

class AddAdSpyToPlans extends AbstractMigration
{
    public function change(): void
    {
        $this->table('plans')
            ->addColumn('max_adspy_searches', 'integer', ['default' => 0])
            ->addColumn('max_ai_analyses', 'integer', ['default' => 0])
            ->update();
    }
}
