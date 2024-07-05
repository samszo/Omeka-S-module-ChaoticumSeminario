<?php
namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class ChaoticumSeminarioSql extends AbstractHelper
{
    protected $api;
    protected $conn;

    public function __construct($api, $conn)
    {
      $this->api = $api;
      $this->conn = $conn;
    }

    /**
     * Execution de requêtes sql directement dans la base sql
     *
     * @param array     $params paramètre de l'action
     * @return array
     */
    public function __invoke($params=[])
    {
        if($params==[])return[];
        switch ($params['action']) {
            case 'statConcept':
                $result = $this->statConcept($params);
                break;                                                        
            case 'corrections':
                $result = $this->corrections($params);
                break;                                                        
            }

        return $result;

    }

   /**
     * corrige la création des fragments
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function corrections($params){
        //corrige les medias audios
        $medias = $this->api->search('media', ['resource_class_id' => 1038,'media_type'=>'audio/flac'])->getContent();
        foreach ($medias as $m) {
            $dataMedia = json_decode(json_encode($m), true);
            //supprime le lien vers la vidéo globals
            if(count($dataMedia['ma:isFragmentOf'])==2){
                unset($dataMedia['ma:isFragmentOf'][1]);
                $this->api->update('media', $m->id(), $dataMedia, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
                echo 'correction média faite '.$m->id();
                unset($dataMedia);
            }
        }
        //corrige les transcriptions
        $trans = $this->api->search('items', ['resource_class_id' => 1459])->getContent();
        foreach ($trans as $t) {
            $dataItem = json_decode(json_encode($t), true);
            //supprime le lien vers la vidéo globale
            if(count($dataItem['oa:hasSource'])==2){
                unset($dataItem['oa:hasSource'][1]);
                $this->api->update('items', $t->id(), $dataItem, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
                echo 'correction suppression vidéo globale faite '.$t->id();
                unset($dataItem);
            }
            //ajoute le créateur de la transcription
            if(!isset($dataItem['dcterms:creator'])){
                $dataItem['dcterms:creator'][]= [
                    'property_id' => 2,
                    '@value' => 'GoogleSpeechToText',
                    'type' => 'literal',
                ];        
                $this->api->update('items', $t->id(), $dataItem, [], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
                echo 'correction ajout creator faite '.$t->id();
                unset($dataItem);
            }
            


        }
        echo 'corrections terminées';

    }

   /**
     * renvoie la liste des concepts et leur nombre
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function statConcept($params){
        $nbCar = $params['nbCar'] ? $params['nbCar'] : 4;
        $p=$this->api->search('properties', ['term' => 'jdc:hasConcept'])->getContent()[0];
        $query="SELECT r.id idR
        -- , vR.value aTitle
        , vFrag.value_resource_id idFrag
        , vItemSource.value_resource_id idItemSource
        , vMediaSource.value_resource_id idMediaSource
        , vStartV.value vStart
        , vEndV.value vEnd
        , rCpt.id idCpt, rCpt.title titleCpt, length(rCpt.title) nbCar
        , vStartW.value wStart
        , vEndW.value wEnd
    FROM resource r
        INNER JOIN value vFrag on vFrag.resource_id = r.id AND vFrag.property_id = ?
        INNER JOIN value vMediaSource on vMediaSource.resource_id = vFrag.value_resource_id AND vMediaSource.property_id = ?
        INNER JOIN value vStartV on vStartV.resource_id = vMediaSource.resource_id AND vStartV.property_id = ? 
        INNER JOIN value vEndV on vEndV.resource_id = vMediaSource.resource_id AND vEndV.property_id = ? 
        INNER JOIN value vItemSource on vItemSource.resource_id = r.id AND vItemSource.property_id = ?
        INNER JOIN value vR on vR.resource_id = r.id
        INNER JOIN resource rA on rA.id = vR.value_annotation_id
        INNER JOIN value vCpt on vCpt.resource_id = vR.value_annotation_id AND 	vCpt.property_id = ? 
        INNER JOIN resource rCpt on rCpt.id = vCpt.value_resource_id
        INNER JOIN value vStartW on vStartW.resource_id = vR.value_annotation_id AND vStartW.property_id = ? 
        INNER JOIN value vEndW on vEndW.resource_id = vR.value_annotation_id AND vEndW.property_id = ? 
        ";
        if($params['id']){
            $query .= " WHERE vItemSource.value_resource_id = ".$params['id']." AND length(rCpt.title) >= ? ";   
        }else{
            $query .= " WHERE length(rCpt.title) >= ? ";   
        }               
        //$query .=" LIMIT 0, 10";
        $rs = $this->conn->fetchAll($query,[
            $this->api->search('properties', ['term' => 'oa:hasSource'])->getContent()[0]->id(), 
            $this->api->search('properties', ['term' => 'ma:isFragmentOf'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'oa:start'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'oa:end'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'ma:isFragmentOf'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'jdc:hasConcept'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'oa:start'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'oa:end'])->getContent()[0]->id(),            
            $nbCar
        ]);
                
        return $rs;       
    }

}
