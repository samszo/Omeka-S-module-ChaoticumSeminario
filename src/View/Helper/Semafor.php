<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Laminas\Http\Headers;
use Omeka\Api\Manager as ApiManager;
use Omeka\Permissions\Acl;
use Omeka\Api\Exception\RuntimeException;

class Semafor extends AbstractHelper
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Acl
     */
    protected $acl;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     *
     * @var chaoticumSeminario
     */
    protected $cs;
    
    /**
     *
     * @var sql
     */
    protected $sql;

    /**
     * @var array
     */
    protected $config;

    protected $props;

    protected $rcs;

    protected $rts;

    protected $credentials;

    protected $client;

    protected $workspace;
    protected $docs;
    protected $propRef;
    protected $headers;
    protected $nbResult;
    protected $scope;

    public function __construct(
        ApiManager $api,
        Acl $acl,
        Logger $logger,
        ChaoticumSeminario $chaoticumSeminario,
        array $config,
        ChaoticumSeminarioSql $sql,
        SemaforCredentials $credentials,
        $client
    ) {
        $this->api = $api;
        $this->acl = $acl;
        $this->logger = $logger;
        $this->cs = $chaoticumSeminario;
        $this->config = $config;
        $this->sql = $sql;
        $this->credentials = $credentials->__invoke();
        $this->client = $client;
        $this->nbResult = $this->config['chaoticumseminario']['config']['chaoticumseminario_semafor_nb_results'];

        $this->headers = [
            'Authorization: Bearer '.$this->credentials['token'],
            'accept: application/json',
        ];        

        $this->setClientApi();   
        //récupère la propriété de référence
        $this->propRef = $this->api->search('properties', ['term' => 'jdc:ragRef'])->getContent()[0];

    }    

    /**
     * gestion des appels à AnythingLLM
     *
     * @param \Omeka\View\Helper\Params $params
     */
    public function __invoke($params): array
    {
        if (is_array($params)) {
            $query = $params;
        } else {
            $query = $params->fromQuery();
            // $post = $params->fromPost();
        }
        switch ($query['action'] ?? null) {
            case 'addCompetences':
                $result = $this->addCompetences($query);
                break;
            default:
                $result = [];
                break;
        }
        return $result;
    }

    /**
     * Ajoute les compétences à une ressource
     *
     * @param array $query
     * 
     */
    protected function addCompetences($query){

        $item = $query["item"]; 
        $this->scope = $this->api->read('properties',$query["scope"])->getContent();       

        //vérifie que les compétences ne sont pas déjà dans la base
        $title = $query["action"]." to -".$item->displayTitle()."- from <".$this->scope->term().">";
        $compExist = $this->api->search('items', ['dcterms:title' => $title])->getContent();
        if(count($compExist)>0){
            $this->logger->info('Les compétences pour la ressource '.$item->id().' existent déjà.');
            switch ($query["type"]) {
                case 'update':
                    $this->api->delete('items', $compExist[0]->id());
                    break;                
                case 'delete':
                    $this->api->delete('items', $compExist[0]->id());
                    return ['OK'];
                    break;                
            }
        }
        //TODO:prendre en compte le type pour insérer ou remplacer ou supprimer
        //recherche les compétence dans avec l'api Semafor
        $search = $this->getSearch($item,$this->scope);
        $competences = $this->getResponse(($this->credentials['url']
            ."Competences?query=".urlencode($search)
            ."&nb_results=".$this->nbResult));

        //enregsitre les compétences dans omk
        $dataComp = [];
        $dataComp['dcterms:title']=[['type'=>'literal','@value'=>$title,'property_id' => $this->cs->getProperty('dcterms:title')->id()]];
        //$dataComp['dcterms:description']=[['type'=>'literal','@value'=>$search,'property_id' => $this->cs->getProperty('dcterms:description')->id()]];
        $dataComp['dcterms:isReferencedBy']=[['type'=>'literal','@value'=>$competences->search_id,'property_id' => $this->cs->getProperty('dcterms:isReferencedBy')->id()]];
        $dataComp['dcterms:source'][] = [
            'property_id' => $this->cs->getProperty('dcterms:source')->id(),
            'value_resource_id' => $item->id(),
            'type' => 'resource',
        ];
        $dataComp['oa:hasScope']=[['type'=>'literal','@value'=>$this->scope->term(),'property_id' => $this->cs->getProperty('oa:hasScope')->id()]];        
        $dataComp['o:resource_template'] = ['o:id' => $this->cs->getResourceTemplate('Compétences du document')->id()];
        $dataComp['o:resource_class'] = ['o:id' => $this->cs->getResourceClass('rome:semaforResult')->id()];
        $dataComp['rome:hasCompetence']=[];
        foreach($competences->search_results as $comp){
            $annotation = [];
            $annotation['lexinfo:confidence'][] = [
                'property_id' => $this->cs->getProperty('lexinfo:confidence')->id(),
                '@value' => $comp->similarity."",
                'type' => 'literal',
            ];
            $annotation['dcterms:valid'][] = [
                'property_id' => $this->cs->getProperty('dcterms:valid')->id(),
                '@value' => $comp->data->valid_from,
                'type' => 'literal',
            ];
            $dataComp['rome:hasCompetence'][] = [
                'property_id' => $this->cs->getProperty('rome:hasCompetence')->id(),
                'value_resource_id' => $this->getsetCompetence($comp)->id(),
                'type' => 'resource',
                '@annotation' => $annotation
            ];  
        }
        $response = $this->api->create('items', $dataComp, [], ['continueOnError' => true])->getContent();
        $this->logger->info('La compétence '.$title.' est ajoutée avec l\'id '.$response->id().'.');

        return ['OK'];
    }

    /**
     * récupère la recherche suivant le scope
     *
     * @param object $comp
     * 
     * @return object
     */
    protected function getsetCompetence($comp):object
    {
        $compExist = $this->api->search('items', ['rome:code' => $comp->data->code_ogr])->getContent();
        if(count($compExist)>0){
            return $compExist[0];
        }
        //ajoute la compétence
        $dataComp = [];
        $dataComp['o:resource_class'] = ['o:id' => $this->cs->getResourceClass('rome:competence')->id()];
        $dataComp['o:resource_template'] = ['o:id' => $this->cs->getResourceTemplate('ROME Référence')->id()];
        $dataComp['rome:libelle']=[['type'=>'literal','@value'=>$comp->data->titre,'property_id' => $this->cs->getProperty('rome:libelle')->id()]];
        $dataComp['rome:code']=[['type'=>'literal','@value'=>$comp->data->code_ogr,'property_id' => $this->cs->getProperty('rome:code')->id()]];
        $response = $this->api->create('items', $dataComp, [], ['continueOnError' => true])->getContent();        
        $this->logger->info('La compétence '.$comp->data->titre.' est ajoutée avec l\'id '.$response->id().'.');
        return $response;

    }
      
    /**
     * récupère la recherche suivant le scope
     *
     * @param object $item
     * @param object $scope
     * 
     * @return string
     */
    protected function getSearch($item,$scope):string
    {
        $search = "";
        $values = $item->value($scope->term(), ['all' => true]);            
        foreach($values as $value){
            if($search!="")$search.=" ";
            $vr = $value->valueResource();
            $search.= $vr ? $vr->displayTitle() : $value->__toString();
        }
        return $search; 
    }

    /**
     * Set the HTTP client to use during this import.
     */
    public function setClientApi()
    {

        //options pour le ssl inadéquate
        $httpClientOptions = array(
            'adapter' => 'Zend\Http\Client\Adapter\Socket',
            'persistent' => false,
            'sslverifypeer' => false,
            'sslallowselfsigned' => false,
            'sslusecontext' => false,
            'ssl' => array(
                'verify_peer' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
            ),
            'timeout' => 20,
        );
        $this->client->setOptions($httpClientOptions);

        //ajoute les headers avec la clef
        $this->client->setHeaders($this->headers);

    }


    /**
     * Get a response from the AnythingLLM API.
     *
     * @param string $url
     * @return Response
     */
    public function getResponse($url,$method="GET",$params=false)
    {
        //limite la taille de l'url à 700 caractères
        $url = substr($url, 0, 700);
        $this->client->resetParameters();
        $this->client->setHeaders($this->headers);
        $this->client->setMethod($method);
        if($params)$this->client->setParameterPost($params);
        $response = $this->client->setUri($url)->send();
        if (!$response->isSuccess()) {
            throw new RuntimeException(sprintf(
                'Requested "%s" got "%s".', $url, $response->renderStatusLine()
            ));
        }
        return json_decode($response->getBody());
    }

}
