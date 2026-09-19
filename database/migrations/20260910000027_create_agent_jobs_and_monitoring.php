<?php

use Phinx\Migration\AbstractMigration;

class CreateAgentJobsAndMonitoring extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agent_jobs')
            ->addColumn('conversation_id', 'biginteger')
            ->addColumn('user_id', 'biginteger')
            ->addColumn('kind', 'string', ['limit' => 20, 'default' => 'agent'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('error', 'string', ['limit' => 500, 'default' => ''])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('started_at', 'timestamp', ['null' => true])
            ->addColumn('finished_at', 'timestamp', ['null' => true])
            ->addIndex(['status', 'created_at'])
            ->addIndex(['conversation_id'])
            ->create();

        $this->table('agent_messages')
            ->addColumn('seen_at', 'timestamp', ['null' => true])
            ->addColumn('rating', 'integer', ['null' => true])
            ->addColumn('rating_note', 'string', ['limit' => 500, 'default' => ''])
            ->update();
    }
}
