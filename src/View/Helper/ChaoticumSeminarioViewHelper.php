<?php
namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Laminas\Filter\RealPath;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Video\Ogg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Audio\Flac;
use \Datetime;
use GuzzleHttp\Psr7\Query;
use Omeka\Api\Exception\RuntimeException;

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
    protected $props;
    protected $rcs;
    protected $rts;

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
      $this->tempsReaction = 1;//nombre de secondes de marge pour les magics Tracks
      $this->tempsMagic=5;//nombre de secondes pour la durée des magics Tracks

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

        switch ($params['action']) {
            case 'addMagicTrack':
                $rs = $this->addMagicTrack($params);
                break;            
            case 'getMediaFragByRef':
                $rs = $this->getMediaFragByRef($params['ref']);
                break;
            case 'getAleaFrag':
                $rs = $this->setVideoFrag($params['media'],$params);
                break;
            case 'setAllFrag':
                $rs = $this->setAllFrag($params);
                break;
            default:
                $rs = $this->getFrags($params);
                break;
    
        }
        return $rs;
    }

    /**
     * création de tous les fragments d'un media audiovisuel
     * 
     * @param   array   $query
     *
     * @return array
     */
    function setAllFrag($params){
        $params['oa:start']=0;
        $params['oa:end']='fin';
        $type = $params['media']->mediaType();
        switch ($type) {
            case 'video/mp4':
                $frag = $this->setVideoFrag($params['media'],$params);
                break;
            case 'audio/mpeg':
                $frag = $this->setAudioFrag($params['media'],$params);
                break;                        
            default:
                return ['error'=>"Mauvais type de Media ",'message'=>"La fragmentation ne prend pas en compte les medias de type :"+$type];
                break;
        }
        return $frag;
    }


    /**
     * création d'un fragment à partir d'une position
     *
     * @param array    $params
     * 
     * @return array
     */
    function addMagicTrack($params){
        //récupère le média original
        $media = $this->api->read('media', $params['oa:hasTarget'])->getContent();
        $arrFrag = $this->setAudioFrag($media,$params);
        return $arrFrag;
    }

    /**
     * récupère les fragments d'un média
     *
     * @param array    $params
     * 
     * @return array
     */
    function getFrags($params){
        //récupère les propriétés
        if(!isset($params['nom'])){            
            $date = new DateTime('NOW');
            $params['nom']="Séminaire ".$date->format('Y-m-d H:i:s');            
        }

        $oMedia = $this->api->read('media', $params['idMedia'])->getContent();

        $fragments = $this->getMediaFrag($oMedia);
        //$fragments = $this->setVideoFrag($oMedia, $params);
        
        return ['media'=>$oMedia,'fragments'=>$fragments];

    }


    /**
     * récupère les fragments d'un média'
     *
     * @param oMedia    $media média concerné par le fractionnement
     * @param array     $params paramètres supplémentaires
     * 
     * @return array
     */
    function getMediaFrag($media, $params=[]){
        $pIsFragmentOf = $this->getProp('ma:isFragmentOf'); 
        $query = array();
        $query['property'][0]['property']= $pIsFragmentOf->id()."";
        $query['property'][0]['type']='res';
        $query['property'][0]['text']=$media->id().""; 
        foreach ($params as $p) {
            $query['property'][] = $p;
        }
        return $this->api->search('media',$query)->getContent();        
    }

    /**
     * fragmente une vidéo 
     * merci à Daniel Berthereau pour le module DeritativeMedia
     *
     * @param oMedia    $media média concerné par le fractionnement
     * @param array     $params paramètre de l'action
     * 
     * @return array
     */
    function setVideoFrag($media, $params){
        $videoFormat = ['ext'=>'mp4','codec'=>'X264'];
        $paths = $this->getFragmentPaths($media, $videoFormat['ext']);

        //paramètrage de ffmpeg
        $video = $this->ffmpeg->open($paths['source']);
        $format = $this->ffprobe
            ->format($paths['source']); // extracts file informations
        $stream = $this->ffprobe
            ->streams($paths['source']) // extracts streams informations
            ->videos()                      // filters video streams
            ->first(); 
        $width = $stream->get('width');              
        $height = $stream->get('height');              
        $duration = (float)$format->get('duration');             // returns the duration property
        
        $this->logger->info(
            'Media #{media_id}: creating chaoticum media "{filename}".', // @translate
            ['media_id' => $media->id(), 'filename' => $paths['filename']]
        );


        //extraction des fragments de 60 secondes
        $deb = intval($params['oa:start']);
        $fin = $params['oa:end']=='fin' ? $duration : intval($params['oa:end']);
        for ($d=$deb; $d < $fin; $d+=60) {            
            $e = $fin > 60 ? $d+60 : $fin;
            $e = $e > $fin  ? $fin : $e;

            //vérifie l'existence du media
            $tempFilename = 'chaosMedia-'.$media->id().'-'.$d.'-'.$e.'.'.$videoFormat['ext'];
            $existe = $this->getMediaByRef($tempFilename);
            //mis à jour des paaramètres 
            $params['debFrag']=$d;
            $params['endFrag']=$e;
            $params['oa:start']=$d;
            $params['oa:end']=$e;
            $params['ref']="Fragment vidéo de : ".$media->id();
            $params['refId']=$tempFilename;

            if(count($existe)==0){
                //extraction du fragment
                $dur = $e-$d;
                $params['tempPath']= $paths['temp'] .'/'.$tempFilename;
                //execute en ligne de commande directe pour plus de rapidité
                $cmd ="ffmpeg -i ".$paths['source']
                ." -ss ".TimeCode::fromSeconds($d)
                ." -to ".TimeCode::fromSeconds($e)
                ." -c:v copy -c:a copy ".$params['tempPath'];
                $output = shell_exec($cmd);
                /*
                $clip = $video->clip(TimeCode::fromSeconds($d), TimeCode::fromSeconds($dur));
                $clip->save(new X264(), $params['tempPath']);
                */
                if (!file_exists($params['tempPath']) || !filesize($params['tempPath'])) {
                    $this->logger->err(
                        'Media #{media_id}: chaoticum media is empty ({filename}).', // @translate
                        ['media_id' => $media->id(), 'filename' => $paths['filename']]
                    );
                    throw new RuntimeException("Impossible de créer le fichier vidéo : '".$params['tempPath']."' (media:".$media->id().").");			
                }
                //réécriture de l'url
                $tempUrl = str_replace('original','tmp',$media->originalUrl());
                $tempUrl = str_replace($media->filename(),$tempFilename,$tempUrl);
                $params['tempUrl']=$tempUrl;

                //création du média chaotique
                $mediaFrag = $this->ajouteMediaFrag($media, $params);        
                $medias = $mediaFrag->media();
                $m = $medias[count($medias)-1];
            }else{
                $m = $existe[0];
                $mediaFrag = $m->item();        
            }
            //extraction de l'audio du fragment pour le traitement du speech to text
            $arrFrags = $this->setAudioFrag($m,$params, true);
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $mediaFrag->displayTitle()]
            );    
        }

        return $arrFrags;

    }

    /**
     * fragmente un audio
     * merci à Daniel Berthereau pour le module DeritativeMedia
     *
     * @param oMedia    $media média concerné par le fractionnement
     * @param array     $params paramètre de l'action
     * @param bool      $sourceIsFrag converti le fichier en entier == extraction audio d'un fragment vidéo
     * 
     * @return array
     */
    function setAudioFrag($media, $params, $sourceIsFrag){

        $paths = $this->getFragmentPaths($media, 'flac');

        //paramètrage de ffmpeg
        $audio = $this->ffmpeg->open($paths['source']);
        $format = $this->ffprobe
            ->format($paths['source']); // extracts file informations
        $duration = (float)$format->get('duration');             // returns the duration property
        
        $this->logger->info(
            'Media #{media_id}: creating chaoticum media "{filename}".', // @translate
            ['media_id' => $media->id(), 'filename' => $paths['source']]
        );

        //extraction des fragments de 60 secondes
        $dur = 60;
        $deb = intval($params['oa:start']);
        $fin = $params['oa:end']=='fin' ? $duration : intval($params['oa:end']);
        for ($d=$deb; $d < $fin; $d+=$dur) { 
            $e = $fin > $dur ? $d+$dur : $fin;
            $e = $e > $fin  ? $fin : $e;
            $params['debFrag']=$d;
            $params['endFrag']=$e;
            //TODO: voir si on prend une marge de 3 secondes pour éviter de découper les mots            
            $tempFilename = 'chaosMedia-'.$media->id().'-'.$d.'-'.$e.'.flac';
            $dur = $e-$d;

            $params['tempPath']= $paths['temp'] .'/'.$tempFilename;
            $existe = $this->getMediaByRef($tempFilename);
            if(count($existe)==0){
                if($sourceIsFrag){
                    //execute en ligne de commande directe pour plus de rapidité
                    $cmd ="ffmpeg -i ".$paths['source']
                    ." -vn -sn -acodec flac -ar 16000 "
                    .$params['tempPath'];
                    $output = shell_exec($cmd);
                }else{
                    $clip = $audio->clip(TimeCode::fromSeconds($deb), TimeCode::fromSeconds($dur));
                    //spécifie le format du fragment pour diminuer la taille et la rendre compatible avec le speech to text
                    $clip->filters()->resample(16000);
                    $format = new Flac();
                    $format
                        ->setAudioChannels(1)
                        ->setAudioKiloBitrate(8);
                    $clip->save($format, $params['tempPath']);
                }

                if (!file_exists($params['tempPath']) || !filesize($params['tempPath'])) {
                    $this->logger->err(
                        'Media #{media_id}: chaoticum media is empty ({filename}).', // @translate
                        ['media_id' => $media->id(), 'filename' => $paths['filename']]
                    );
                    throw new RuntimeException("Impossible de créer le fichier audio : '".$params['tempPath']."' (media:".$media->id().").");			
                }
                //réécriture de l'url
                $tempUrl = str_replace('original','tmp',$media->originalUrl());
                $params['tempUrl']=str_replace($media->filename(),$tempFilename,$tempUrl);
                
                //création du média dans l'item
                $params['ref']="Fragment audio de : ".$media->id();
                $params['refId']=$tempFilename;
                $mediaFrags = $this->ajouteMediaFrag($media, $params);
            }else{
                $mediaFrags = $existe[0]->item();        
            }        
        }

        return $mediaFrags;

    }

    /**
     * récupère le path du fragment
     *
     * @param oMedia    $media
     * @param string    $ext
     * 
     * @return array
     */
    protected function getFragmentPaths($media, $ext){
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
        $pattern = $ext."/{filename}.".$ext;
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

        return  ['filename' => $storageName, 'source'=>$sourcePath, 'temp'=>$tempPath];

    }

    /**
     * récupère un item média par sa référence
     *
     * @param oMedia    $media
     * @param array     $data
     * @return array
     */
    protected function getMediaFragByRef($ref){
        //vérifie la présence de l'item chaotique pour ne pas la récréer inutilement
        $param = array();
        $param['property'][0]['property']= $this->getProp('dcterms:isReferencedBy')->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        return $this->api->search('items',$param)->getContent();
    }

    /**
     * récupère un media par sa référence
     *
     * @param string    $ref
     * @return array
     */
    protected function getMediaByRef($ref){
        //vérifie la présence de l'item chaotique pour ne pas la récréer inutilement
        $param = array();
        $param['property'][0]['property']= $this->getProp('dcterms:isReferencedBy')->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        return $this->api->search('media',$param)->getContent();
    }

    /**
     * Création du média chaotique
     *
     * @param oMedia    $media
     * @param array     $data
     * @return oMedia
     */
    protected function ajouteMediaFrag($media, $data)
    {
        $this->logger->info('Media '.$media->id().' : chaoticum ajouteMedia.',$data);    

        //récupère l'item du média
        $itemOri = isset($data['oItem']) ? $data['oItem'] : $media->item();

        //ajoute le fragment de media et la référence à l'item de base
        $dataItem = json_decode(json_encode($itemOri), true);

        $oMedia = [];
        $oMedia['o:resource_class'] = ['o:id' => $this->getRc('ma:MediaFragment')->id()];
        //$oMedia['o:resource_templates'] = ['o:id' => $this->resourceTemplate['Cartographie des expressions']->id()];
        $valueObject = [];
        $valueObject['property_id'] = $this->getProp('dcterms:title')->id();
        $valueObject['@value'] = $data['ref'].' : '.$data['debFrag'].'_'.$data['endFrag'];
        $valueObject['type'] = 'literal';
        $oMedia['dcterms:title'][] = $valueObject;    

        if(isset($data['refId'])){
            $valueObject = [];
            $valueObject['property_id'] = $this->getProp('dcterms:isReferencedBy')->id();
            $valueObject['@value'] = $data['refId'];
            $valueObject['type'] = 'literal';
            $oMedia['dcterms:title'][] = $valueObject;        
        }
        $valueObject = [];
        $valueObject['property_id'] = $this->getProp('ma:isFragmentOf')->id();
        $valueObject['value_resource_id']=$media->id();        
        $valueObject['type']='resource';    
        $oMedia['ma:isFragmentOf'][] = $valueObject;    
        $valueObject = [];
        $valueObject['property_id'] = $this->getProp('oa:start')->id();
        $valueObject['@value'] = $data['debFrag']."";
        $valueObject['type'] = 'literal';
        $oMedia['oa:start'][] = $valueObject;    
        $valueObject = [];
        $valueObject['property_id'] = $this->getProp('oa:end')->id();
        $valueObject['@value'] = $data['endFrag']."";
        $valueObject['type'] = 'literal';
        $oMedia['oa:end'][] = $valueObject; 
        $oMedia['o:ingester'] = 'url';
        $oMedia['o:source'] = $data['tempPath'];
        //ATTENTION problème de dns sur le serveur paris 8
        $data['tempUrl'] = str_replace('https://arcanes.univ-paris8.fr','http://192.168.30.208',$data['tempUrl']);
        $oMedia['ingest_url'] = $data['tempUrl'];

        //mise à jour de l'item
        $dataItem['o:media'][] = $oMedia;    
        /*
        $dataItem['dcterms:isReferencedBy'][]=[
            'property_id' => $this->getProp('dcterms:isReferencedBy')->id()
            ,'@value' => $data['ref'] ,'type' => 'literal'
        ];
        */
        $response = $this->api->update('items', $dataItem['o:id'], $dataItem, [], ['continueOnError' => true,'isPartial'=>1, 'collectionAction' => 'replace']);
        $mediaFrag = $response->getContent();
        if($mediaFrag){
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $media->filename()]
            );    
        }else{
            $this->logger->err(
                'Media #{media_id}: chaoticum item is empty ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $data['tempUrl']]
            );
        }
        return $mediaFrag;

    }

    function getProp($p){
        if(!isset($this->props[$p]))
          $this->props[$p]=$this->api->search('properties', ['term' => $p])->getContent()[0];
        return $this->props[$p];
      }
  
    function getRc($t){
        if(!isset($this->rcs[$t]))
            $this->rcs[$t] = $this->api->search('resource_classes', ['term' => $t])->getContent()[0];
        return $this->rcs[$t];
    }
    function getRt($l){
        if(!isset($this->rts[$l]))
            $this->rts[$l] = $this->api->read('resource_templates', ['label' => $l])->getContent();
        return $this->rts[$l];
    }


}
