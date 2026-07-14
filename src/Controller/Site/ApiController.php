<?php declare(strict_types=1);

namespace ChaoticumSeminario\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Api\Manager as ApiManager;

class ApiController extends AbstractActionController
{
    const CONFERENCE_RESOURCE_CLASS_ID = 47;

    protected $api;
    protected $chaoticumSeminarioSql;
    protected $acl;

    public function __construct(ApiManager $api, $chaoticumSeminarioSql, $acl)
    {
        $this->api = $api;
        $this->chaoticumSeminarioSql = $chaoticumSeminarioSql;
        $this->acl = $acl;
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
        ]);

        $transcriptions = [];
        foreach ($rows as $row) {
            $idTrans = $row['idTrans'];
            if (!isset($transcriptions[$idTrans])) {
                $transcriptions[$idTrans] = [
                    'idTrans' => $idTrans,
                    'idFrag' => $row['idFrag'],
                    'start' => $row['startFrag'],
                    'end' => $row['endFrag'],
                    'face' => $row['face'],
                    'plage' => $row['plage'],
                    'bnf' => $row['source1'],
                    'creator' => $row['creator'],
                    'source' => $row['source3'],
                    'concepts' => [],
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

        return new JsonModel([
            'idConf' => $idConf,
            'transcriptions' => array_values($transcriptions),
        ]);
    }
}
