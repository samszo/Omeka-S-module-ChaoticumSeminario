<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Laminas\Http\Headers;
use Omeka\Api\Manager as ApiManager;
use Omeka\Permissions\Acl;
use mikehaertl\shellcommand\Command;

class AnythingLLM extends AbstractHelper
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
    protected $chaoticumSeminario;
    
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

    public function __construct(
        ApiManager $api,
        Acl $acl,
        Logger $logger,
        ChaoticumSeminario $chaoticumSeminario,
        array $config,
        ChaoticumSeminarioSql $sql,
        AnythingLLMCredentials $credentials,
        $client
    ) {
        $this->api = $api;
        $this->acl = $acl;
        $this->logger = $logger;
        $this->chaoticumSeminario = $chaoticumSeminario;
        $this->config = $config;
        $this->sql = $sql;
        $this->credentials = $credentials->__invoke();
        $this->client = $client;

        $this->headers = [
            'Authorization: Bearer '.$this->credentials['key'],
            'accept: application/json',
        ];        
        $this->setClientApi();   
        //récupère la propriété de référence
        $this->propRef = $this->api->search('properties', ['term' => 'dcterms:isReferencedBy'])->getContent()[0];

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
            case 'addDoc':
                $result = $this->addDoc($query['item']);
                break;
            default:
                $result = [];
                break;
        }
        return $result;
    }

    /**
     * Récupère le workspace
     *
     * @param array $params
     * 
     */
    public function getWorkspace(){

        $response = $this->getResponse($this->credentials['url'].'workspace/'.$this->credentials['ws']);
        $this->workspace = $response->workspace[0];

        //récupère les documents
        $this->docs = [];
        foreach ($this->workspace->documents as $doc) {
            $doc->metadata = json_decode($doc->metadata);
            $id = intval(explode('.',explode('-',$doc->metadata->title)[1])[0]);
            $this->docs[$id]=$doc;
        }

    }

    /**
     * Ajoute un resource dans le RAG
     *
     * @param object $item
     * 
     */
    protected function addDoc($item){

        //vérifie que la ressource n'est pas déjà dans le RAG
        if(!isset($this->docs[$item->id()])){
            //ajoute le titre dans le RAG
            $this->addDocToWorkspace($item);
            $this->logger->info('La resource '.$item->id().' est ajoutée dans le RAG');
        }else{
            //vérifie si la référence est définie
            $isRef = $item->value('dcterms:isReferencedBy');
            if($isRef){
                $this->logger->info('La resource '.$item->id().' est déjà référencée : '.$isRef->asHtml());                
            }else{
                //met à jour la référence
                $this->updateRef($item);
                $this->logger->info('Nouvelle référence de la resource '.$item->id().' : '.$this->docs[$item->id()]->docpath);                
            }
        }
        return ['OK'];
    }

    /**
     * ajoute le doc dans le workspace
     *
     * @param object $item
     * 
     */
    protected function addDocToWorkspace($item){
        $doc = $this->addDocToAnythingLLM($item);
        $params = [
            "adds"=> [
                $doc->documents[0]->location
            ]
        ];
        $ws = $this->getResponse($this->credentials['url'].'workspace/'.$this->credentials['ws'].'/update-embeddings',"POST",$params);
        $this->docs[$item->id()]=$ws->workspace->documents[count($ws->workspace->documents)-1];
        $this->updateRef($item);            
    }
    /**
     * ajoute le doc dans le workspace
     *
     * @param object $item
     * 
     */
    protected function addDocToAnythingLLM($item){
        $frag = $item->value('ma:isFragmentOf')->valueResource();
        $source = $item->value('oa:hasSource')->valueResource();
        $txt = "#".$frag->displayTitle()."\n"
            ."##".$source->displayTitle()."\n"
            .$item->displayTitle();
        $params = [
            "textContent"=>$txt,
            "metadata"=>[
                "title"=>"Transcription ".$item->id(),
                "idTrans"=>$item->id(),
                "docSource"=>$source->id(),
                "description"=>"cours_".$frag->id()
                    ."-frag_".$source->id(),
                "idSource"=>$source->id(),
                "idCours"=>$frag->id()
            ]
        ];
        return $this->getResponse($this->credentials['url'].'document/raw-text',"POST",$params);
    }


    /**
     * Mise à jour de la référence
     *
     * @param object $item
     * 
     */
    protected function updateRef($item){

        $dataItem = json_decode(json_encode($item), true);
        $valueObject = [];
        $valueObject['property_id'] = $this->propRef->id();
        $valueObject['@value'] = (string) $this->docs[$item->id()]->docpath;
        $valueObject['type'] = 'literal';
        $dataItem[$this->propRef->term()][]=$valueObject;
        $response = $this->api->update('items', $dataItem['o:id'], $dataItem, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);

    }
           

    /**
     * Vérifie si la resource est dans le RAG
     *
     * @param array $params
     * @return array
     */
    protected function isDoc($params){
        $uri = $this->credentials['url'].'document/raw-transcription-22520-7c7a7d1a-b14a-44e2-9dd9-772e80443217.json';
        $response = $this->getResponse($uri);
        return $response->getBody()=="Not Found" ? false : true;
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
        $this->client->resetParameters();
        $this->client->setHeaders($this->headers);
        $this->client->setMethod($method);
        if($params)$this->client->setParameterPost($params);
        $response = $this->client->setUri($url)->send();
        if (!$response->isSuccess()) {
            throw new Exception\RuntimeException(sprintf(
                'Requested "%s" got "%s".', $url, $response->renderStatusLine()
            ));
        }
        return json_decode($response->getBody());
    }

}
