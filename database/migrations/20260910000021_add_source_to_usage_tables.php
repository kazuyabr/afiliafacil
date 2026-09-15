<?php

use Phinx\Migration\AbstractMigration;

class AddSourceToUsageTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ad_spy_searches')
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'platform'])
            ->update();

        $this->table('transcriptions')
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'platform'])
            ->update();

        $this->table('tts_generations')
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'platform'])
            ->update();

        $this->table('agent_messages')
            ->addColumn('source', 'string', ['limit' => 20, 'default' => 'platform'])
            ->update();
    }
}
