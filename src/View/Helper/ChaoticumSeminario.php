<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Datetime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Audio\Flac;
use FFMpeg\Format\Video\X264;
use Laminas\Filter\RealPath;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Exception\RuntimeException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\PropertyRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Api\Representation\ResourceClassRepresentation;
use Omeka\File\TempFileFactory;
use Omeka\File\Store\StoreInterface;
use Omeka\Stdlib\Cli;
use Web64\Nlp\NlpClient;

class ChaoticumSeminario extends AbstractHelper
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Cli
     */
    protected $cli;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var
     */
    protected $ffmpeg;

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var
     */
    protected $resourceClasses = [];

    /**
     * @var array
     */
    protected $resourceTemplates = [];

    //réduction des fragment à 50 secondes pour éviter le plantage 60 secondes
    protected $durFrag = 50;

    public function __construct(
        ApiManager $api,
        EntityManager $entityManager,
        Connection $connection,
        Logger $logger,
        Cli $cli,
        TempFileFactory $tempFileFactory,
        StoreInterface $store,
        string $basePath
    ) {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->cli = $cli;
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->basePath = $basePath;
        $this->ffmpeg = FFMpeg::create();
        $this->ffprobe = FFProbe::create();
        // Nombre de secondes de marge pour les magics Tracks.
        $this->tempsReaction = 1;
        // Nombre de secondes pour la durée des magics Tracks.
        $this->tempsMagic = 5;
    }

    /**
     * Initialisation du séminaire.
     *
     * @param array $params paramètres du séminaire
     * - action
     *
     * Les autres options varient selon l'action.
     *
     * - addMagicTrack (pour audio)
     *   - oa:hasTarget : media id
     * Retourne un item.
     *
     * - getMediaFragByRef (dans dcterms:isReferencedBy)
     *   - ref
     * Retourne une liste d'item ?
     *
     * - getAleaFrag
     *   - media
     *   - oa:start
     *   - oa:end
     * Retourne un item.
     *
     * 
     * - setAllFrag
     *   - media
     *   - oa:start
     *   - oa:end
     * Retourne un item ou un array contenant un message d'erreur.
     *
     * - getEntities
     *   - item (id ou Item)
     * Retourne une liste de noms d'entités.
     *
     * - getFrags (par défaut)
     *   - media
     *   - nom : Nom du séminaire (sinon nom créé)
     * Retourne un array avec le media et les fragments.
     * Les fragments sont liés via ma:isFragmentOf.
     *
     * @return mixed
     */
    public function __invoke(array $params = [])
    {
        // Note: ffmpeg supports urls as input and output.
        if (!($this->store instanceof \Omeka\File\Store\Local)) {
            $this->logger->err(
                'A local store is required to derivate media currently.' // @translate
            );
            return null;
        }

        $action = $params['action'] ?? null;
        switch ($action) {
            case 'addMagicTrack':
                $result = $this->addMagicTrack($params);
                break;
            case 'getMediaFragByRef':
                $result = $this->getMediaFragByRef($params['ref'] ?? null);
                break;
            case 'getAleaFrag':
                $result = $this->setVideoFrag($params['media'] ?? null, $params);
                break;
            case 'setAllFrag':
                $result = $this->setAllFrag($params);
                break;
            case 'getEntities':
                $result = $this->getEntities($params);
                break;
            case 'getFrags':
            default:
                $result = $this->getFrags($params);
                break;
        }
        return $result;
    }

    /**
     * Récupère les entités nommées d'un texte.
     *
     * @param array $params
     */
    protected function getEntities(array $params = [])
    {
        $item = !is_object($params['item'])
            ? $this->api->read('items', $params['item'])->getContent()
            : $params['item'];
        $text = $item->displayTitle();

        $nlpserver_config = [
            'hosts' => [
                'http://localhost:6400/',
            ],
            'debug' => true,
        ];

        $nlp = new NlpClient($nlpserver_config['hosts'], $nlpserver_config['debug']);

        $detected_lang = $nlp->language($text);

        $polyglot = $nlp->polyglot_entities($text, $detected_lang);
        $result = $polyglot->getEntities();
        return $result;
    }

    /**
     * Création de tous les fragments d'un media audiovisuel.
     *
     * @param array $params
     */
    protected function setAllFrag(array $params = [])
    {
        $params['oa:start'] = 0;
        $params['oa:end'] = 'fin';
        $type = $params['media']->mediaType();
        switch ($type) {
            case 'video/mp4':
                $frag = $this->setVideoFrag($params['media'], $params);
                break;
            case 'audio/mpeg':
            case  'audio/flac':
                $frag = $this->setAudioFrag($params['media'], $params);
                break;            
            default:
                return [
                    'error' => 'Mauvais type de Media ',
                    'message' => 'La fragmentation ne prend pas en compte les medias de type :' + $type,
                ];
                break;
        }
        return $frag;
    }

    /**
     * Création d'un fragment à partir d'une position.
     *
     * @param array $params
     */
    protected function addMagicTrack(array $params = []): ?ItemRepresentation
    {
        // Récupère le média original
        try {
            $media = $this->api->read('media', ['id' => $params['oa:hasTarget']])->getContent();
        } catch (\Exception $e) {
            return null;
        }
        $arrFrag = $this->setAudioFrag($media, $params);
        return $arrFrag;
    }

    /**
     * Récupère les fragments d'un média.
     *
     * @param array $params
     */
    protected function getFrags(array $params = [])
    {
        $oMedia = $params['media'] ?? null;
        $fragments = $this->getMediaFrag($oMedia);
        return [
            'media' => $oMedia,
            'fragments' => $fragments,
        ];
    }

    /**
     * Récupère les fragments d'un média.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media média concerné par le fractionnement
     * @param array $params paramètres supplémentaires
     * @return MediaRepresentation[]
     */
    protected function getMediaFrag(
        ?MediaRepresentation $media,
        array $params = []
    ): array {
        if (!$media) {
            return [];
        }
        $pIsFragmentOf = $this->getProperty('ma:isFragmentOf');
        $query = [];
        $query['property'][0]['property'] = (string) $pIsFragmentOf->id();
        $query['property'][0]['type'] = 'res';
        $query['property'][0]['text'] = (string) $media->id();
        foreach ($params as $p) {
            $query['property'][] = $p;
        }
        return $this->api->search('media', $query)->getContent();
    }

    /**
     * Fragmente une vidéo
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media média concerné par le fractionnement
     * @param array $params Paramètre de l'action
     */
    protected function setVideoFrag(
        MediaRepresentation $media,
        array $params = []
    ): ItemRepresentation {
        $videoFormat = [
            'ext' => 'mp4',
            'codec' => 'X264',
        ];
        $paths = $this->getFragmentPaths($media, $videoFormat['ext']);

        // Paramètrage de ffmpeg
        $video = $this->ffmpeg->open($paths['source']);
        $format = $this->ffprobe
            ->format($paths['source']); // extracts file informations
        $stream = $this->ffprobe
            ->streams($paths['source']) // extracts streams informations
            ->videos()                      // filters video streams
            ->first();
        $width = $stream->get('width');
        $height = $stream->get('height');
        $duration = (float) $format->get('duration');             // returns the duration property

        $this->logger->info(
            'Media #{media_id}: creating chaoticum media "{filename}".', // @translate
            ['media_id' => $media->id(), 'filename' => $paths['filename']]
        );

        // Extraction des fragments de 60 secondes
        $deb = intval($params['oa:start']);
        $fin = $params['oa:end'] == 'fin' ? $duration : intval($params['oa:end']);
        for ($d = $deb; $d < $fin; $d += $this->durFrag) {
            $e = $fin > $this->durFrag ? $d + $this->durFrag : $fin;
            $e = $e > $fin ? $fin : $e;

            // Vérifie l'existence du media
            $tempFilename = 'chaosMedia-' . $media->id() . '-' . $d . '-' . $e . '.' . $videoFormat['ext'];
            $existe = $this->getMediaByRef($tempFilename);
            // Mis à jour des paaramètres
            $params['debFrag'] = $d;
            $params['endFrag'] = $e;
            $params['oa:start'] = $d;
            $params['oa:end'] = $e;
            $params['ref'] = 'Fragment vidéo de : ' . $media->id();
            $params['refId'] = $tempFilename;

            if ($existe == null) {
                // Extraction du fragment
                $dur = $e - $d;
                $params['tempPath'] = $paths['temp'] . '/' . $tempFilename;
                // Execute en ligne de commande directe pour plus de rapidité
                $cmd = 'ffmpeg -i ' . $paths['source']
                    . ' -ss ' . TimeCode::fromSeconds($d)
                    . ' -to ' . TimeCode::fromSeconds($e)
                    . ' -c:v copy -c:a copy ' . $params['tempPath'];
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
                    throw new RuntimeException('Impossible de créer le fichier vidéo : "' . $params['tempPath'] . '" (media:' . $media->id() . ').');
                }
                // Réécriture de l'url
                $tempUrl = str_replace('original', 'tmp', $media->originalUrl());
                $tempUrl = str_replace($media->filename(), $tempFilename, $tempUrl);
                $params['tempUrl'] = $tempUrl;

                // Création du média chaotique
                $mediaFrag = $this->ajouteMediaFrag($media, $params);
                $medias = $mediaFrag->media();
                $m = $medias[count($medias) - 1];
            } else {
                $m = $existe;
                $mediaFrag = $m->item();
            }
            // Extraction de l'audio du fragment pour le traitement du speech to text
            //$params['parentMediaId']=$media->id();
            $arrFrags = $this->setAudioFrag($m, $params, true);
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $mediaFrag->displayTitle()]
            );
        }

        return $arrFrags;
    }

    /**
     * Fragmente un audio.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media média concerné par le fractionnement
     * @param array $params paramètre de l'action
     * @param bool $sourceIsFrag converti le fichier en entier == extraction audio d'un fragment vidéo
     */
    protected function setAudioFrag(
        MediaRepresentation $media,
        array $params = [],
        bool $sourceIsFrag = false
    ): ItemRepresentation {
        $paths = $this->getFragmentPaths($media, 'flac');

        // Paramètrage de ffmpeg
        $audio = $this->ffmpeg->open($paths['source']);
        $format = $this->ffprobe
            ->format($paths['source']); // extracts file informations
        $duration = (float) $format->get('duration');             // returns the duration property

        $this->logger->info(
            'Media #{media_id}: creating chaoticum media "{filename}".', // @translate
            ['media_id' => $media->id(), 'filename' => $paths['source']]
        );

        //extraction des fragments
        //réduction des fragment à $this->durFrag secondes pour éviter le plantage 60 secondes
        $deb = intval($params['oa:start']);
        $fin = $params['oa:end'] == 'fin' ? $duration : intval($params['oa:end']);
        for ($d = $deb; $d < $fin; $d += $this->durFrag) {
            $e = $fin > $this->durFrag ? $d + $this->durFrag : $fin;
            $e = $e > $fin ? $fin : $e;
            $params['debFrag'] = $d;
            $params['endFrag'] = $e;
            //TODO: voir si on prend une marge de 3 secondes pour éviter de découper les mots
            $tempFilename = 'chaosMedia-' . $media->id() . '-' . $d . '-' . $e . '.flac';
            $dur = $e - $d;

            $params['tempPath'] = $paths['temp'] . '/' . $tempFilename;
            $existe = $this->getMediaByRef($tempFilename);
            if ($existe == null) {
                if ($sourceIsFrag) {
                    //execute en ligne de commande directe pour plus de rapidité
                    $cmd = 'ffmpeg -i ' . $paths['source']
                    . ' -vn -sn -acodec flac -ar 16000 '
                    . $params['tempPath'];
                    $output = shell_exec($cmd);
                } else {
                    $audio->filters()->clip(TimeCode::fromSeconds($deb), TimeCode::fromSeconds($dur));
                    //spécifie le format du fragment pour diminuer la taille et la rendre compatible avec le speech to text
                    $audio->filters()->resample(16000);
                    $format = new Flac();
                    $format
                        ->setAudioChannels(1)
                        ->setAudioKiloBitrate(8);
                    $audio->save($format, $params['tempPath']);
                }

                if (!file_exists($params['tempPath']) || !filesize($params['tempPath'])) {
                    $this->logger->err(
                        'Media #{media_id}: chaoticum media is empty ({filename}).', // @translate
                        ['media_id' => $media->id(), 'filename' => $paths['filename']]
                    );
                    throw new RuntimeException('Impossible de créer le fichier audio : "' . $params['tempPath'] . '" (media:' . $media->id() . ').');
                }
                // Réécriture de l'url
                $tempUrl = str_replace('original', 'tmp', $media->originalUrl());
                $params['tempUrl'] = str_replace($media->filename(), $tempFilename, $tempUrl);

                // Création du média dans l'item
                $params['ref'] = 'Fragment audio de : ' . $media->id();
                $params['refId'] = $tempFilename;
                $mediaFrags = $this->ajouteMediaFrag($media, $params);
            } else {
                $mediaFrags = $existe->item();
            }
        }

        return $mediaFrags;
    }

    /**
     * Récupère le path du fragment d'un média.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param string $extension
     */
    protected function getFragmentPaths(MediaRepresentation $media, string $extension): ?array
    {
        $mainMediaType = strtok((string) $media->mediaType(), '/');
        $filename = $media->filename();
        $sourcePath = $this->basePath . '/original/' . $filename;

        if (!file_exists($sourcePath)) {
            $this->logger->warn(
                'Media #{media_id}: the original file does not exist ({filename})', // @translate
                ['media_id' => $media->id(), 'filename' => 'original/' . $filename]
            );
            return null;
        }

        if (!is_readable($sourcePath)) {
            $this->logger->warn(
                'Media #{media_id}: the original file is not readable ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => 'original/' . $filename]
            );
            return null;
        }

        $realpath = new RealPath(false);

        $storageId = $media->storageId();
        $pattern = $extension . '/{filename}.' . $extension;
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
            return null;
        }

        if (file_exists($chaoticumPath) && !is_writeable($chaoticumPath)) {
            $this->logger->warn(
                'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $storageName]
            );
            return null;
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
                return null;
            }
        } else {
            $result = @mkdir($dirpath, 0755, true);
            if (!$result) {
                $this->logger->err(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return null;
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
                return null;
            }
        } else {
            $result = @mkdir($tempPath, 0755, true);
            if (!$result) {
                $this->logger->err(
                    'Media #{media_id}: chaoticum media is not writeable ({filename}).', // @translate
                    ['media_id' => $media->id(), 'filename' => $storageName]
                );
                return null;
            }
        }

        return [
            'filename' => $storageName,
            'source' => $sourcePath,
            'temp' => $tempPath,
        ];
    }

    /**
     * Récupère un item média par sa référence.
     */
    protected function getMediaFragByRef(string $ref): ?array
    {
        if (empty($ref)) {
            return null;
        }
        // Vérifie la présence de l'item chaotique pour ne pas la récréer inutilement
        $param = [];
        $param['property'][0]['property'] = (string) $this->getProperty('dcterms:isReferencedBy')->id();
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $ref;
        return $this->api->search('items', $param)->getContent();
    }

    /**
     * Récupère un media par sa référence.
     *
     * @param string $ref
     */
    protected function getMediaByRef($ref): ?MediaRepresentation
    {
        // Vérifie la présence de l'item chaotique pour ne pas la récréer inutilement
        $param = [];
        $param['property'][0]['property'] = (string) $this->getProperty('dcterms:isReferencedBy')->id();
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $ref;
        $param['limit'] = 1;
        $medias = $this->api->search('media', $param)->getContent();
        return $medias ? reset($medias) : null;
    }

    /**
     * Création du média chaotique
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param array $data
     */
    protected function ajouteMediaFrag($media, $data)
    {
        $this->logger->info('Media ' . $media->id() . ' : chaoticum ajouteMedia.', $data);

        // Récupère l'item du média
        $itemOri = isset($data['oItem']) ? $data['oItem'] : $media->item();

        // Ajoute le fragment de media et la référence à l'item de base
        $dataItem = json_decode(json_encode($itemOri), true);

        $oMedia = [];
        $oMedia['o:resource_class'] = ['o:id' => $this->getResourceClass('ma:MediaFragment')->id()];
        // $oMedia['o:resource_templates'] = ['o:id' => $this->resourceTemplate['Cartographie des expressions']->id()];
        $valueObject = [];
        $valueObject['property_id'] = $this->getProperty('dcterms:title')->id();
        $valueObject['@value'] = $data['ref'] . ' : ' . $data['debFrag'] . '_' . $data['endFrag'];
        $valueObject['type'] = 'literal';
        $oMedia['dcterms:title'][] = $valueObject;

        if (isset($data['refId'])) {
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty('dcterms:isReferencedBy')->id();
            $valueObject['@value'] = $data['refId'];
            $valueObject['type'] = 'literal';
            $oMedia['dcterms:title'][] = $valueObject;
        }
        $valueObject = [];
        $valueObject['property_id'] = $this->getProperty('ma:isFragmentOf')->id();
        $valueObject['value_resource_id'] = $media->id();
        $valueObject['type'] = 'resource';
        $oMedia['ma:isFragmentOf'][] = $valueObject;

        if($data['parentMediaId']){
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty('ma:isFragmentOf')->id();
            $valueObject['value_resource_id'] = $data['parentMediaId'];
            $valueObject['type'] = 'resource';
            $oMedia['ma:isFragmentOf'][] = $valueObject;    
        }

        $valueObject = [];
        $valueObject['property_id'] = $this->getProperty('oa:start')->id();
        $valueObject['@value'] = (string) $data['debFrag'];
        $valueObject['type'] = 'literal';
        $oMedia['oa:start'][] = $valueObject;
        $valueObject = [];
        $valueObject['property_id'] = $this->getProperty('oa:end')->id();
        $valueObject['@value'] = (string) $data['endFrag'];
        $valueObject['type'] = 'literal';
        $oMedia['oa:end'][] = $valueObject;
        $oMedia['o:ingester'] = 'url';
        $oMedia['o:source'] = $data['tempPath'];
        // ATTENTION problème de dns sur le serveur paris 8
        $data['tempUrl'] = str_replace('https://arcanes.univ-paris8.fr', 'http://192.168.30.208', $data['tempUrl']);
        $oMedia['ingest_url'] = $data['tempUrl'];

        // Mise à jour de l'item.
        $dataItem['o:media'][] = $oMedia;
        /*
        $dataItem['dcterms:isReferencedBy'][]=[
            'property_id' => $this->getProperty('dcterms:isReferencedBy')->id()
            ,'@value' => $data['ref'] ,'type' => 'literal'
        ];
        */
        //TODO: ajouter audio/flac dans les settings 
        $response = $this->api->update('items', $dataItem['o:id'], $dataItem, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
        $mediaFrag = $response->getContent();
        if ($mediaFrag) {
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $media->filename()]
            );
        } else {
            $this->logger->err(
                'Media #{media_id}: chaoticum item is empty ({filename}).', // @translate
                ['media_id' => $media->id(), 'filename' => $data['tempUrl']]
            );
        }
        return $mediaFrag;
    }

    protected function getProperty($term): PropertyRepresentation
    {
        if (!isset($this->properties[$term])) {
            $this->properties[$term] = $this->api->search('properties', ['term' => $term])->getContent()[0];
        }
        return $this->properties[$term];
    }

    protected function getResourceClass($term): ResourceClassRepresentation
    {
        if (!isset($this->resourceClasses[$term])) {
            $this->resourceClasses[$term] = $this->api->search('resource_classes', ['term' => $term])->getContent()[0];
        }
        return $this->resourceClasses[$term];
    }

    protected function getResourceTemplate($label): ResourceTemplateRepresentation
    {
        if (!isset($this->resourceTemplates[$label])) {
            $this->resourceTemplates[$label] = $this->api->read('resource_templates', ['label' => $label])->getContent();
        }
        return $this->resourceTemplates[$label];
    }
}
