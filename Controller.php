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

    function GET_index()
    {
        $this->checkPermissions();
        $this->render('convert-to-collective');
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

    function GET_mergeDuplicatedAgents()
    {
        $this->checkPermissions();

        self::$plugin->mergeDuplicatedAgents();
    }

    function GET_fixSubagents()
    {
        $this->checkPermissions();

        self::$plugin->fixSubagents();
    }

    function POST_enqueueJob()
    {
        $this->checkPermissions();

        $app = App::i();

        $app->enqueueOrReplaceJob(Job::SLUG, ['agent_ids' => $this->data['agents']]);
    }
}
