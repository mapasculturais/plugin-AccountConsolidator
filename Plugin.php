<?php
namespace AccountConsolidator;

use Doctrine\DBAL\Exception;
use MapasCulturais\App;
use MapasCulturais\Connection;
use MapasCulturais\Plugin as MapasCulturaisPlugin;
use Symfony\Component\VarDumper\Cloner\VarCloner;

class Plugin extends MapasCulturaisPlugin{
    protected Connection $conn;

    function __construct(array $config = [])
    {
        $config += [
            'document_metadata_key' => 'cpf',
            'similarity_cutoff' => 67,
            'skip_words' => ['(in memoriam)']
        ];

        parent::__construct($config);
    }

    function _init() {
        $app = App::i();
        $this->conn = $app->em->getConnection();

        $self = $this;
        $app->hook('GET(agent.consolidateAccounts)', function() use($self) {
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '4G'); 
            $agents = $self->fetchAgents();
            $similarities = $self->groupSimilarAgents($agents);

            echo '<pre>';
            echo json_encode($similarities,JSON_PRETTY_PRINT);
        });
    }

    function register()
    {
        
    }

    /**
     * Retorna 
     * @return array 
     * @throws Exception 
     */
    function fetchAgents(): array {
        $doc_metadata_key = $this->config['document_metadata_key'];

        $sql = "SELECT 
                    u.email AS user_email,
                    a.id AS agent_id,
                    unaccent(lower(a.name)) AS agent_name,
                    unaccent(lower(nomeCompleto.value)) AS agent_nome_completo,
                    doc.value AS agent_doc,
                    emailPrivado.value AS agent_email_privado
                FROM agent a 
                    JOIN usr u ON u.id = a.user_id
                    LEFT JOIN agent_meta nomeCompleto ON nomeCompleto.object_id = a.id AND nomeCompleto.key = 'nomeCompleto'
                    LEFT JOIN agent_meta emailPrivado ON emailPrivado.object_id = a.id AND emailPrivado.key = 'emailPrivado'
                    LEFT JOIN agent_meta doc ON doc.object_id = a.id AND doc.key = '$doc_metadata_key'
                WHERE 
                    a.type = 1 AND
                    a.status = 1
                GROUP BY user_email, agent_id, agent_nome_completo, agent_doc, agent_email_privado
                ORDER BY a.id ASC

                --- LIMIT 6000
                ";
        $agents = $this->conn->fetchAll($sql);

        $agents = array_map(function($a) {
            $a = (object) $a;
            $a->agent_name = trim($a->agent_name ?: '');
            $a->agent_nome_completo = trim($a->agent_nome_completo ?: '');
            $a->agent_doc = trim($a->agent_doc ?: '');
            $a->agent_email_privado = trim($a->agent_email_privado ?: '');

            return $a;
        }, $agents);

        return $agents;
    }

    function groupSimilarAgents(array $agents): array {
        $app = App::i();

        /** @var object[] */
        $similarities = [];

        /** @var object[] */
        $similarities_by_agent_id = [];

        for($i1 = 0; $i1 < count($agents); $i1++) {
            $agent1 = $agents[$i1];

            $app->log->debug("#{$i1} - Comparando agente {$agent1->agent_id} ({$agent1->agent_name})");

            for($i2 = $i1; $i2 < count($agents); $i2++) {
                $agent2 = $agents[$i2];
                
                // não precisa verificar os agentes do segundo loop com índice menor ou igual ao do primeiro loop pq já foram veriicados
                if($i2 <= $i1) {
                    continue;
                }


                if($sim = $this->getAgentSimilarities($agent1, $agent2)) {
                    if(!($similarity_container = $similarities_by_agent_id[$agent1->agent_id] ?? $similarities_by_agent_id[$agent2->agent_id] ?? null)){
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

    function getAgentSimilarities(object $agent1, object $agent2): array {
        $similarity_cutoff = $this->config['similarity_cutoff'];
        $similarities = [];

        // calcula as similaridades dos nomes
        similar_text($agent1->agent_name, $agent2->agent_name, $name__similarity);
        similar_text($agent1->agent_nome_completo, $agent2->agent_nome_completo, $nome_completo__similarity);
        similar_text($agent1->agent_name, $agent2->agent_nome_completo, $name_nome_completo__similarity);
        similar_text($agent1->agent_nome_completo, $agent2->agent_name, $nome_completo_name__similarity);

        $max_similarity = max($name__similarity,$nome_completo__similarity,$name_nome_completo__similarity,$nome_completo_name__similarity);

        // compara os documentos dos agentes
        $pattern = '#[^\w]#';
        $doc_1 = preg_replace($pattern, '', $agent1->agent_doc);
        $doc_2 = preg_replace($pattern, '', $agent2->agent_doc);
        if($doc_1 && $doc_1 == $doc_2 && $max_similarity >= 60) {
            $similarities['doc'] = [$max_similarity, $agent1->agent_doc, $agent2->agent_doc];
        }

        // compara os emails
        if(
            true && (
                $agent1->user_email == $agent2->user_email || 
                $agent1->agent_email_privado == $agent2->agent_email_privado || 
                $agent1->user_email == $agent2->agent_email_privado || 
                $agent1->agent_email_privado == $agent2->user_email
                )
            ) {

            if($name__similarity >= $similarity_cutoff) {
                $similarities['name:name'] = [$name__similarity, $agent1->agent_name, $agent2->agent_name];
            }
            if($nome_completo__similarity >= $similarity_cutoff) {
                $similarities['nome_completo:nome_completo'] = [$nome_completo__similarity, $agent1->agent_nome_completo, $agent2->agent_nome_completo];
            }
            if($name_nome_completo__similarity >= $similarity_cutoff) {
                $similarities['name:nome_completo'] = [$name_nome_completo__similarity, $agent1->agent_name, $agent2->agent_nome_completo];
            }
            if($nome_completo_name__similarity >= $similarity_cutoff) {
                $similarities['nome_completo:name'] = [$nome_completo_name__similarity, $agent1->agent_nome_completo, $agent2->agent_name];
            }
         }

        return $similarities;
    }
}