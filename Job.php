<?php

namespace AccountConsolidator;

use MapasCulturais\App;
use MapasCulturais\Entities\Job as EntitiesJob;

class Job extends \MapasCulturais\Definitions\JobType
{
    const SLUG = 'account-consolidator';

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations) { }

    protected function _execute(EntitiesJob $job) { 
        $app = App::i();

        $plugin = Plugin::$instance;

        /** @var int[] */
        $agent_ids = $job->agent_ids;

        // converte os agentes individuais com nomes de agente coletivos em agentes coletivos
        $plugin->convertToCollective($agent_ids);

        $plugin->fixUserProfiles();

        $agents = $plugin->fetchAgents();
        $similarities = $plugin->groupSimilarAgents($agents);

        $total = count($similarities);
        $count = 0;

        foreach ($similarities as $agents_similarities) {
            $count++;
            $agents_similarities->total = $total;
            $agents_similarities->count = $count;
            $agents_similarities->percentage = number_format($count / $total * 100, 1, ',') . '%';
            $plugin->log($plugin::ACTION_MERGE_DUPLICATED_AGENTS, $agents_similarities);
            $plugin->mergeAgents($agents_similarities->agents);

            $app->em->clear();
        }

        $plugin->fixSubagents();

    }
}