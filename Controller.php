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

    function POST_saveCheckboxState()
    {
        $this->checkPermissions();

        $agent_id = (int) ($this->data['agentId'] ?? 0);
        $checked = (bool) ($this->data['checked'] ?? false);

        if (!$agent_id) {
            return $this->json(['ok' => false, 'message' => 'agentId inválido'], 400);
        }

        $dir = PRIVATE_FILES_PATH . 'account-consolidator/';
        mkdir($dir, 0777, true);
        $file = $dir . 'unchecked-agents.json';

        $unchecked = [];
        if (is_file($file)) {
            $decoded = json_decode(@file_get_contents($file), true);
            if (is_array($decoded)) {
                $unchecked = $decoded;
            }
        }

        if ($checked) {
            $unchecked = array_values(array_filter($unchecked, fn($id) => (int) $id !== $agent_id));
        } else {
            if (!in_array($agent_id, $unchecked, true)) {
                $unchecked[] = $agent_id;
            }
        }

        file_put_contents($file, json_encode($unchecked), LOCK_EX);

        return $this->json(['ok' => true, 'unchecked' => $unchecked]);
    }

    function GET_loadCheckboxState()
    {
        $this->checkPermissions();

        $file = PRIVATE_FILES_PATH . 'account-consolidator/unchecked-agents.json';

        if (!is_file($file)) {
            return $this->json(['unchecked' => []]);
        }

        $decoded = json_decode(file_get_contents($file), true);

        if (!is_array($decoded)) {
            return $this->json(['unchecked' => []]);
        }

        return $this->json(['unchecked' => array_values($decoded)]);
    }
}
