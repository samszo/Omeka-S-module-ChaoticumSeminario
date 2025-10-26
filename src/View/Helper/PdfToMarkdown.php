<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Laminas\Http\Headers;
use Omeka\Api\Manager as ApiManager;
use Omeka\Permissions\Acl;
use mikehaertl\shellcommand\Command;
use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Gemini;
use Gemini\Enums\ModelVariation;
use Gemini\GeminiHelper;
use Gemini\Enums\FileState;
use Gemini\Enums\MimeType;
use Gemini\Data\UploadedFile;


class PdfToMarkdown extends AbstractHelper
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

    protected $properties = [];

    protected $rcs;

    protected $rts;

    protected $credentials;

    protected $client;

    protected $workspace;
    protected $docs;
    protected $propRef;
    protected $headers;
    protected $pathFiles;

    /**
     *
     * @var GoogleGeminiCredentials
     */
    protected $googleGeminiCredentials;
    protected $googleGeminiKey="";
    /**
     *
     * @var anythingLLMcredentials
     */
    protected $anythingLLMcredentials;


    public function __construct(
        ApiManager $api,
        Acl $acl,
        Logger $logger,
        ChaoticumSeminario $chaoticumSeminario,
        array $config,
        ChaoticumSeminarioSql $sql,
        AnythingLLMCredentials $anythingLLMcredentials,
        GoogleGeminiCredentials $googleGeminiCredentials,
        $client,
        $pathFiles
    ) {
        $this->api = $api;
        $this->acl = $acl;
        $this->logger = $logger;
        $this->chaoticumSeminario = $chaoticumSeminario;
        $this->config = $config;
        $this->sql = $sql;
        $this->anythingLLMcredentials = $anythingLLMcredentials->__invoke();
        $this->client = $client;
        $this->googleGeminiCredentials = $googleGeminiCredentials;
        $this->pathFiles = $pathFiles;

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
            case 'pdfToMarkdown':
                $result = $this->getMarkdown($query['item'],$query['moteur']);
                break;
            default:
                $result = [];
                break;
        }
        return $result;
    }



    /**
     * Transcription du PDF en markdown
     *
     * @param object $item
     * @param string $moteur
     * 
     */
    protected function getMarkdown($item,$moteur){

        //récupère le fichier pdf
        $pdfFile = null;
        foreach ($item->media() as $media) {
            if ($media->mediaType()=='application/pdf') {
                $pdfFile = $media->originalUrl();
                if(!$pdfFile){
                    $this->logger->warn(
                        'Media #{media_id}: the original file does not exist ({filename})', // @translate
                        ['media_id' => $media->id(), 'filename' => 'original/' . $pdfFile]
                    );
                }else{
                    switch ($moteur) {
                        case 'PdfToMarkdownParser':
                            $parser = new PdfToMarkdownParser();
                            $pdfContent = file_get_contents($pdfFile);
                            $markdown = $parser->parseContent($pdfContent);
                            break;
                        case 'PdfToMarkdownParser':
                            $markdown = $this->getGeminiMarkdown($item,$media);                        
                            break;
                        case 'marker':
                            $markdown = $this->getMarkerMarkdown($item,$media);                        
                            break;
                    }
                    /*extrait le markdown avec la lib
                    */

                    //extrait le markdown avec gemini
                    if(!$markdown){
                        $this->logger->warn(
                            'Media #{media_id}: the pdf ({filename}) have no markdown', // @translate
                            ['media_id' => $media->id(), 'filename' => 'original/' . $pdfFile]
                        );
                    }else{
                        $data = $this->addMediaMarkdown($item,$media,$markdown);
                    }
                }
            }
        }
    }

    /**
     * get markdown whith marker
     * NOTE: marker must be installed on the server cf. https://github.com/datalab-to/marker
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param string $markdown
     */
    protected function getMarkerMarkdown($item, $media)
    {
        $outputDir = $this->pathFiles."/md";
        //extraction des chunks
        $cmd = 'marker_single '.$outputDir['source'].' --output_dir '.$outputDir.' --output_format chunks';
        $chunks = shell_exec($cmd);                        
        $this->logger->info('Item ' . $item->id() . ' : getMarkerMarkdown : '.$media->id().' : chunks extraits');

        //extraction du markdown
        $cmd = 'marker_single '.$outputDir['source'].' --output_dir '.$outputDir.' --output_format markdown';
        $md = shell_exec($cmd);                        
        $this->logger->info('Item ' . $item->id() . ' : getMarkerMarkdown : '.$media->id().' : markdown extraits');


        $markdown = $md; //  The picture shows a table with a white tablecloth. On the table are two cups of coffee, a bowl of blueberries, a silver spoon, and some flowers. There are also some blueberry scones on the table.
        return $markdown;
    }

    /**
     * get markdown whith gemini
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param string $markdown
     */
    protected function getGeminiMarkdown($item, $media)
    {
        // Prépare le compte une seule fois.
        if (!$this->googleGeminiKey) {
            $this->googleGeminiKey = $this->googleGeminiCredentials->__invoke();
        }
        if (!$this->googleGeminiKey) {
            $this->logger->err(
                'Google Gemini Key are not set.' // @translate
            );
            return;}

        //cf. https://aistudio.google.com/api-keys
        $client = Gemini::client($this->googleGeminiKey);


        $files = $client->files();
        $meta = $files->upload(
            filename: $media->originalUrl(),
            mimeType: MimeType::APPLICATION_PDF,
            displayName: 'Video'
        );
        do {
            sleep(2);
            $meta = $files->metadataGet($meta->uri);
        } while (!$meta->state->complete());

        if ($meta->state == FileState::Failed) {
            $this->logger->err(
                'Upload failed: #{media_id}: {message}).', // @translate
                ['media_id' => $item->id(), 'message' => json_encode($meta->toArray(), JSON_PRETTY_PRINT)]
            );
        }
        $this->logger->info('Item ' . $item->id() . ' : getGeminiMarkdown : '.$media->id().' : '.$meta->uri);

        $result = $client
            ->generativeModel(model: 'gemini-2.5-flash')
            ->generateContent([
                'transformes en markdown le fichier pdf fourni',
                new UploadedFile(
                    fileUri: $meta->uri, 
                    mimeType: MimeType::APPLICATION_PDF
                )
            ]);
        $this->logger->info('Item ' . $item->id() . ' : getGeminiMarkdown : '.$media->id().' : markdown récupéré');

        $markdown = $result->text(); //  The picture shows a table with a white tablecloth. On the table are two cups of coffee, a bowl of blueberries, a silver spoon, and some flowers. There are also some blueberry scones on the table.
        return $markdown;
    }
    /**
     * Création du média markdown
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     * @param string $markdown
     */
    protected function addMediaMarkdown($item, $media, $markdown)
    {
        $this->logger->info('Item ' . $item->id() . ' : chaoticum addMediaMarkdown : '.$media->id());

        //ajoute le markdown dans fichier markdown
        $paths = $this->chaoticumSeminario->getFragmentPaths($media, 'md');
        $paths['tempPath'] = $paths['temp'].str_replace('md',"",$paths['filename'])."md";
        file_put_contents($paths['tempPath'], $markdown);
        $paths['tempUrl'] = str_replace("original/","tmp/",$media->originalUrl());
        $paths['tempUrl'] = str_replace(".pdf",".md",$paths['tempUrl']);

        // Ajoute le fragment de media et la référence à l'item de base
        $dataItem = json_decode(json_encode($item), true);

        $oMedia = [];
        // $oMedia['o:resource_class'] = ['o:id' => $this->getResourceClass('ma:MediaFragment')->id()];
        // $oMedia['o:resource_templates'] = ['o:id' => $this->resourceTemplate['Cartographie des expressions']->id()];
        $valueObject = [];
        $valueObject['property_id'] = $this->getProperty('dcterms:title')->id();
        $valueObject['@value'] = "markdown - " . $item->displayTitle();
        $valueObject['type'] = 'literal';
        $oMedia['dcterms:title'][] = $valueObject;

        $oMedia['o:ingester'] = 'url';
        $oMedia['ingest_url'] = $paths['tempUrl'];

        // Mise à jour de l'item.
        $dataItem['o:media'][] = $oMedia;
        /*
        $dataItem['dcterms:isReferencedBy'][]=[
            'property_id' => $this->getProperty('dcterms:isReferencedBy')->id()
            ,'@value' => $data['ref'] ,'type' => 'literal'
        ];
        */
        $response = $this->api->update('items', $item->id(), $dataItem, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
        $mediaFrag = $response->getContent();
        if ($mediaFrag) {
            $this->logger->info(
                'Media #{media_id}: chaoticum media created ({filename}).', // @translate
                ['media_id' => $mediaFrag->id(), 'filename' => $paths['tempUrl']]
            );
        } else {
            $this->logger->err(
                'Media #{media_id}: chaoticum item is empty ({filename}).', // @translate
                ['media_id' => $item->id(), 'filename' => $paths['tempUrl']]
            );
        }
        return $mediaFrag;
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

    public function getProperty($term)
    {
        if (!isset($this->properties[$term])) {
            $this->properties[$term] = $this->api->search('properties', ['term' => $term])->getContent()[0];
        }
        return $this->properties[$term];
    }

}
