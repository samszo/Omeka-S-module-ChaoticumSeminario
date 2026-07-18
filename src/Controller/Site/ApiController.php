<?php declare(strict_types=1);

namespace ChaoticumSeminario\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Manager as ApiManager;
use Omeka\Job\Dispatcher;

class ApiController extends AbstractActionController
{
    const CONFERENCE_RESOURCE_CLASS_ID = 47;

    protected $api;
    protected $chaoticumSeminarioSql;
    protected $acl;
    protected $dispatcher;

    public function __construct(ApiManager $api, $chaoticumSeminarioSql, $acl, Dispatcher $dispatcher)
    {
        $this->api = $api;
        $this->chaoticumSeminarioSql = $chaoticumSeminarioSql;
        $this->acl = $acl;
        $this->dispatcher = $dispatcher;
    }

    /**
     * GET /chaoticum-seminario-api/conferences
     * Liste des conférences (items resource_class_id=47).
     */
    public function conferencesAction()
    {
        $query = $this->params()->fromQuery();
        $query['resource_class_id'] = self::CONFERENCE_RESOURCE_CLASS_ID;

        $response = $this->api->search('items', $query);
        $conferences = array_map(function ($item) {
            return $item->jsonSerialize();
        }, $response->getContent());

        return new JsonModel([
            'total' => $response->getTotalResults(),
            'conferences' => $conferences,
        ]);
    }

    /**
     * GET /chaoticum-seminario-api/listconferences
     * Liste les titres de conférences (items resource_class_id=47).
     */
    public function listconferencesAction()
    {
        $rows = ($this->chaoticumSeminarioSql)([
            'action' => 'getConferences',
        ]);
        return new JsonModel(array_values($rows));
    }

    /**
     * GET /chaoticum-seminario-api/delconf
     * supprime une conférence et tous les objets liés
     */
    public function delconfAction()
    {
        $idConf = $this->params()->fromQuery('idConf');
        if (!$idConf) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Missing idConf parameter']);
        }

