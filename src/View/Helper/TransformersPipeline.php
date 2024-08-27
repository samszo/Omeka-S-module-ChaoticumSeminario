<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Permissions\Acl;
use Omeka\Stdlib\Message;

//https://github.com/CodeWithKyrian/transformers-php
use Codewithkyrian\Transformers\Transformers;
use function Codewithkyrian\Transformers\Pipelines\pipeline;
use function Codewithkyrian\Transformers\Utils\{memoryUsage, timeUsage};


class TransformersPipeline extends AbstractHelper
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
     * @var array
     */
    protected $config;

    /**
     * @var function
     */
    protected $pipeline;


    public function __construct(
        ApiManager $api,
        Acl $acl,
        Logger $logger,
        ChaoticumSeminario $chaoticumSeminario,
        array $config
    ) {
        $this->api = $api;
        $this->acl = $acl;
        $this->logger = $logger;
        $this->chaoticumSeminario = $chaoticumSeminario;
        $this->config = $config;

        //configuration de transformer
        Transformers::setup()
            ->setCacheDir('/var/www/html/omk_deleuze/modules/ChaoticumSeminario/vendor/codewithkyrian/transformers/cache')
            ->apply(); 

    }

    /**
     * gestion des appels à Transformer
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
        return $this->exePipeline($query);
    }

    /**
     * initialisation du pipeline
     *
     * @param string $name
     */
    public function initPipeline($name)
    {
        switch ($name) {
            case 'tokenClassification':
                $this->pipeline = pipeline('token-classification', 'Xenova/bert-base-NER');
                break;
        }
    }

    /**
     * Execution du pipeline après vérification des droits
     *
     * @param array $params
     */
    protected function exePipeline(array $params = [])
    {
        $rs = $this->acl->userIsAllowed(null, 'create');
        if ($rs) {        
            switch ($params['pipeline'] ?? null) {
                case 'tokenClassification':
                    $result = $this->tokenClassification($params);
                    break;
                default:
                    $result = [];
                    break;
            }
        } else {
            return [
                'error' => 'droits insuffisants',
                'message' => 'Vous n’avez pas le droit d’exécuter cette fonction.',
            ];
        }
        return $result;

    }


    /**
     * Extrait les entités nommées d'un texte.
     *
     * @param array $params
     */
    protected function tokenClassification(array $params = [])
    {
        $item = !is_object($params['item'])
        ? $this->api->read('items', $params['item'])->getContent()
        : $params['item'];
        $text = $item->displayTitle(); 
        $this->logger->info(new Message('tokenClassification '.$item->id().' : '.$text));

        $data = json_decode(json_encode($item), true);

        $pipeline = pipeline('token-classification', 'Xenova/bert-base-NER');
        $rs = $pipeline($text,aggregationStrategy:'first');

        //traitement des résultats
        foreach ($rs as $r) {
            $e = $this->addExtraction($r, $item, 'token-classification', 'Xenova/bert-base-NER');
        }

        $this->logger->info(new Message('tokenClassification result : '.count($rs)));
        return $rs;
    }

    /**
     * Ajoute une extraction.
     *
     * @param array $r
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param array $pipeline
     * @param array $model
     *
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    protected function addExtraction($r, $item, $pipeline, $model)
    {            
        //récupère les données d'origine
        $data = json_decode(json_encode($item), true);
        
        //vérifie si la donnée existe
        $ref = $pipeline.' : '.$r['entity_group']. ' ('.$model.' : '.$r['score'].')';        
        $existe = false;
        if(isset($data['curation:data'])){
            foreach ($data['curation:data'] as $c) {
                if($c['@value'] == $ref)$existe=true;
            }
        }
        if($existe)return $item;

        $extraction = [];
        $extraction['curation:category'][] = [
            'property_id' => $this->chaoticumSeminario->getProperty('curation:category')->id(),
            '@value' => (string) $r['entity_group'],
            'type' => 'literal',
        ];
        $extraction['lexinfo:confidence'][] = [
            'property_id' => $this->chaoticumSeminario->getProperty('lexinfo:confidence')->id(),
            '@value' => (string) $r['score'],
            'type' => 'literal',
        ];
        $extraction['jdc:iaModel'][] = [
            'property_id' => $this->chaoticumSeminario->getProperty('jdc:iaModel')->id(),
            '@value' => (string) $model,
            'type' => 'literal',
        ];        
        $extraction['jdc:pipeline'][] = [
            'property_id' => $this->chaoticumSeminario->getProperty('jdc:pipeline')->id(),
            '@value' => (string) $pipeline,
            'type' => 'literal',
        ];        
        $data['curation:data'][] = [
            'property_id' => $this->chaoticumSeminario->getProperty('curation:data')->id(),
            'type' => 'literal',
            '@value' => $ref,
            '@annotation' => $extraction,
        ];
        $this->api->update('items', $item->id(), $data, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
        $this->logger->info(new Message(
            'addExtraction %d.', // @translate
            $item->id()
        ));

    }

}
