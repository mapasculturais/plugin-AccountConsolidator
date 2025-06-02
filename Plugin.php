<?php

namespace AccountConsolidator;

use Doctrine\DBAL\Exception;
use MapasCulturais\App;
use MapasCulturais\Connection;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Plugin as MapasCulturaisPlugin;
use Symfony\Component\VarDumper\Cloner\VarCloner;

class Plugin extends MapasCulturaisPlugin
{
    protected Connection $conn;

    function __construct(array $config = [])
    {
        $config += [
            'document_metadata_key' => 'cpf',
            'pj_document_metadata_key' => 'cnpj',
            'similarity_cutoff' => 67,

            // emails de usuários que nào devem ser comparados
            'skip_user_emails' => [],

            // termos que serão removidos dos nomes no momento da comparação
            'skip_terms' => ['(in memoriam)'],

            // termos que devem conter em ambos os lados da comparação caso um dos lados contenha
            'required_common_terms' => ['mei'],

            // lista de palavras que forçam um nome a ser considerado pessoa física
            'person_terms' => [
                'do coco',
            ],

            // lista de palavras que fazem um nome ser considerado de pessoa jurídica
            'colective_terms' => include __DIR__ . '/collective-terms.php'

        ];

        parent::__construct($config);
    }

    function _init()
    {
        $app = App::i();
        $this->conn = $app->em->getConnection();

        $self = $this;

        $app->hook('GET(account-consolidator.<<*>>):before', function () use ($app) {
            $app->view->enqueueStyle('app-v2', 'account-consolidator', 'css/account-consolidator.css');
        });
    }

    function register()
    {
        $app = App::i();

        $app->registerController('account-consolidator', Controller::class);

        Controller::$plugin = $this;
    }

