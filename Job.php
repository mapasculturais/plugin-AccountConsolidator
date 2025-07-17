<?php

namespace AccountConsolidator;

use MapasCulturais\App;
use MapasCulturais\Entities\Job as EntitiesJob;

class Job extends \MapasCulturais\Definitions\JobType
{
    const SLUG = 'account-consolidator';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations) { }

    protected function _execute(EntitiesJob $job) { 
        \Notifications\Module::$disableMailMessages = true;

        $plugin = Plugin::$instance;

        /** @var int[] */
        $agent_ids = $job->agent_ids;

        $plugin->convertToCollective($agent_ids);

        $plugin->fixUserProfiles();

        $plugin->mergeDuplicatedAgents();

        $plugin->fixSubagents();

    }
}