        $allow = $this->acl->userIsAllowed(null, 'delete');
        if (!$allow) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Not allow to do this']);
        }

        //
        $arr = [1871,9068,15974,23280,29891,35493,40562,45517,47679,52563,63018];
        $rsTot = [];
        foreach ($arr as $idConf) {

            try {
                $item = $this->api->read('items', $idConf,[],["continueOnError"=>true])->getContent();
            } catch (\Throwable $th) {
                /*
                $response = $this->getResponse();
                $response->setStatusCode(400);
                return new JsonModel(['error' => $idConf." - Item don't exist"]);
                */
                $rsTot[]=$idConf." - Item don't exist";
                continue;
            }


            $arr = $item->getJsonLd();
            if(!isset($arr["o:resource_class"]) || $arr["o:resource_class"]->id()!=self::CONFERENCE_RESOURCE_CLASS_ID){
                $response = $this->getResponse();
                $response->setStatusCode(400);
                return new JsonModel(['error' => 'Item is not a conference']);
            }
            
            $rs = [];
            $subjects = $item->subjectValues();
            if (isset($subjects['isFragmentOf'])) {
                $rs["fragments"]=[];
                foreach ($subjects['isFragmentOf'] as $v) {
                    $vr = $v['val']->resource();
                    if (!$vr) continue;
                    $action=["id"=>$vr->id(),"title"=>$vr->displayTitle(),"action"=>""];
                    $action["action"]=$this->api->delete('items', $action["id"]);                
                    $rs["fragments"][]=$action;
                }
            }
            $rs["action"]=$this->api->delete('items', $idConf); 
            $rsTot[]=$rs;               
        }

        return new JsonModel($rsTot);
    }


    /**
     * GET /chaoticum-seminario-api/transcriptions?idConf=123
     * Liste des transcriptions d'une conférence (via timelineConceptAnnexe).
     */
    public function transcriptionsAction()
    {
        $idConf = $this->params()->fromQuery('idConf');
        if (!$idConf) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Missing idConf parameter']);
        }

        $rows = ($this->chaoticumSeminarioSql)([
            'action' => 'timelineConceptAnnexe',
            'idConf' => $idConf,
            'getTexte'=>true
        ]);
        $transcriptions = $this->mapTranscriptions($rows["timeline"],$rows["scores"]);

        return new JsonModel([
            'idConf' => $idConf,
            'transcriptions' => array_values($transcriptions),
        ]);
    }

   /**
     * GET /chaoticum-seminario-api/cherche?trouve=bidule
     * Liste des transcriptions d'une conférence (via timelineConceptAnnexe).
     */
    public function chercheAction()
    {
        $trouve = $this->params()->fromQuery('trouve');
        if (!$trouve) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Missing trouve parameter']);
        }

        $rows = ($this->chaoticumSeminarioSql)([
            'action' => 'timelineConceptAnnexe',
            'cherche' => $trouve,
        ]);

        $transcriptions = $this->mapTranscriptions($rows['timeline'],$rows['scores']);

        return new JsonModel([
            'trouve' => $trouve,
            'transcriptions' => array_values($transcriptions),
        ]);
    }

    /**
     * GET /chaoticum-seminario-api/relancer?idFrag=123&modele=whisper
     * Relance la transcription d'un fragment avec un autre modèle.
     * Nécessite un utilisateur authentifié (key_identity/key_credential) ayant les droits.
     */
    public function relancerAction()
    {
        if (!$this->identity()) {
            $response = $this->getResponse();
            $response->setStatusCode(401);
            return new JsonModel(['error' => 'Authentification requise']);
        }

        $idFrag = $this->params()->fromQuery('idFrag');
        $modele = $this->params()->fromQuery('modele');
        $jobs = [
            'whisper' => \ChaoticumSeminario\Job\WhisperSpeechToText::class,
            'google' => \ChaoticumSeminario\Job\GoogleSpeechToText::class,
        ];

        if (!$idFrag || !isset($jobs[$modele])) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Paramètres idFrag et modele (whisper|google) requis']);
        }

        try {
            $job = $this->dispatcher->dispatch($jobs[$modele], [
                'ids' => [(int) $idFrag],
                'service' => $modele === 'whisper' ? 'Whisper' : 'Google',
            ]);
        } catch (\Throwable $e) {
            $response = $this->getResponse();
            $response->setStatusCode(403);
            return new JsonModel(['error' => 'Droits insuffisants pour relancer une transcription : '.$e->getMessage()]);
        }

        return new JsonModel(['job' => $job->getId()]);
    }

    /**
     * GET /chaoticum-seminario-api/signaler?idConf=1&idTrans=2&type=personne&texte=...&lien=...
     * Crée un signalement léger (à trier plus tard dans l'admin Omeka).
     * Nécessite un utilisateur authentifié (key_identity/key_credential) ayant les droits.
     */
    public function signalerAction()
    {
        if (!$this->identity()) {
            $response = $this->getResponse();
            $response->setStatusCode(401);
            return new JsonModel(['error' => 'Authentification requise']);
        }

        $idConf = $this->params()->fromQuery('idConf');
        $idTrans = $this->params()->fromQuery('idTrans');
        $type = $this->params()->fromQuery('type');
        $texte = trim((string) $this->params()->fromQuery('texte'));
        $lien = $this->params()->fromQuery('lien');

        $typesLabels = [
            'correction' => 'Correction de transcription',
            'personne' => 'Référence à une personne',
            'oeuvre' => 'Référence à une œuvre',
            'date' => 'Référence à une date ou une période',
            'lieu' => 'Référence à un lieu',
        ];

        if (!$idConf || !$idTrans || !isset($typesLabels[$type]) || !$texte) {
            $response = $this->getResponse();
            $response->setStatusCode(400);
            return new JsonModel(['error' => 'Paramètres idConf, idTrans, type et texte requis']);
        }

        try {
            $data = [];
            $data['dcterms:title'][] = [
                'property_id' => $this->getPropertyId('dcterms:title'),
                '@value' => $typesLabels[$type].' — fragment #'.$idTrans,
                'type' => 'literal',
            ];
            $data['dcterms:type'][] = [
                'property_id' => $this->getPropertyId('dcterms:type'),
                '@value' => $type,
                'type' => 'literal',
            ];
            $data['dcterms:description'][] = [
                'property_id' => $this->getPropertyId('dcterms:description'),
                '@value' => $texte,
                'type' => 'literal',
            ];
            if ($lien) {
                $data['dcterms:references'][] = [
                    'property_id' => $this->getPropertyId('dcterms:references'),
                    '@id' => $lien,
                    'type' => 'uri',
                ];
            }

            $item = $this->api->create('items', $data)->getContent();
        } catch (\Throwable $e) {
            $response = $this->getResponse();
            $response->setStatusCode(403);
            return new JsonModel(['error' => 'Droits insuffisants pour créer un signalement : '.$e->getMessage()]);
        }

        return new JsonModel(['id' => $item->id()]);
    }

    protected function getPropertyId($term)
    {
        static $cache = [];
        if (!isset($cache[$term])) {
            $result = $this->api->search('properties', ['term' => $term])->getContent();
            $cache[$term] = $result ? $result[0]->id() : null;
        }
        return $cache[$term];
    }

    function mapTranscriptions(array $rows, array $scores=[]){
        $transcriptions = [];
        foreach ($rows as $row) {
            $idTrans = $row['idTrans'];
            if (!isset($transcriptions[$idTrans])) {
                //récupère le score de pertinence (recherche uniquement)
                $score=0;
                foreach ($scores as $s) {
                    if ($s['id']==$idTrans) {
                        $score += $s['score'];
                    }
                }
                $transcriptions[$idTrans] = [
                    'idTrans' => $idTrans,
                    'idFrag' => $row['idFrag'],
                    'idConf' => $row['idConf'],
                    'theme' => $row['titleConf'],
                    'num' => $row['num'],
                    'start' => $row['startFrag'],
                    'end' => $row['endFrag'],
                    'face' => $row['face'],
                    'plage' => $row['plage'],
                    'bnf' => $row['source1'],
                    'gallica' => $row['source2'],
                    'creator' => $row['creator'],
                    'source' => $row['source3'],
                    'texte' => $row['texte'],
                    'concepts' => [],
                    'score' => $score,
                ];
            }
            $transcriptions[$idTrans]['concepts'][] = [
                'idAnno' => $row['idAnno'],
                'idConcept' => $row['idCpt'],
                'title' => $row['titleCpt'],
                'start' => $row['startCpt'],
                'end' => $row['endCpt'],
                'confidence' => $row['confiance'],
            ];
        }
        return $transcriptions;
    }
    

}
