<?php
namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\Filter\RealPath;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Video\Ogg;
use \Datetime;

class ChaoticumSeminarioViewHelper extends AbstractHelper
{
    protected $api;
    protected $conn;
    protected $logger;
    protected $services;
    protected $basePath; 
    protected $entityManager;   
    protected $store;
    protected $tempFileFactory;
    protected $cli;
    protected $ffmpeg;   

    public function __construct($services)
    {
      $this->api = $services['api'];
      $this->conn = $services['conn'];
      $this->basePath = $services['basePath'];      
      $this->logger = $services['logger'];
      $this->entityManager = $services['entityManager'];      
      $this->store = $services['store'];      
      $this->tempFileFactory = $services['tempFileFactory'];   
      $this->cli = $services['cli'];   
      $this->ffmpeg = FFMpeg::create();
      $this->ffprobe = FFProbe::create();


    }

    /**
     * Initialisation du séminaire
     *
     * @param array     $params paramètres du séminaire
     * 
     * @return array
     */
    public function __invoke($params=[])
    {
       // Note: ffmpeg supports urls as input and output.
       if (!($this->store instanceof \Omeka\File\Store\Local)) {
            $this->logger->err(
                'A local store is required to derivate media currently.' // @translate
            );
            return false;
        }

        //récupère les propriétés
        if(!isset($params['nom'])){            
            $date = new DateTime('NOW');
            $params['nom']="Séminaire ".$date->format('Y-m-d H:i:s');            
        }

        $oMedia = $this->api->read('media', $params['idMedia'])->getContent();

        $fragments = $this->getVideoFrag($oMedia);
        //$fragments = $this->setVideoFrag($oMedia, $params);
        
        return ['media'=>$oMedia,'fragments'=>$fragments];

    }

    /**
     * récupère les fragments d'un média'
     *
     * @param oMedia    $media média concerné par le fractionnement
     * 
     * @return array
     */
    function getVideoFrag($media){
        $pIsFragmentOf = $this->api->search('properties', ['term' => 'ma:isFragmentOf'])->getContent()[0]; 
        $param = array();
        $param['property'][0]['property']= $pIsFragmentOf->id()."";
        $param['property'][0]['type']='res';
        $param['property'][0]['text']=$media->item()->id().""; 
        $result = $this->api->search('items',$param)->getContent();
        return $result;
        
    }