    function isACollectiveName(?string $name): bool
    {
        $name = (string) $name;

        if (str_contains($name, '&')) {
            return true;
        }

        $name = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name));

        foreach ($this->config['person_terms'] as $term) {
            $term = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $term));
            if (preg_match("/\b{$term}\b/", $name)) {
                return false;
            }
        }

        foreach ($this->config['colective_terms'] as $term) {
            $term = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $term));
            if (preg_match("/\b{$term}\b/", $name)) {
                return true;
            }
        }

        return false;
    }

    function analyzePersonNames()
    {
        $agents = $this->conn->fetchAll("SELECT 
                                                a.user_id, u.email as user_email, u.profile_id, 
                                                a.id, a.parent_id,a.name as name, a.type, 
                                               _nomeCompleto.value as nome_completo, 
                                                _cpf.value as cpf, 
                                                _cnpj.value as cnpj 
                                            FROM agent a 
                                                LEFT JOIN agent_meta _nomeCompleto ON _nomeCompleto.object_id = a.id AND _nomeCompleto.key = 'nomeCompleto'
                                                LEFT JOIN agent_meta _cpf ON _cpf.object_id = a.id AND _cpf.key = 'cpf'
                                                LEFT JOIN agent_meta _cnpj ON _cnpj.object_id = a.id AND _cnpj.key = 'cnpj'
                                                JOIN usr u ON u.id = a.user_id 
                                            WHERE a.type = 1 AND a.status > 0
                                            ORDER BY a.name ASC");

        $names_not_ok = [];

        foreach ($agents as &$agent) {
            $agent = (object) $agent;
            if ($this->isACollectiveName($agent->name)) {
                $names_not_ok[] = $agent;
            }
        }

        return $names_not_ok;
    }

    function convertToCollective(array $agent_ids)
    {
        $agent_ids = array_map(fn($id) => (int) $id, $agent_ids);
        $ids = implode(',', $agent_ids);
        $this->conn->executeQuery("UPDATE agent SET type = 2 WHERE id in ($ids)");
    }

    /** Faz com que todos os usuários tenham um agente individual como agente principal (profile) */
    function fixUserProfiles()
    {
        $skip_user_emails = '';
        if ($emails = $this->config['skip_user_emails']) {
            $emails = "'" . implode("','", $emails) . "'";
            $skip_user_emails = "AND u.email NOT IN ($emails)";
        }

        $agents = $this->conn->fetchAll("SELECT 
                                                a.user_id, u.email as user_email, u.profile_id, 
                                                a.id, a.parent_id, unaccent(lower(a.name)) as name, a.type, 
                                                unaccent(lower(_nomeCompleto.value)) as nome_completo, 
                                                emailPublico.value as email_publico, 
                                                emailPrivado.value as email_privado, 
                                                _cpf.value as cpf, 
                                                _cnpj.value as cnpj 
                                            FROM agent a 
                                                LEFT JOIN agent_meta _nomeCompleto ON _nomeCompleto.object_id = a.id AND _nomeCompleto.key = 'nomeCompleto'
                                                LEFT JOIN agent_meta emailPrivado ON emailPrivado.object_id = a.id AND emailPrivado.key = 'emailPrivado'
                                                LEFT JOIN agent_meta emailPublico ON emailPublico.object_id = a.id AND emailPublico.key = 'emailPublico'
                                                LEFT JOIN agent_meta _cpf ON _cpf.object_id = a.id AND _cpf.key = 'cpf'
                                                LEFT JOIN agent_meta _cnpj ON _cnpj.object_id = a.id AND _cnpj.key = 'cnpj'
                                                JOIN usr u ON u.id = a.user_id 
                                            WHERE a.user_id IN (
                                                SELECT distinct(u.id) 
                                                FROM usr u 
                                                WHERE 
                                                    u.profile_id IN (SELECT id FROM agent WHERE type = 2)
                                                    $skip_user_emails
                                            )
                                            ORDER BY a.id ASC");

        $user_agents = [];

        $user_skel = [
            'user_id' => null,
            'profile_id' => null,
            'new_profile_agente' => null,
            'individual' => [],
            'coletivo' => []
        ];

        // agrupa agentes dos usuários, saparando os agentes individuais e coletivos
        foreach ($agents as $agent) {
            $agent = (object) $agent;
            if (!isset($user_agents[$agent->user_id])) {
                $user_agents[$agent->user_id] = (object) $user_skel;
                $user_agents[$agent->user_id]->user_id = $agent->user_id;
            }

            $user_agents[$agent->user_id]->profile_id = $agent->profile_id;

            if ($agent->type == 1) {
                $user_agents[$agent->user_id]->individual[] = $agent;
            }

            if ($agent->type == 2) {
                $user_agents[$agent->user_id]->coletivo[] = $agent;
            }
        }

        // agrupamento de cada cenário
        $cases = [
            '1.' => [],
            '2.1.' => [],
            '2.2.' => [],
            '2.3.' => [],
            '2.4.' => [],
            '3.1.' => [],
            '3.2.' => [],
            '3.3.' => [],
            '4.' => [],
        ];

        // definiçào de qual será o agente de perfil
        foreach ($user_agents as $user_id => &$user) {
            // 1. se o usuário tem apenas 1 agente individual, coloca esse agente como agente de perfil
            if (count($user->individual) == 1) {
                $user->new_profile_agente = $user->individual[0];
                $cases['1.'][] = $user;
                continue;
            }

            // 2. se o usuário tem mais do que 1 agente individual procura o agente seguinto essa ordem: 
            // 2.1. procura o agente que tem o email igual ao do usuário e que tenha o cpf preenchido
            // 2.2. que tenha CPF preenchido
            // 2.3. procura o agente que tem o email igual ao do usuário
            // 2.4. pega o primeiro da lista
            if (count($user->individual) > 1) {
                // 2.1. procura o agente que tem o email igual ao do usuário e que tenha o cpf preenchido
                foreach ($user->individual as $agent) {
                    if ($this->isACollectiveName($agent->name) || $this->isACollectiveName($agent->nome_completo)) {
                        continue;
                    }
                    if ($agent->cpf && ($agent->email_privado == $agent->user_email || $agent->email_publico == $agent->user_email)) {
                        $user->new_profile_agente = $agent;
                        $cases['2.1.'][] = $user;
                        break;
                    }
                }
                if ($user->new_profile_agente) continue;

                // 2.2. que tenha CPF preenchido
                foreach ($user->individual as $agent) {
                    if ($this->isACollectiveName($agent->name) || $this->isACollectiveName($agent->nome_completo)) {
                        continue;
                    }

                    if ($agent->cpf) {
                        $user->new_profile_agente = $agent;
                        $cases['2.2.'][] = $user;
                        break;
                    }
                }
                if ($user->new_profile_agente) continue;

                // 2.3. procura o agente que tem o email igual ao do usuário
                foreach ($user->individual as $agent) {
                    if ($this->isACollectiveName($agent->name) || $this->isACollectiveName($agent->nome_completo)) {
                        continue;
                    }

                    if ($agent->email_privado == $agent->user_email || $agent->email_publico == $agent->user_email) {
                        $user->new_profile_agente = $agent;
                        $cases['2.3.'][] = $user;
                        break;
                    }
                }

                // 2.4. pega o primeiro da lista
                $user->new_profile_agente = $user->individual[0];
                $cases['2.4.'][] = $user;
                continue;
            }

            if ($user->new_profile_agente) continue;


            // 3. Se o usuário não possui nenhum agente individual:
            // 3.1. escolhe um agente coletivo que tenha CPF preenchido, NÃO tenha CNPJ preenchido e tenha o email igual ao do usuário
            // 3.2. escolhe um agente coletivo que tenha CPF preenchido, NÃO tenha CNPJ preenchido
            // 3.3. escolhe um agente coletivo que NÃO tenha CPF preenchido e NÃO tenha CNPJ preenchido

            foreach ($user->coletivo as $agent) {
                // verifica se o nome do agente contém uma das palavras que reconhecem um coletivo
                if ($this->isACollectiveName($agent->name) || $this->isACollectiveName($agent->nome_completo)) {
                    continue;
                }

                // 3.1. escolhe um agente coletivo que tenha CPF preenchido, não tenha CNPJ preenchido e tenha o email igual ao do usuário
                if ($agent->cpf && !$agent->cnpj && ($agent->email_privado == $agent->user_email || $agent->email_publico == $agent->user_email)) {
                    $user->new_profile_agente = $agent;
                    $cases['3.1.'][] = $user;
                    break;
                }

                // 3.2. escolhe um agente coletivo que tenha CPF preenchido, não tenha CNPJ preenchido
                if ($agent->cpf && !$agent->cnpj) {
                    $user->new_profile_agente = $agent;
                    $cases['3.2.'][] = $user;
                    break;
                }

                // 3.3. escolhe um agente sem cpf nem cnpj
                if (!$agent->cpf && !$agent->cnpj) {
                    $user->new_profile_agente = $agent;
                    $cases['3.3.'][] = $user;
                    break;
                }
            }

            if ($user->new_profile_agente) continue;

            // 4. não foi encontrado nenhum agente para ser o agente principal
            // se o $user->new_profile_agente permanecer null é porque não foi encontrado nenhum agente para ser o agente de perfil, 
            // no loop de definição dos agentes de perfil, se o $user->new_profile_agente for null, será criado um agente vazio como rascunho

            $cases['4.'][] = $user;
        }

        $cases_count = [];

        foreach ($cases as $num => $users) {
            $cases_count[$num] = count($users);
        }
        echo '<pre>';
        // print_r($cases);

        foreach ($cases as $case => $users) {
            $c = count($users);
            echo "<h1> $case ($c)</h1>";
            foreach ($users as $user) {
                $this->fixUserProfile($user);
            }
        }
    }

    public function fixUserProfile(object $user_def)
    {
        $app = App::i();
        if ($user_def->new_profile_agente) {
            $app->log->debug('FIX USER PROFILE: ' . "{$user_def->user_id};{$user_def->new_profile_agente->id};{$user_def->new_profile_agente->name}");
        } else {
            $app->log->debug('FIX USER PROFILE: ' . "{$user_def->user_id};NEW AGENT;NEW AGENT");
        }

        $conn = $this->conn;
        $conn->beginTransaction();

        $user = $app->repo('User')->find($user_def->user_id);

        if (!$user_def->new_profile_agente) {
            echo "NOVO" . PHP_EOL;
            $app->log->debug('criando novo agente individual para ser o profile do user ' . $user->user_id);
            $new_profile_agent = new Agent($user);
            $new_profile_agent->name = "";
            $new_profile_agent->type = 1;
            $new_profile_agent->status = Agent::STATUS_DRAFT;

            $new_profile_agent->save(true);
        } else {
            $new_profile_agent = $app->repo('Agent')->find($user_def->new_profile_agente->id);
            $new_profile_agent->type = 1;
            $new_profile_agent->save(true);
        }

        $new_profile_agent->setAsUserProfile();

        echo "{$user_def->user_id};{$new_profile_agent->id};{$new_profile_agent->name}" . PHP_EOL;
        $conn->commit();
        $app->em->clear();
    }

    /**
     * Retorna 
     * @return array 
     * @throws Exception 
     */
    function fetchAgents(): array
    {
        $doc_metadata_key = $this->config['document_metadata_key'];
        $pj_doc_metadata_key = $this->config['pj_document_metadata_key'];

        $skip_user_emails = '';
        if ($emails = $this->config['skip_user_emails']) {
            $emails = "'" . implode("','", $emails) . "'";
            $skip_user_emails = "AND u.email NOT IN ($emails)";
        }

        $sql = "SELECT 
                    u.id AS user_id,
                    u.create_timestamp AS user_create_timestamp,
                    u.profile_id AS user_profile_id,
                    u.email AS user_email,
                    u.last_login_timestamp AS user_last_login_timestamp,
                    a.id AS agent_id,
                    a.create_timestamp AS agent_create_timestamp,
                    a.update_timestamp AS agent_update_timestamps,
                    unaccent(lower(a.name)) AS agent_name,
                    unaccent(lower(nomeCompleto.value)) AS agent_nome_completo,
                    doc.value AS agent_doc,
                    pj_doc.value AS pj_doc,
                    emailPrivado.value AS agent_email_privado,
                    array_to_json(array_agg(row_to_json(terms))) AS terms
                FROM agent a 
                    JOIN usr u ON u.id = a.user_id
                    LEFT JOIN agent_meta nomeCompleto ON nomeCompleto.object_id = a.id AND nomeCompleto.key = 'nomeCompleto'
                    LEFT JOIN agent_meta emailPrivado ON emailPrivado.object_id = a.id AND emailPrivado.key = 'emailPrivado'
                    LEFT JOIN agent_meta doc ON doc.object_id = a.id AND doc.key = '$doc_metadata_key'
                    LEFT JOIN agent_meta pj_doc ON pj_doc.object_id = a.id AND pj_doc.key = '$pj_doc_metadata_key'
                    LEFT JOIN term_relation tr ON tr.object_type = 'MapasCulturais\Entities\Agent' AND tr.object_id = a.id
                    LEFT JOIN term terms ON terms.id = tr.term_id
                WHERE 
                    a.type = 1 AND
                    a.status = 1 
                    $skip_user_emails
                GROUP BY 
                    u.id,
                    user_create_timestamp,
                    user_profile_id,
                    user_email,
                    user_last_login_timestamp,
                    agent_id,
                    agent_create_timestamp,
                    agent_update_timestamps,
                    agent_name,
                    agent_nome_completo,
                    agent_doc,
                    pj_doc,
                    agent_email_privado
                ORDER BY a.id ASC
                LIMIT 1000
                ";
        $agents = $this->conn->fetchAll($sql);

        $agents = array_map(function ($a) {
            $a = (object) $a;
            $a->agent_name = trim($a->agent_name ?: '');
            $a->agent_nome_completo = trim($a->agent_nome_completo ?: '');
            $a->agent_doc = trim($a->agent_doc ?: '');
            $a->agent_email_privado = trim($a->agent_email_privado ?: '');

            return $a;
        }, $agents);

        return $agents;
    }

    function groupSimilarAgents(array $agents): array
    {
        $app = App::i();

        /** @var object[] */
        $similarities = [];

        /** @var object[] */
        $similarities_by_agent_id = [];

        for ($i1 = 0; $i1 < count($agents); $i1++) {
            $agent1 = $agents[$i1];

            $app->log->debug("#{$i1} - Comparando agente {$agent1->agent_id} ({$agent1->agent_name})");

            for ($i2 = $i1; $i2 < count($agents); $i2++) {
                $agent2 = $agents[$i2];

                // não precisa verificar os agentes do segundo loop com índice menor ou igual ao do primeiro loop pq já foram veriicados
                if ($i2 <= $i1) {
                    continue;
                }


                if ($sim = $this->getAgentSimilarities($agent1, $agent2)) {
                    if (!($similarity_container = $similarities_by_agent_id[$agent1->agent_id] ?? $similarities_by_agent_id[$agent2->agent_id] ?? null)) {
                        $similarity_container = (object) ['agents' => [], 'similarities' => []];
                        $similarities[] = $similarity_container;
                        $similarities_by_agent_id[$agent1->agent_id] = $similarity_container;
                        $similarities_by_agent_id[$agent2->agent_id] = $similarity_container;
                    }

                    $similarity_container->agents[$agent1->agent_id] = $agent1;
                    $similarity_container->agents[$agent2->agent_id] = $agent2;

                    $similarity_container->similarities["{$agent1->agent_id}:{$agent2->agent_id}"] = $sim;
                }
            }
        }

        return $similarities;
    }

    function similarNames($name1, $name2): float
    {
        // remove os termos que não devem ser considerados nas comparações
        foreach ($this->config['skip_terms'] as $term) {
            $term = preg_quote($term);
            $pattern = "#( |^){$term}([ $])#";
            $name1 = trim(preg_replace($pattern, ' ', $name1));
            $name2 = trim(preg_replace($pattern, ' ', $name2));
        }

        // verifica se ambos os lados contém os termos da configuração required_common_terms
        foreach ($this->config['required_common_terms'] as $term) {
            if (str_contains($name1, $term) && !str_contains($name2, $term)) {
                return 0.0;
            }

            if (!str_contains($name1, $term) && str_contains($name2, $term)) {
                return 0.0;
            }
        }

        similar_text($name1, $name2, $similarity);

        return $similarity;
    }

    function getAgentSimilarities(object $agent1, object $agent2): array
    {
        $similarity_cutoff = $this->config['similarity_cutoff'];
        $similarities = [];

        // calcula as similaridades dos nomes
        $name__similarity = $this->similarNames($agent1->agent_name, $agent2->agent_name);
        $nome_completo__similarity = $this->similarNames($agent1->agent_nome_completo, $agent2->agent_nome_completo);
        $name_nome_completo__similarity = $this->similarNames($agent1->agent_name, $agent2->agent_nome_completo);
        $nome_completo_name__similarity = $this->similarNames($agent1->agent_nome_completo, $agent2->agent_name);

        $max_similarity = max($name__similarity, $nome_completo__similarity, $name_nome_completo__similarity, $nome_completo_name__similarity);

        // compara os documentos dos agentes
        $pattern = '#[^\w]#';
        $doc_1 = preg_replace($pattern, '', $agent1->agent_doc);
        $doc_2 = preg_replace($pattern, '', $agent2->agent_doc);
        if ($doc_1 && $doc_1 == $doc_2 && $max_similarity >= 60) {
            $similarities['doc'] = [$max_similarity, $agent1->agent_doc, $agent2->agent_doc];
        }

        // compara os emails
        if (
            $agent1->user_email == $agent2->user_email ||
            ($agent1->agent_email_privado && $agent1->agent_email_privado == $agent2->agent_email_privado) ||
            ($agent1->user_email && $agent1->user_email == $agent2->agent_email_privado) ||
            ($agent1->agent_email_privado && $agent1->agent_email_privado == $agent2->user_email)
        ) {

            if ($name__similarity >= $similarity_cutoff) {
                $similarities['name:name'] = [$name__similarity, $agent1->agent_name, $agent2->agent_name];
            }
            if ($nome_completo__similarity >= $similarity_cutoff) {
                $similarities['nome_completo:nome_completo'] = [$nome_completo__similarity, $agent1->agent_nome_completo, $agent2->agent_nome_completo];
            }
            if ($name_nome_completo__similarity >= $similarity_cutoff) {
                $similarities['name:nome_completo'] = [$name_nome_completo__similarity, $agent1->agent_name, $agent2->agent_nome_completo];
            }
            if ($nome_completo_name__similarity >= $similarity_cutoff) {
                $similarities['nome_completo:name'] = [$nome_completo_name__similarity, $agent1->agent_nome_completo, $agent2->agent_name];
            }
        }

        return $similarities;
    }

    function mergeAgents(array $agents)
    {
        $app = App::i();

        $agent_ids = array_map(fn($agent) => $agent->agent_id, $agents);
        $agent_ids = implode(',', $agent_ids);

        $revision_ids = $this->conn->fetchColumn("SELECT max(id), object_id 
                                                  FROM entity_revision 
                                                  WHERE object_type = 'MapasCulturais\Entities\Agent' AND 
                                                        object_id in({$agent_ids}) 
                                                  GROUP BY object_id");

        $revision_ids = implode(',', $revision_ids);


        $revision_data = $this->conn->fetchAll("SELECT er.object_id, er.create_timestamp, rd.timestamp, rd.key, rd.value
                            FROM entity_revision er
                            LEFT JOIN entity_revision_revision_data errd ON errd.revision_id = er.id
                            LEFT JOIN entity_revision_data rd ON rd.id = errd.revision_data_id
                            WHERE er.id in ({$revision_ids})
                            ORDER BY rd.key, rd.timestamp ASC");


        $agents_data = [];
        $merged_data = [];

        $registered_metadata = $app->getRegisteredMetadata(Agent::class);

        // mescla as informações mais recentes baseadas nas revisões.
        // por conta da ordenação pelo timestamp do dado, no fim do loop o $agent_data
        // terá a versão mais recente dos dados preenchidos
        foreach ($revision_data as $data) {
            $data = (object) $data;

            if(in_array($data->key, ['_spaces', 'parent', '_subsiteId', '_type'])) {
                continue;
            }

            $data->value = json_decode($data->value);

            if($unserializer = $registered_metadata[$data->key]->unserialize) {
                $data->value = $unserializer($data->value, );
            }

            $agents_data[$data->object_id] = &$agents_data[$data->object_id] ?? [];
            $agent_data = &$agents_data[$data->object_id];

            if (in_array($data->key, ['createTimestamp', 'updateTimestamp'])) {
                $data->value = new \DateTime($data->value->date);
            }

            if ($data->key == '_terms') {
                $data->key = 'terms';
                $data->value = [];
                foreach (json_decode($agents[$data->object_id]->terms) as $term) {
                    if (!is_object($term)) {
                        continue;
                    }
                    $data->value[$term->taxonomy] = $data->value[$term->taxonomy] ?? [];
                    $data->value[$term->taxonomy][] = $term->term;
                }
            }

            if (isset($data->value)) {
                if ($data->key == 'createTimestamp') {
                    $merged_data[$data->key] = !isset($merged_data[$data->key]) || $merged_data[$data->key] > $data->value ? $data->value : $merged_data[$data->key];
                } else {
                    $merged_data[$data->key] = $data->value;
                }
            }

            $agent_data[$data->key] = $data->value;
        }

        eval(\psy\sh());die;
        /*
        Objetivo: manter um agente individual para cada CPF.
        - Se um ou mais dos agentes for o agente de perfil do usuário e o(s) outro(s) agentes não, 
            mantém o agente de perfil do usuário com o login mais recente
        - Se nenhum dos agentes forem agente de perfil, mantém o agente modificado a menos tempo.
        - Será mantida as informações mais recentes do agente individual, de cada metadado (imagem avatar, banner, email, endereço…);
        - Mesclar galeria de fotos e download, cuidando para evitar arquivos repetidos (verificando assinatura md5);
        - Mesclar galeria de vídeos e links, cuidando para evitar repetições;
        - Manter inscrições, eventos, espaços, projetos e oportunidades vinculadas aos seus agentes mesclados;
        */

        $profile_agents = [];
        foreach ($agents as $agent) {
            if ($agent->user_profile_id == $agent->agent_id) {
                $profile_agents[] = $agent;
            }
        }
  
        $target_agent = null;

        if ($profile_agents) {
            // se há agentes perfis de usuário, escolhe o aquele que tenha o usuário que fez login há menos tempo
            usort($profile_agents, fn($agent1, $agent2) => $agent2->user_last_login_timestamp <=> $agent1->user_last_login_timestamp);
            $target_agent = $profile_agents[0];
        } else {
            // se não há perfis de usuário, escolhe o agente que tenha sido atualizado há menos tempo
            usort($agents, fn($agent1, $agent2) => $agent2->agent_update_timestamps <=> $agent1->agent_update_timestamps);
            $target_agent = $agents[0];
        }

        $agent = $app->repo('Agent')->find($target_agent->agent_id);

        foreach($merged_data as $key => $value) {
            $agent->$key = $value;
        }

        $agent->save(true);

        $agents_to_delete = array_filter($agents, fn($ag) => $ag->agent_id != $target_agent->agent_id);

        // transfere todas as entidades "assinadas" pelos agentes que serão excluídos para o agente de destino
        foreach($agents_to_delete as $agent_to_delete) {
            $this->transferSubagents($agent_to_delete, $agent);
            $this->transferSpaces($agent_to_delete, $agent);
            $this->transferProjects($agent_to_delete, $agent);
            $this->transferOpportunities($agent_to_delete, $agent);
            $this->transferEvents($agent_to_delete, $agent);
            $this->transferRegistrations($agent_to_delete, $agent);

            $this->mergeMetaLists($agent_to_delete, $agent);
            $this->mergeFiles($agent_to_delete, $agent);
            $this->conn->executeQuery("DELETE FROM agent WHERE id = $agent_to_delete->agent_id");
        }

        // iterar no $users verificando se o usuário está vazio e apagando
        // o usuário pode estar vazio se não havia outro 

        eval(\psy\sh());
        die;
    }

    public function transferAgentRelations($from_agent, $to_agent) {
        $this->conn->executeQuery('
            UPDATE agent_relation 
            SET agent_id = :to 
            WHERE agent_id = :from', 
            ['from' => $from_agent->agent_id, 'to' => $to_agent->id]);

        $this->conn->executeQuery("
            UPDATE agent_relation 
            SET object_id = :to 
            WHERE object_type = 'MapasCulturais\Entities\Agent' AND object_id = :from", 
            ['from' => $from_agent->agent_id, 'to' => $to_agent->id]);

        // remove diplicidades da tabela 
        $this->conn->executeQuery("
            DELETE FROM agent_relation T1
                USING   agent_relation T2 
            WHERE T1.id < T2.id  -- delete the older versions
                AND T1.agent_id = T2.agent_id
                AND T1.object_type = T2.object_type
                AND T1.object_id = T2.object_id
                AND T1.type = T2.type
                AND T1.has_control = T2.has_control
                AND (T1.agent_id = :id OR T2.object_id = :id)
            ", ['id' => $to_agent->id]);
            
    }

    public function transferSubagents($from_agent, $to_agent) {
        $this->conn->executeQuery('
            UPDATE agent 
            SET 
                parent_id = :to,
                user_id = :user
            WHERE parent_id = :from', 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id,
                'user' => $to_agent->user->id
            ]);
    }

    public function transferSpaces($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE space 
            SET agent_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);
    }

    public function transferProjects($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE project 
            SET agent_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);
    }

    public function transferOpportunities($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE opportunity 
            SET agent_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);

        $this->conn->executeQuery("
            UPDATE opportunity 
            SET object_id = :to
            WHERE
                object_type = 'MapasCulturais\Entities\Agent' AND
                object_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);
    }

    public function transferEvents($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE event 
            SET agent_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);
    }

    public function transferRegistrations($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE registration 
            SET agent_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);
    }

    public function transferEvaluations($from_user, $to_user) {
        $this->conn->executeQuery("
            UPDATE registration_evaluation 
            SET user_id = :to
            WHERE agent_id = :from", 
            [
                'from' => $from_user->agent_id, 
                'to' => $to_user->id
            ]);
    }

    public function mergeMetaLists($from_agent, $to_agent) {
        $this->conn->executeQuery("
            UPDATE metalist 
            SET object_id = :to
            WHERE
                object_type = 'MapasCulturais\Entities\Agent' AND
                object_id = :from", 
            [
                'from' => $from_agent->agent_id, 
                'to' => $to_agent->id
            ]);

        $this->conn->executeQuery("
            DELETE FROM metalist T1
                USING   metalist T2 
            WHERE T1.id < T2.id  -- delete the older versions
                AND T1.object_type = 'MapasCulturais\Entities\Agent'
                AND T1.object_id = :id
                AND T1.object_type = T2.object_type
                AND T1.object_id = T2.object_id
                AND T1.grp = T2.grp
                AND T1.value = T2.value
            ", ['id' => $to_agent->id]);

    }

    public function mergeFiles($from_agent, $to_agent) {
        // obtém lista de arquivos da entidade de origem
        $from_files = $this->conn->fetchAll("
            SELECT * 
            FROM file 
            WHERE object_type = 'MapasCulturais\Entities\Agent' AND
                  object_id = :from",
                ['from' => $from_agent->agent_id]);

        // obtém listas de arquivos do destino
        $to_files = $this->conn->fetchAll("
            SELECT * 
            FROM file 
            WHERE object_type = 'MapasCulturais\Entities\Agent' AND
                  object_id = :from",
                ['from' => $to_agent->id]);

        $from_files = array_map(fn($item) => (object) $item, $from_files);
        $to_files = array_map(fn($item) => (object) $item, $to_files);


        $unique_file_groups = ['avatar', 'header'];

        $move_files = [];
        $delete_files = [];

        $array_find = function (array $array, callable $callable) {
            foreach($array as $item) {
                if($callable($item)) {
                    return $item;
                }
            }
        };

        // itera nos arquivos da entidade de origem e passa para a entidade de destino, 
        foreach($from_files as $origin_file) {
            $origin_file = $origin_file;
            if(in_array($origin_file->grp, $unique_file_groups)) {
                if($target_group_file = $array_find($to_files, fn($file) => $file->grp === $origin_file->grp)) {
                    if($origin_file->create_timestamp > $target_group_file->create_timestamp) {
                        $delete_files[] = $target_group_file;
                        $move_files[] = $origin_file;
                        continue;
                    } 
                    $delete_files[] = $origin_file;
                    continue;
                } 
            } else if($target_group_file = $array_find($to_files, fn($file) => $file->md5 === $origin_file->md5)) {
                // verificando se o md5 não consta na lista de arquivos de destino para não duplicar
                // caso já tenha arquivo com o mesmo md5, apaga o de origem e mantém o do destino
                $delete_files[] = $origin_file;
                continue;
            }

            $move_files[] = $origin_file;
        }

        $app = App::i();
        $public_files_path = $app->storage->config['dir'];
        // apaga os arquivos que devem ser apagados
        foreach($delete_files as $file) {
            $this->conn->executeQuery("DELETE FROM file WHERE id = {$file->id}");
            $path = $file->private ? 
                PRIVATE_FILES_PATH . $file->path :
                $public_files_path . $file->path;
            
            
            unlink($path);
            $this->conn->executeQuery("DELETE FROM file WHERE id = $file->id");
        }

        // move os arquivos que precisam ser movidos
        foreach($move_files as $file) {
            $old_path = $file->private ? 
                PRIVATE_FILES_PATH . $file->path :
                $public_files_path . $file->path;

            $new_path = str_replace("agent/{$from_agent->agent_id}/", "agent/{$to_agent->id}/", $old_path);

            $pathinfo = pathinfo ($new_path);

            @mkdir($pathinfo['dirname'], recursive:true);

            rename($old_path, $new_path);

            $this->conn->executeQuery("UPDATE file SET object_id = {$to_agent->id} WHERE id = $file->id");
        }
    }
}