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
            case 'timelineConcept':
                $result = $this->timelineConcept($params);
                break;                                                        
        }            

        return $result;

    }

    /*
    Vérifications
     
    Doublons mot
    SELECT  v.value, count(*) nb, v.resource_id
    FROM value v
    WHERE v.property_id = 198
    group by v.value, v.resource_id
    order by nb desc

    Doublons transcription
    SELECT v.value_resource_id, count(*) nb
    FROM value v
    WHERE v.property_id = 531
    GROUP BY v.value_resource_id
    order by nb desc        
    */

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

   /**
     * renvoie la timeline de transcription
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function timelineConcept($params){
        $order =" ORDER BY idConf, CAST(startFrag AS DECIMAL(12,6)),  CAST(startCpt AS DECIMAL(6,2)) ";

        $p=$this->api->search('properties', ['term' => 'jdc:hasConcept'])->getContent()[0];
        $query="SELECT  mConf.item_id idConf, vConf.value titleConf
            , vFrag.value_resource_id idFrag
            , vFragStart.value startFrag
            , vFragEnd.value endFrag
            , vTransAnno.resource_id idTrans
            , vTransCrea.value creator
            , vAnno.resource_id idAnno
            , vConcept.value_resource_id idCpt
            , vConceptTitre.value titleCpt
            , LENGTH(vConceptTitre.value) nbCar
            , vConceptStart.value startCpt
            , vConceptEnd.value endCpt
            , vConceptConf.value confiance
        FROM media mConf
            inner join value vConf on vConf.resource_id = mConf.item_id and vConf.property_id = 1
            
            inner join value vAnno on vAnno.value_resource_id = mConf.id and vAnno.property_id = 425
            
            inner join value vTransAnno on vTransAnno.value_annotation_id = vAnno.resource_id and vTransAnno.property_id = 198
            inner join value vTransCrea on vTransCrea.resource_id = vTransAnno.resource_id and vTransCrea.property_id = 2
            
            inner join value vFrag on vFrag.resource_id = vTransAnno.resource_id and vFrag.property_id = 531
            
            inner join value vFragStart on vFragStart.resource_id = vFrag.value_resource_id and vFragStart.property_id = 543
            inner join value vFragEnd on vFragEnd.resource_id = vFrag.value_resource_id and vFragEnd.property_id = 524
            
            inner join value vConcept on vConcept.resource_id = vAnno.resource_id and vConcept.property_id = 216
            inner join value vConceptTitre on vConceptTitre.resource_id = vConcept.value_resource_id and vConceptTitre.property_id = 1
            inner join value vConceptStart on vConceptStart.resource_id = vAnno.resource_id and vConceptStart.property_id = 543
            inner join value vConceptEnd on vConceptEnd.resource_id = vAnno.resource_id and vConceptEnd.property_id = 524
            inner join value vConceptConf on vConceptConf.resource_id = vAnno.resource_id and vConceptConf.property_id = 404 ";
        if($params['idConf']){
            $query.=" WHERE mConf.item_id = ? ".$order;    
            $rs = $this->conn->fetchAll($query,[
                $params['idConf']
            ]);    
        }                
        if($params['idMediaConf']){
            $query.=" WHERE mConf.id = ? ".$order;    
            $rs = $this->conn->fetchAll($query,[
                $params['idMediaConf']
            ]);    
        }                
        if($params['idConcept']){
            $query.=" WHERE vConcept.value_resource_id = ? ".$order;    
            $rs = $this->conn->fetchAll($query,[
                $params['idConcept']
            ]);    
        }                
        return $rs;       
    }

     

}