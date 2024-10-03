<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Permissions\Acl;
use mikehaertl\shellcommand\Command;

class WhisperSpeechToText extends AbstractHelper
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


    /**
     * @var string
     */
    protected $cmdParams;

    public function __construct(
        ApiManager $api,
        Acl $acl,
        Logger $logger,
        ChaoticumSeminario $chaoticumSeminario,
        array $config,
        ChaoticumSeminarioSql $sql
    ) {
        $this->api = $api;
        $this->acl = $acl;
        $this->logger = $logger;
        $this->chaoticumSeminario = $chaoticumSeminario;
        $this->config = $config;
        $this->sql = $sql;
    }

    /**
     * gestion des appels à Whisper
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
        switch ($query['service'] ?? null) {
            case 'speechToText':
                $result = $this->speechToText($query);
                break;
            case 'getHistoTags':
                $result = $this->getHistoTags($query);
                break;
            default:
                $result = [];
                break;
        }
        return $result;
    }

    /**
     * Récupère l'historique des tags d'une transcription
     *
     * @param array $params
     * @return array
     */
    protected function getHistoTags($params)
    {
        $result = [];
        $item = !is_object($params['item']) ? $this->api->read('items', $params['item'])->getContent() : $params['item'];
        // Récupère les transcription de l'item
        $param = [];
        $param['resource_classe_id'] = $this->getRc('lexinfo:PartOfSpeech');
        $param['property'][0]['property'] = $this->getProp("oa:hasSource")->id() . "";
        $param['property'][0]['type'] = 'res';
        $param['property'][0]['text'] = (string) $item->id() . "";
        $trans = $this->api->search('items', $param)->getContent();
        foreach ($trans as $t) {
            // Récupère les infos
            $arrMC = $t->value('jdc:hasConcept', ['all' => true]);
            $arrConf = $t->value('lexinfo:confidence', ['all' => true]);
            $arrStart = $t->value('oa:start', ['all' => true]);
            $arrEnd = $t->value('oa:end', ['all' => true]);
            $arrSpeaker = $t->value('dbo:speaker', ['all' => true]);
            $audio = $t->media()[0];
            $video = $audio->value('ma:isFragmentOf')->valueResource();
            $gStart = intval($audio->value('oa:start')->__toString());
            $gEnd = intval($audio->value('oa:end')->__toString());
            $nb = count($arrMC);
            for ($i = 0; $i < $nb; $i++) {
                $itemMC = $arrMC->valueResource();
                if (!isset($result[$itemMC->id()])) {
                    $result[$itemMC->id()] = [
                        'title' => $itemMC->displayTitle(),
                        'uses' => [],
                    ];
                }
                $result[$itemMC->id()]['uses'][] = [
                    'a' => $audio->id(),
                    'v' => $video->id(),
                    's' => $arrStart[$i]->__toString(),
                    'e' => $arrEnd[$i]->__toString(),
                    'gs' => intval($arrStart[$i]->__toString()) + $gStart,
                    'ge' => intval($arrEnd[$i]->__toString()) + $gEnd,
                    'c' => $arrConf[$i]->__toString(),
                    'sp' => $arrSpeaker[$i]->__toString(),
                ];
            }
        }
        return $result;
    }

    /**
     * extraction du text à partir d'un fichier audio
     *
     * @param array $params
     * @return array
     */
    public function speechToText($params)
    {
        static $credentials;

        $rs = $this->acl->userIsAllowed(null, 'create');
        if ($rs) {

            set_time_limit(0);
            $result = [];
            $item = is_object($params['frag'])
                ? $params['frag']
                : $this->api->read('items', $params['frag'])->getContent();
            $medias = $item->media();
            //création des fragments
            foreach ($medias as $media) {
                $class = $media->displayResourceClassLabel();
                if($class!="MediaFragment"){
                    $this->chaoticumSeminario->__invoke([
                        'action' => 'setAllFrag',
                        'media' => $media,
                    ]);
                }
            }
            //traitement des fragments
            $medias = $item->media();
            foreach ($medias as $media) {
                $frags = $this->chaoticumSeminario->__invoke([
                    'action' => 'getMediaFrag',
                    'media' => $media,
                ]);
                foreach ($frags['fragments'] as $f) {
                    if($f->mediaType()==='audio/flac'){
                        $result[] = $this->getSpeechToText($item, $f);
                    }
                }    
            }
            return $result;
        } else {
            return [
                'error' => 'droits insuffisants',
                'message' => 'Vous n’avez pas le droit d’exécuter cette fonction.',
            ];
        }
    }

    /**
     * execute un speech_to_text Whisper
     *
     * @param string $urlBaseFrom
     * @param string $urlBaseTo
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     *
     * @return array
     */
    public function getSpeechToText($item, $media)
    {
        //TODO:gérer les propriétés
        $langue = "French";
        $this->cmdParams = ' --model medium '
            .'--fp16 False '
            .'--word_timestamps True --output_format json --verbose True --append_punctuations True --prepend_punctuations False ';
        $paths = $this->chaoticumSeminario->getFragmentPaths($media, 'flac');

        // Vérifie si le part of speech est présent
        $rt = $this->getRt('Transcription');
        $param = [];
        $param['resource_class_id'] = $rt->resourceClass()->id();
        $param['property'][0]['property'] = 'oa:hasSource';
        $param['property'][0]['type'] = 'res';
        $param['property'][0]['text'] = (string) $media->id();
        $param['property'][1]['property'] = 'dcterms:creator';
        $param['property'][1]['type'] = 'eq';
        $param['property'][1]['text'] = 'WhisperSpeechToText';
        
        $exist = $this->api->search('items', $param)->getContent();
        $result=false;
        if (count($exist)) {
            $result[] = $exist[0];
        } else {
            /*
            if ($urlBaseFrom) {
                $oriUrl = str_replace($urlBaseFrom, $urlBaseTo, $media->originalUrl());
            } else {
                $oriUrl = $media->originalUrl();
            }
            */
            $this->logger->info('Speech to text : original path = ' . $paths['source']);
            //donne le path complet de whisper pour une bonne execution
            $cmd = '/usr/local/bin/whisper ' . $paths['source']
            . ' --language ' . $langue
            . ' --output_dir '. $paths['temp']
            . $this->cmdParams;
            $this->logger->info('Speech to text : ',['cmd'=>$cmd]);
            //POUR MAC positionne le path pour une bonne exécution de ffmpeg
            putenv('PATH=/opt/homebrew/bin');
            $command = new Command($cmd);
            if ($command->execute()) {
                $output =  $command->getOutput();
                $this->logger->info('Speech to text : ',['output'=>$output]);
            } else {
                $error = $command->getError();
                $exitCode = $command->getExitCode();
                $this->logger->info('Speech to text : ',['error'=>$error]);
                return false;
            }
            // Load the JSON file
            $jsonData = file_get_contents($paths['temp'].'/'.str_replace('.flac','.json',$media->filename()));
            // Parse the JSON data
            $data = json_decode($jsonData, true); // Set the second parameter to true to get an associative array
            $t = $this->addTranscription($data, $item, $media);
            if($t)
                $this->logger->info('Speech to text : identifiant de transcription = ' . $t->id());
            else
                $this->logger->info('Speech to text : AUCUNE transcription');

        }
        return $result;        
    }



    /**
     * Ajoute une transcription.
     *
     * @param $alt
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     *
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    public function addTranscription($data, $item, $media)
    {
        //TODO:ajouter la création automatique des ressources template et l'importation des vocabulaires
        $rt = $this->getRt('Transcription');
        $oItem['o:resource_template'] = ['o:id' => $rt->id()];
        $oItem = [];
        $oItem['o:resource_class'] = ['o:id' => $rt->resourceClass()->id()];
        $oItem['dcterms:title'][] = [
            'property_id' => $this->getProp('dcterms:title')->id(),
            '@value' => $data['text'],
            'type' => 'literal',
        ];
        $oItem['oa:hasSource'][] = [
            'property_id' => $this->getProp('oa:hasSource')->id(),
            'value_resource_id' => $media->id(),
            'type' => 'resource',
        ];
        $oItem['ma:isFragmentOf'][] = [
            'property_id' => $this->getProp('ma:isFragmentOf')->id(),
            'value_resource_id' => $item->id(),
            'type' => 'resource',
        ];        
        $oItem['dcterms:creator'][] = [
            'property_id' => $this->getProp('dcterms:creator')->id(),
            '@value' => 'WhisperSpeechToText',
            'type' => 'literal',
        ];        
        $oItem['curation:note'][] = [
            'property_id' => $this->getProp('curation:note')->id(),
            '@value' => $this->cmdParams,
            'type' => 'literal',
        ];        
        

        $curationDataId = $this->getProp('curation:data')->id();
        $mediaId = $media->id();
        $mediaTitle = $media->value('dcterms:isReferencedBy');
        $baseIndexTitle = pathinfo((string) $mediaTitle ?: $media->source(), PATHINFO_FILENAME);
        $video = $media->value('ma:isFragmentOf')->valueResource();

        $segmentId = $this->getProp('lexinfo:segmentation')->id();
        
        foreach ($data['segments'] as $seg) {

            $segment = [];
            $segment['ma:MediaFragment'][] = [
                'property_id' => $this->getProp('ma:hasFragment')->id(),
                'value_resource_id' => $video->id(),
                'type' => 'resource',
            ];
            $segment['lexinfo:partOfSpeech'][] = [
                'property_id' => $this->getProp('lexinfo:partOfSpeech')->id(),
                '@value' => $seg['text'],
                'type' => 'literal',
            ];
            $segment['oa:start'][] = [
                'property_id' => $this->getProp('oa:start')->id(),
                '@value' => (string) $seg['start'],
                'type' => 'literal',
            ];
            $segment['oa:end'][] = [
                'property_id' => $this->getProp('oa:end')->id(),
                '@value' => (string) $seg['end'],
                'type' => 'literal',
            ];
            $segment['lexinfo:confidence'][] = [
                'property_id' => $this->getProp('lexinfo:confidence')->id(),
                '@value' => (string) $seg['temperature'],
                'type' => 'literal',
            ];
            $segment['dbo:speaker'][] = [
                'property_id' => $this->getProp('jdc:hasActant')->id(),
                '@value' => 'no',
                'type' => 'literal',
            ];

            $oItem['lexinfo:segmentation'][] = [
                'property_id' => $segmentId,
                'type' => 'literal',
                '@value' => 's' . $mediaId . '/' . $seg['start'] . '/' . $seg['id'] . ' [' . $baseIndexTitle . ']',
                '@annotation' => $segment,
            ];    
            
            foreach ($seg['words'] as $w) {
                //le concept est le mot sans espace ni apostrophe
                $tag = trim($w['word']);
                $pos = strpos($tag, "'");
                $concept = $this->getTag($pos===false ? $tag : substr($tag, $pos+1));
                $annotation = [];
                $annotation['ma:MediaFragment'][] = [
                    'property_id' => $this->getProp('ma:hasFragment')->id(),
                    'value_resource_id' => $video->id(),
                    'type' => 'resource',
                ];
                $annotation['jdc:hasConcept'][] = [
                    'property_id' => $this->getProp('jdc:hasConcept')->id(),
                    'value_resource_id' => $concept['id'],
                    'type' => 'resource',
                ];
                $annotation['oa:start'][] = [
                    'property_id' => $this->getProp('oa:start')->id(),
                    '@value' => (string) $w['start'],
                    'type' => 'literal',
                ];
                $annotation['oa:end'][] = [
                    'property_id' => $this->getProp('oa:end')->id(),
                    '@value' => (string) $w['end'],
                    'type' => 'literal',
                ];
                $annotation['lexinfo:confidence'][] = [
                    'property_id' => $this->getProp('lexinfo:confidence')->id(),
                    '@value' => (string) $w['probability'],
                    'type' => 'literal',
                ];
                $annotation['dbo:speaker'][] = [
                    'property_id' => $this->getProp('jdc:hasActant')->id(),
                    '@value' => 'no',
                    'type' => 'literal',
                ];
    
                $oItem['curation:data'][] = [
                    'property_id' => $curationDataId,
                    'type' => 'literal',
                    '@value' => 'm' . $mediaId . '/' . $w['start'] . '/' . $concept['id'] . ' [' . $baseIndexTitle . '] (' . $concept['label'] . ')',
                    '@annotation' => $annotation,
                ];
            }            
        }
        return $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
    }

    /**
     * Récupère le tag au format skos
     *
     * @param array $tag
     * @return o:Item
     */
    protected function getTag($tag)
    {
        // Vérifie la présence de l'item pour gérer la création
        /*TROP LONG quand trop de données
        $param = [];
        $param['property'][0]['property'] = $this->getProp("skos:prefLabel")->id() . "";
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $tag;
        $result = $this->api->search('items', $param)->getContent();
        */
        /*Solution de contournement
        1. ajoute les concepts dans une table annexe :
        INSERT INTO concepts (id, label)
        SELECT v.resource_id, v.value
        FROM value v 
        INNER JOIN resource r ON r.id = v.resource_id AND r.resource_class_id = 381
        WHERE v.property_id = 1
        2. requête la table annexe pour vérifier l'existance
        3. ajoute le nouveau concept dans la table annexe
        */
        $result = $this->sql->__invoke([
            'action' => 'getConcept',
            'label' => $tag,
        ]);
        if (count($result)) {
            return $result[0];
        } else {
            $oItem = [];
            $class = $this->getRc('skos:Concept');
            $oItem['o:resource_class'] = ['o:id' => $class->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->getProp("dcterms:title")->id();
            $valueObject['@value'] = $tag;
            $valueObject['type'] = 'literal';
            $oItem["dcterms:title"][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->getProp("skos:prefLabel")->id();
            $valueObject['@value'] = $tag;
            $valueObject['type'] = 'literal';
            $oItem["skos:prefLabel"][] = $valueObject;
            // Création du tag
            $cpt = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            //ajout dans la table annexe
            $this->sql->__invoke([
                'action' => 'addConcept',
                'id' => $cpt->id(),
                'label' => $tag,
            ]);            
            return ['id'=>$cpt->id(),'label'=>$cpt->displayTitle()];
        }
    }

    public function getProp($p)
    {
        if (!isset($this->props[$p])) {
            $this->props[$p] = $this->api->search('properties', ['term' => $p])->getContent()[0];
        }
        return $this->props[$p];
    }

    public function getRc($t)
    {
        if (!isset($this->rcs[$t])) {
            $this->rcs[$t] = $this->api->search('resource_classes', ['term' => $t])->getContent()[0];
        }
        return $this->rcs[$t];
    }

    public function getRt($l)
    {
        if (!isset($this->rts[$l])) {
            $this->rts[$l] = $this->api->read('resource_templates', ['label' => $l])->getContent();
        }
        return $this->rts[$l];
    }
}