    /**
     * fragmente une vidéo de manière aléatoire
     * merci à Daniel Berthereau pour le module DeritativeMedia
     *
     * @param oMedia    $media média concerné par le fractionnement
     * @param array     $params paramètre de l'action
     * 
     * @return array
     */
    function setVideoFrag($media, $params){


        $mainMediaType = strtok((string) $media->mediaType(), '/');
        $filename = $media->filename();
        $sourcePath = $this->basePath . '/original/' . $filename;

        if (!file_exists($sourcePath)) {
            $this->logger->warn(
                'Media #{media_id}: the original file does not exist ({filename})', // @translate
                ['media_id' => $media->id(), 'filename' => 'original/' . $filename]
            );
            return false;
        }

        if (!is_readable($sourcePath)) {
            $this->logger->warn(
                'Media #{media_id}: the original file is not readable ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => 'original/' . $filename]
            );
            return false;
        }


        $realpath = new RealPath(false);

        $storageId = $media->storageId();
        $pattern = "mp4/{filename}.mp4";
        $folder = mb_substr($pattern, 0, mb_strpos($pattern, '/{filename}.'));
        $basename = str_replace('{filename}', $storageId, mb_substr($pattern, mb_strpos($pattern, '/{filename}.') + 1));
        $storageName = $folder . '/' . $basename;
        $chaoticumPath = $this->basePath . '/' . $storageName;

        // Another security check.
        if ($chaoticumPath !== $realpath->filter($chaoticumPath)) {
            $this->logger->err(
                'Media #{media_id}: the chaoticum pattern "{pattern}" does not create a real path.', // @translate
                ['media_id' => $media->id(), 'pattern' => $pattern]
            );
            return false;
        }

        if (file_exists($chaoticumPath) && !is_writeable($chaoticumPath)) {
            $this->logger->warn(
                'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $storageName]
            );
            return false;
        }

        // The path can contain a directory (module Archive repertory).
        // TODO To be removed: this is managed by the store anyway.
        $dirpath = dirname($chaoticumPath);
        if (file_exists($dirpath)) {
            if (!is_dir($dirpath) || !is_writable($dirpath)) {
                $this->logger->warn(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return false;
            }
        } else {
            $result = @mkdir($dirpath, 0755, true);
            if (!$result) {
                $this->logger->err(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return false;
            }
        }

        //paramètrage de ffmpeg
        $video = $this->ffmpeg->open($sourcePath);
        $format = $this->ffprobe
            ->format($sourcePath); // extracts file informations
        $stream = $this->ffprobe
            ->streams($sourcePath) // extracts streams informations
            ->videos()                      // filters video streams
            ->first(); 
        $width = $stream->get('width');              
        $height = $stream->get('height');              
        $duration = $format->get('duration');             // returns the duration property
        
        $this->logger->info(
            'Media #{media_id}: creating chaoticum media "{filename}".', // @translate
            ['media_id' => $media->id(), 'filename' => $storageName]
        );


        // vérifie l'existence du dossier temporaire
        $tempPath = $this->basePath . '/tmp';
        if (file_exists($tempPath)) {
            if (!is_dir($tempPath) || !is_writable($tempPath)) {
                $this->logger->warn(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return false;
            }
        } else {
            $result = @mkdir($tempPath, 0755, true);
            if (!$result) {
                $this->logger->err(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return false;
            }
        }

        //extraction du fragment
        $tDur = 6;
        $tDeb = random_int(0, $duration-$tDur);
        $tempFilename = 'chaosMedia-'.$media->id().'-'.$tDeb.'-'.$tDur.'.ogg';
        $tempPath = $tempPath .'/'.$tempFilename;
        $clip = $video->clip(TimeCode::fromSeconds($tDeb), TimeCode::fromSeconds($tDur));
        $clip->save(new Ogg(), $tempPath);

        if (!file_exists($tempPath) || !filesize($tempPath)) {
            $this->logger->err(
                'Media #{media_id}: chaoticum media is empty ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $storageName]
            );
            return false;
        }
        //réécriture de l'url
        $tempUrl = str_replace('original','tmp',$media->originalUrl());
        $tempUrl = str_replace($media->filename(),$tempFilename,$tempUrl);

        //création du média chaotique
        $mediaFrag = $this->ajouteMedia($media, ['tempPath'=>$tempUrl,'tDeb'=>$tDeb,'tDur'=>$tDur]);        
        if($mediaFrag){
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $mediaFrag->filename()]
            );    
        }else{
            $this->logger->err(
                'Media #{media_id}: chaoticum item is empty ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $tempUrl]
            );
            return false;
        }

        //suprime le fichier temporaire


        return $mediaFrag;

    }


    /**
     * Création du média chaotique
     *
     * @param oMedia    $media
     * @param array     $data
     * @return oItem
     */
    protected function ajouteMedia($media, $data)
    {
        $this->logger->info('Media '.$media->id().' : chaoticum ajouteMedia.',$data);    

        //récupère l'item du média
        $itemOri = $media->item();

        //construction de la référence
        $ref = 'ChaoticumItem_'.$itemOri->id().'_'.$media->id().'_'.$data['tDeb'].'_'.$data['tDur'];

        //récupère la propriétés pour la référence
        $pIsRefBy = $this->api->search('properties', ['term' => 'dcterms:isReferencedBy'])->getContent()[0];        

        //vérifie la présence de l'item chaotique pour ne pas la récréer inutilement
        $param = array();
        $param['property'][0]['property']= $pIsRefBy->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));

        if(count($result)){
            return $result[0]->getContent();            			
        }else{            
            //creation de l'item chaotique
            $idClassFrag =  $this->api->search('resource_classes', ['term' => 'ma:MediaFragment'])->getContent()[0];        
            $pTitle = $this->api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]; 
            $pIsFragmentOf = $this->api->search('properties', ['term' => 'ma:isFragmentOf'])->getContent()[0]; 
            $pDuration = $this->api->search('properties', ['term' => 'ma:duration'])->getContent()[0];
            $pStart = $this->api->search('properties', ['term' => 'schema:startTime'])->getContent()[0];               

            $oItem = [];
            $oItem['o:resource_class'] = ['o:id' => $idClassFrag->id()];
            //$oItem['o:resource_templates'] = ['o:id' => $this->resourceTemplate['Cartographie des expressions']->id()];
            $valueObject = [];
            $valueObject['property_id'] = $pTitle->id();
            $valueObject['@value'] = $ref;
            $valueObject['type'] = 'literal';
            $oItem[$pTitle->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $pIsFragmentOf->id();
            $valueObject['value_resource_id']=$itemOri->id();        
            $valueObject['type']='resource';    
            $oItem[$pIsFragmentOf->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $pDuration->id();
            $valueObject['@value'] = $data['tDur'];
            $valueObject['type'] = 'literal';
            $oItem[$pDuration->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $pStart->id();
            $valueObject['@value'] = $data['tDeb'];
            $valueObject['type'] = 'literal';
            $oItem[$pStart->term()][] = $valueObject;    
            $oItem['o:media'][] = [
                'o:ingester' => 'url',
                'o:source'   => $media->source(),
                'ingest_url' => $data['tempPath'],
                $pTitle->term() => [
                    [
                        '@value' => $ref,
                        'property_id' => $pTitle->id(),
                        'type' => 'literal',
                    ],
                ],
            ];    
            $response = $this->api->create('items', $oItem, [], ['continueOnError' => true]);
        }               
        $oItem = $response->getContent();

        return $oItem;
    }
}
