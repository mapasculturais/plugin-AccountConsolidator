<?php

namespace AccountConsolidator;

use MapasCulturais\App;
use MapasCulturais\Controller as MapasCulturaisController;
use MapasCulturais\Exceptions\PermissionDenied;

class Controller extends MapasCulturaisController
{
    static Plugin $plugin;

    function __construct()
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '4G');
    }

    function checkPermissions()
    {
        $this->requireAuthentication();

        $app = App::i();

        if (!$app->user->is('saasAdmin')) {
            throw new PermissionDenied($app->user, action: 'fix agents');
        }
    }

    function GET_convertToCollective()
    {
        $this->checkPermissions();

        $agents = self::$plugin->analyzePersonNames();

        $this->render('convert-to-collective', ['agents' => $agents]);
    }

    function POST_convertToCollective()
    {
        $this->checkPermissions();
        $agent_ids = $this->data['agentIds'];
        self::$plugin->convertToCollective($agent_ids);
    }

    function GET_fixUserProfiles()
    {
        $this->checkPermissions();

        $app = App::i();
        $app->disableAccessControl();

        self::$plugin->fixUserProfiles();
    }

    function ALL_calculateAgentSimilarities()
    {
        $this->checkPermissions();

        $agents = self::$plugin->fetchAgents();
        $similarities = self::$plugin->groupSimilarAgents($agents);
    }

    function GET_mergeDuplicatedAgents()
    {
        $this->checkPermissions();

        $app = App::i();

        $agents = self::$plugin->fetchAgents();
        $similarities = self::$plugin->groupSimilarAgents($agents);

        $total = count($similarities);
        $count = 0;

        foreach ($similarities as $agents_similarities) {
            $count++;
            $agents_similarities->total = $total;
            $agents_similarities->count = $count;
            $agents_similarities->percentage = number_format($count / $total * 100, 1, ',') . '%';
            self::$plugin->log(self::$plugin::ACTION_MERGE_DUPLICATED_AGENTS, $agents_similarities);
            self::$plugin->mergeAgents($agents_similarities->agents);

            $app->em->clear();
        }
    }

    function GET_fixSubagents()
    {
        $this->checkPermissions();

        $app = App::i();
        self::$plugin->fixSubagents();
    }
}
