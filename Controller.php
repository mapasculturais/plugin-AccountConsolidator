<?php

namespace AccountConsolidator;

use MapasCulturais\App;
use MapasCulturais\Controller as MapasCulturaisController;
use MapasCulturais\Exceptions\PermissionDenied;

class Controller extends MapasCulturaisController {
    static Plugin $plugin;

    function __construct() {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '4G'); 
    }

    function checkPermissions() {
        $this->requireAuthentication();

        $app = App::i();

        if(!$app->user->is('saasAdmin')) {
            throw new PermissionDenied($app->user, action:'fix agents');
        }
    }

    function GET_convertToCollective() {
        $this->checkPermissions();

        $agents = self::$plugin->analyzePersonNames();

        $this->render('convert-to-collective', ['agents' => $agents]);
    }

    function POST_convertToCollective() {
        $this->checkPermissions();
        $agent_ids = $this->data['agentIds'];
        self::$plugin->convertToCollective($agent_ids);
    }

    function GET_fixUserProfiles() {
        $this->checkPermissions();

        $app = App::i();
        $app->disableAccessControl();
        
        self::$plugin->fixUserProfiles();
    }
    
    function GET_mergeDuplicatedAgents() {
        $agents = self::$plugin->fetchAgents();
        $similarities = self::$plugin->groupSimilarAgents($agents);

        echo '<pre>';
        echo json_encode($similarities,JSON_PRETTY_PRINT);

        foreach($similarities as $agents_similarities) {
            self::$plugin->mergeAgents($agents_similarities->agents);
        }
    }

    
}
