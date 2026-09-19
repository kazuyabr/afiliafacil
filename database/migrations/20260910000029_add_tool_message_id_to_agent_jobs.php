<?php

use Phinx\Migration\AbstractMigration;

class AddToolMessageIdToAgentJobs extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agent_jobs')
            ->addColumn('tool_message_id', 'biginteger', ['null' => true, 'after' => 'kind'])
            ->update();
    }
}
