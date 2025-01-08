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
            case 'timelineConceptAnnexe':
                $result = $this->timelineConceptAnnexe($params);
                break;                        
            case 'getConferenceTexte':                                                    
                $result = $this->getConferenceTexte($params);
                break;    
            case 'getMarkdownTranscription':                                                    
                $result = $this->getMarkdownTranscription($params);
                break; 
            case 'getConcept':                       
                $result = $this->getConcept($params);
                break; 
            case 'addConcept':                       
                $result = $this->addConcept($params);
                break; 
            case 'suggestConcept':
                $result = $this->suggestConcept($params);
                break;
            case 'getConferences':                       
                $result = $this->getConferences($params);
                break; 
            case 'getTransNote':
                $result = $this->getTransNote($params);
                break;    
            case 'getNoteExtrapolation':
                $result = $this->getNoteExtrapolation($params);
                break;
            case 'getConceptTrans':
                $result = $this->getConceptTrans($params);
                break;
            case 'getFlacFromServeur':
                $result = $this->getFlacFromServeur();
                break;    
            case 'getNextTrans':
                $result = $this->getNextTrans($params);
                break; 
            case 'timelineTrans':
                $result = $this->timelineTrans($params);   
                break; 
            }            

        return $result;

    }


    /**
     * récupère la transcription suivante
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getNextTrans($params){
        $query='SELECT 
            tn.idFrag
        FROM
            transcriptions t
                INNER JOIN
            transcriptions tn ON tn.idDisque = t.idDisque
                AND tn.start = t.end
        WHERE
            t.idFrag = ?';
        $rs = $this->conn->fetchAll($query,[$params['idFrag']]);
        if(count($rs)){
            $rs = $this->timelineConceptAnnexe(['idFrag'=>$rs[0]['idFrag']]);
        }else{
            //si pas de réponse on passe au cours suivant
            $query='SELECT 
                t.idFrag
            FROM
                conferences c
                    INNER JOIN
                conferences cn ON cn.created > c.created
                    INNER JOIN
                transcriptions t ON t.idConf = cn.id
            WHERE
                c.id = ?
            ORDER BY cn.created , t.start
            LIMIT 0 , 1
            ';
            $rs = $this->conn->fetchAll($query,[$params['idConf']]);            
            $rs = $this->timelineConceptAnnexe(['idFrag'=>$rs[0]['idFrag']]);
        }                
        return $rs;        
    }

    
    

    /* pour récupérer les flacs en local
    1. charger dans l'ancienne base dans omk_test
    2. exporter la réquête suivante en cvs
    select m.storage_id, m.extension, r.id
    from media m
    left join omk_test.resource r on r.id = m.id
    where m.extension = 'flac' and r.id is null
    3. créer les lignes de commandes
    4. excuter les lignes de commandes
    */
    function getFlacFromServeur(){
        $query = "SELECT 
                m.storage_id, m.extension, r.id, m.size
            FROM
                media m
                    LEFT JOIN
                omk_test.resource r ON r.id = m.id
            WHERE
                m.extension = 'flac' AND r.id IS NULL
            ORDER BY r.id";
        $rs = $this->conn->fetchAll($query);  
        $numReprise = 0;//count($rs)-(2270-1294);
        foreach ($rs as $i=>$r) {
            if($i>=$numReprise){
                $url = "/Users/hnparis8/Sites/omk_deleuze/files/original/".$r['storage_id'].".".$r['extension'];
                if (file_exists($url)) {
                    echo "existe;".$r['storage_id'].".".$r['extension'].";".$r['size'].";".filesize($url)."<br>";
                }else{
                    echo "abscent;".$r['storage_id'].".".$r['extension']."<br>";
                    echo "curl -o ".$r['storage_id'].".".$r['extension']
                    ." http://127.0.0.1:8181/omk_deleuze/files/original/".$r['storage_id'].".".$r['extension']."<br>";
                }
            }
        }           
    }

    /**
     * récupère l'extrapolation d'une note
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getNoteExtrapolation($params){
        $query='SELECT DISTINCT t.id FROM transcriptions t 
            WHERE t.texte Like "%'.$params['note'].'%"';
        $rs = $this->conn->fetchAll($query);                
        return $rs;        
    }


    /**
     * récupère les transcription pour un concept
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getConceptTrans($params){
        $select = "";
        $inner = "";
        if($params["idsConcept"]){            
            $concepts = explode(",",$params["idsConcept"]);
            $select = ",";
            foreach ($concepts as $i=>$c) {
                $select .= " GROUP_CONCAT(CONCAT(tc".$i.".start,'-',tc".$i.".end)) se".$i.",";
                $inner .= " INNER JOIN timeline_concept tc".$i." ON tc".$i.".idTrans = t.id
                    AND tc".$i.".idConcept = ?";
            }
            $select = substr($select,0,-1);
        }else{
            $concepts = [$params['idConcept']];
            $inner .= " INNER JOIN
            timeline_concept tc0 ON tc0.idTrans = t.id
                AND tc0.idConcept = ? ";
        }
        $query='SELECT 
            c.titre "Cours",
            c.theme "Theme",
            c.source "Source",
            c.promo "Promo",
            t.idConf,
            c.created "Date",
            t.agent "Agent",
            t.texte "Transcription",
            t.start "Début",
            t.end "Fin",
            t.file "Audio",
            tc0.idTrans,
            COUNT(tc0.id) "Nb."
        '.$select.'
        FROM
            transcriptions t
                INNER JOIN
            conferences c ON c.id = t.idConf
        '.$inner.'
        GROUP BY t.id
        ORDER BY c.created , t.start';
        $rs = $this->conn->fetchAll($query,$concepts);                
        return $rs;        
    }

        

    /**
     * récupère les conférences et leur stats
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getConferences($params){
        $query="SELECT 
            c.*,
            COUNT(DISTINCT (d.id)) nbDisque,
            COUNT(DISTINCT (t.agent)) nbAgent,
            COUNT(DISTINCT (idFrag)) nbFrag,
            COUNT(DISTINCT (t.id)) nbTrans,
            COUNT(DISTINCT (tc.idConcept)) nbConcept
        FROM
            conferences c
                INNER JOIN
            transcriptions t ON t.idConf = c.id
                INNER JOIN
            disques d ON d.idConf = c.id
                INNER JOIN
            timeline_concept tc ON tc.idTrans = t.id
        GROUP BY c.id";
        $rs = $this->conn->fetchAll($query);                
        return $rs;      
    } 

    /**
     * ajoute un concept
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function addConcept($params){
        $query="INSERT INTO `concepts` (`id`, `label`) VALUES (?, ?)";
        $rs = $this->conn->fetchAll($query,[
            $params['id'],
            $params['label']
        ]);                
        return $rs;      
    }

    /**
     * renvoie un concept
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getConcept($params){
        $query="SELECT id, label
        FROM concepts
        WHERE label = ?
        ";
        $rs = $this->conn->fetchAll($query,[
            $params['label']
        ]);                
        return $rs;        
    }    
    /**
     * renvoie un concept
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function suggestConcept($params){
        $query="SELECT id, label
        FROM concepts
        WHERE label LIKE ?
        LIMIT 0, 100";
        $rs = $this->conn->fetchAll($query,[
            "%".$params['label']."%"
        ]);                
        return $rs;        
    }        
   /**
     * calcul le markdown d'une transcription
     * utile pour le RAG
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getMarkdownTranscription($params){
        //TODO : tester avec cette syntaxe : (graphDB, has description, “A triple-store platform, built on rdf4j. Links diverse data, indexes it for semantic search and enriches it via text analysis to build big knowledge graphs.”)

        $t = $this->api->read('items', $params['id'])->getContent();
        $txt = "#Transcription ".$params['id']."\n";
        $d = json_decode(json_encode($t), true);
        $txt .= "A comme cours : ".$d['ma:isFragmentOf'][0]['display_title']."\n";
        $txt .= "Est une transcription du fragment de cours :".$d['oa:hasSource'][0]['display_title']."\n";
        foreach ($d['lexinfo:segmentation'] as $k => $v) {
            $txt .= "A comme phrase ".$k." : ".$v["@annotation"]["lexinfo:partOfSpeech"][0]["@value"]."\n";
        }
        return [['txt'=>$txt]];
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
     * renvoie la transcription complète d'une conférence
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getConferenceTexte($params){
        $query="SELECT vFrag.resource_id idTrans, vFragTitle.value txt
        FROM item i
            inner join value vFrag on vFrag.property_id = ? and vFrag.value_resource_id = i.id
            inner join value vFragTitle on vFragTitle.property_id = ? and vFragTitle.resource_id = vFrag.resource_id
        WHERE i.id = ?
        ORDER BY vFrag.resource_id
        ";
        //$query .=" LIMIT 0, 10";
        $rs = $this->conn->fetchAll($query,[
            $this->api->search('properties', ['term' => 'ma:isFragmentOf'])->getContent()[0]->id(), 
            $this->api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]->id(),
            $params['id']
        ]);
                
        return $rs;       
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
     * renvoie les notes
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getTransNote($params){
        $p = [
            $this->api->search('properties', ['term' => 'oa:hasSource'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'jdc:degradColors'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'oa:start'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'oa:end'])->getContent()[0]->id(),            
            $this->api->search('resource_templates', ['label' => 'Note transcription'])->getContent()[0]->id(),            
        ];
        $inner = isset($params["idNote"]) ? "" : " and vTrans.value_resource_id = ? ";
        $where = isset($params["idNote"]) ? " AND r.id = ? " : "";
        $query = "SELECT 
                r.id id , vTitle.value titre,
                vTrans.value_resource_id idTrans,
                vColor.value color,
                vStart.value start,
                vEnd.value end
            FROM resource r
                inner join value vTrans on vTrans.property_id = $p[0] and vTrans.resource_id = r.id 
                    ".$inner."
                inner join value vTitle on vTitle.property_id = $p[1] and vTitle.resource_id = r.id
                inner join value vColor on vColor.property_id = $p[2] and vColor.resource_id = r.id
                inner join value vStart on vStart.property_id = $p[3] and vStart.resource_id = r.id
                inner join value vEnd on vEnd.property_id = $p[4] and vEnd.resource_id = r.id
            WHERE r.resource_template_id = $p[5]".$where;
        //echo $query;
        $rs = $this->conn->fetchAll($query,[
            isset($params["idNote"]) ? $params["idNote"] : $params['id']
        ]);    
        if(isset($params["idNote"])){
            $trans = $this->timelineConceptAnnexe(['idTrans'=>$rs[0]['idTrans']]);
            $rs[0]["idFrag"]=$trans[0]["idFrag"];
            return ['note'=>$rs[0],"trans"=>$trans];       
        }else
            return $rs;       
    }

    function getCache($params){

    }

    
   /**
     * renvoie la timeline des transcriptions à partir des annexes
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function timelineConceptAnnexe($params){
        $query = "SELECT 
            c.id idConf, c.theme titleConf, c.source source1, c.created, c.num,
            d.id idMediaConf, d.face , d.plage, d.uri source2,
            t.id idTrans, t.idFrag, t.start startFrag, t.end endFrag, t.agent creator, t.file source3,
            tc.id idAnno, tc.idConcept idCpt, tc.start startCpt, tc.end endCpt, tc.confidence confiance,
            cpt.label titleCpt, LENGTH(cpt.label) nbCar
        FROM
            conferences c
                INNER JOIN
            disques d ON d.idConf = c.id
                INNER JOIN
            transcriptions t ON t.idDisque = d.id
                INNER JOIN
            timeline_concept tc ON tc.idTrans = t.id
                INNER JOIN
                    concepts cpt ON cpt.id = tc.idConcept";
        if($params['idFrag']){
            $query.=" WHERE t.idFrag = ? ";    
            $rs = $this->conn->fetchAll($query,[
                $params['idFrag']
            ]);    
        }                        
        if($params['idConf']){
            $query.=" WHERE c.id = ? ";    
            $rs = $this->conn->fetchAll($query,[
                $params['idConf']
            ]);    
        }
        if($params['idTrans']){
            /* on récupère les identifiants de transcription
            */
            $query.=" WHERE t.id =".$params['idTrans'];    
            $rs = $this->conn->fetchAll($query);    
        }                                  
        if($params['cherche']){
            $finds = $this->getTransRecherche($params);
            $ids = array_map(function ($a) { return $a['id']; }, $finds);
            $query.=" WHERE t.id IN (".implode(",",$ids).")";    
            $timeline = $this->conn->fetchAll($query);
            $rs=['scores'=>$finds,'timeline'=>$timeline];    
        }                    
        return $rs;       
    }
    /**
     * renvoie les transcription pour une recherche
     *
     * @param array    $params paramètre de la requête
     * @return array
     */
    function getTransRecherche($params){

        $query = "SELECT id, MATCH (texte)
            AGAINST (? IN NATURAL LANGUAGE MODE) AS score
            FROM transcriptions
            WHERE MATCH (texte) AGAINST(? IN NATURAL LANGUAGE MODE)";
        $rs = $this->conn->fetchAll($query,[
            $params['cherche'],
            $params['cherche']
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

        //TODO:recherche les identifiants de propriété par le term

        $order =" ORDER BY idConf, mediaConfTrack, mediaConfTrackNum, CAST(startFrag AS DECIMAL(12,6)),  CAST(startCpt AS DECIMAL(6,2)) ";

        $p=$this->api->search('properties', ['term' => 'jdc:hasConcept'])->getContent()[0];
        $query="SELECT mConf.item_id idConf, vConf.value titleConf
			, mConf.id idMediaConf
			, vMediaTrack.value mediaConfTrack
            , vMediaTrackNum.value mediaConfTrackNum
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
            inner join value vMediaTrack on vMediaTrack.resource_id = mConf.id and vMediaTrack.property_id = 492
            inner join value vMediaTrackNum on vMediaTrackNum.resource_id = mConf.id and vMediaTrackNum.property_id = 484
            
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
        if($params['search']){
            /* on recherche uniquement dans :
            - les transcriptions = 412
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['search'],'resource-type'=>"item",'resource_class_id'=>"412"],
                ['returnScalar' => 'id'])->getContent();
            if(count($ids)==0)return [];
            $query.=" WHERE vTransAnno.resource_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }  
        if($params['getTrans']){
            /* on récupère les identifiants de transcription
            */
            $query.=" WHERE vTransAnno.resource_id IN (".$params['getTrans'].") ";    
            $rs = $this->conn->fetchAll($query);    
        }          
        if($params['searchValueAnno']){
            /* on recherche uniquement dans :
            - les transcriptions = 412
            */
            $ids=$this->api->search('value_annotations', 
                ['fulltext_search' => $params['search']],
                ['returnScalar' => 'id'])->getContent();
            if(count($ids)==0)return [];
            $query.=" WHERE vTransAnno.resource_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }          
        if($params['searchConf']){
            /* on recherche uniquement dans :
            - les conférences = 47
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['search'],'resource-type'=>"item",'resource_class_id'=>"47"],
                ['returnScalar' => 'id'])->getContent();
            $query.=" WHERE mConf.item_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }                
        if($params['searchCpt']){
            /* on recherche uniquement dans :
            - concept = 381
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['searchCpt'],'resource-type'=>"item",'resource_class_id'=>"381"],
                ['returnScalar' => 'id'])->getContent();
            //on fait une recherche générale si pas de concept
            if(count($ids)==0){
                $params['search']=$params['searchCpt'];
                $params['searchCpt']=false;
                $rs = $this->timelineConcept($params);
            }else{
                $query.=" WHERE vConcept.value_resource_id IN (".implode(",",$ids).")";    
                $rs = $this->conn->fetchAll($query);        
            }
        }                
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

     
   /**
     * renvoie la timeline d'une transcription
     * version interne omeka s
     * @param array    $params paramètre de la requête
     * @return array
     */
    function timelineTrans($params){

        
        $order =" ORDER BY idConf, CAST(startFrag AS DECIMAL(12,6)),  CAST(startCpt AS DECIMAL(6,2)) ";

        $p = [
            $this->api->search('properties', ['term' => 'dcterms:title'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'ma:hasFragment'])->getContent()[0]->id(),
            $this->api->search('properties', ['term' => 'curation:data'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'dcterms:creator'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'oa:hasSource'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'oa:start'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'oa:end'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'jdc:hasConcept'])->getContent()[0]->id(),            
            $this->api->search('properties', ['term' => 'lexinfo:confidence'])->getContent()[0]->id(),            
        ];
        $query="SELECT mConf.item_id idConf
            , vConf.value titleConf
			, mConf.id idMediaConf
            , rmConf.title titleMediaConf
            , vAnno.resource_id idAnno
            , vTransAnno.resource_id idTrans
            , vTransCrea.value creator
            , vFrag.value_resource_id idFrag
            , vFragStart.value startFrag
            , vFragEnd.value endFrag
            , vConcept.value_resource_id idCpt
            , vConceptTitre.value titleCpt
            , LENGTH(vConceptTitre.value) nbCar
            , vConceptStart.value startCpt
            , vConceptEnd.value endCpt
            , vConceptConf.value confiance
        FROM media mConf
            inner join resource rmConf ON rmConf.id = mConf.id
            inner join value vConf on vConf.resource_id = mConf.item_id and vConf.property_id = $p[0]
            inner join value vAnno on vAnno.value_resource_id = mConf.id and vAnno.property_id = $p[1]
            inner join value vTransAnno on vTransAnno.value_annotation_id = vAnno.resource_id and vTransAnno.property_id = $p[2]
            inner join value vTransCrea on vTransCrea.resource_id = vTransAnno.resource_id and vTransCrea.property_id = $p[3]
            inner join value vFrag on vFrag.resource_id = vTransAnno.resource_id and vFrag.property_id = $p[4]
            inner join value vFragStart on vFragStart.resource_id = vFrag.value_resource_id and vFragStart.property_id = $p[5]
            inner join value vFragEnd on vFragEnd.resource_id = vFrag.value_resource_id and vFragEnd.property_id = $p[6]
            inner join value vConcept on vConcept.resource_id = vAnno.resource_id and vConcept.property_id = $p[7]
            inner join value vConceptTitre on vConceptTitre.resource_id = vConcept.value_resource_id and vConceptTitre.property_id = $p[0]
            inner join value vConceptStart on vConceptStart.resource_id = vAnno.resource_id and vConceptStart.property_id = $p[5]
            inner join value vConceptEnd on vConceptEnd.resource_id = vAnno.resource_id and vConceptEnd.property_id = $p[6]
            inner join value vConceptConf on vConceptConf.resource_id = vAnno.resource_id and vConceptConf.property_id = $p[8] 
        ";
        //echo $query;
        if($params['search']){
            /* on recherche uniquement dans :
            - les transcriptions = 412
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['search'],'resource-type'=>"item",'resource_class_id'=>"412"],
                ['returnScalar' => 'id'])->getContent();
            if(count($ids)==0)return [];
            $query.=" WHERE vTransAnno.resource_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }  
        if($params['getTrans']){
            /* on récupère les identifiants de transcription
            */
            $query.=" WHERE vTransAnno.resource_id IN (".$params['getTrans'].") ";    
            $rs = $this->conn->fetchAll($query);    
        }          
        if($params['searchValueAnno']){
            /* on recherche uniquement dans :
            - les transcriptions = 412
            */
            $ids=$this->api->search('value_annotations', 
                ['fulltext_search' => $params['search']],
                ['returnScalar' => 'id'])->getContent();
            if(count($ids)==0)return [];
            $query.=" WHERE vTransAnno.resource_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }          
        if($params['searchConf']){
            /* on recherche uniquement dans :
            - les conférences = 47
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['search'],'resource-type'=>"item",'resource_class_id'=>"47"],
                ['returnScalar' => 'id'])->getContent();
            $query.=" WHERE mConf.item_id IN (".implode(",",$ids).")";    
            $rs = $this->conn->fetchAll($query);    
        }                
        if($params['searchCpt']){
            /* on recherche uniquement dans :
            - concept = 381
            */
            $ids=$this->api->search('items', 
                ['fulltext_search' => $params['searchCpt'],'resource-type'=>"item",'resource_class_id'=>"381"],
                ['returnScalar' => 'id'])->getContent();
            //on fait une recherche générale si pas de concept
            if(count($ids)==0){
                $params['search']=$params['searchCpt'];
                $params['searchCpt']=false;
                $rs = $this->timelineConcept($params);
            }else{
                $query.=" WHERE vConcept.value_resource_id IN (".implode(",",$ids).")";    
                $rs = $this->conn->fetchAll($query);        
            }
        }                
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
   /*
    Vérifications
     
    -- Doublons mot
    SELECT  v.value, count(*) nb, v.resource_id
    FROM value v
    WHERE v.property_id = 198
    group by v.value, v.resource_id
    order by nb desc

    -- Doublons transcription
    SELECT v.value_resource_id, count(*) nb
    FROM value v
    WHERE v.property_id = 531
    GROUP BY v.value_resource_id
    order by nb desc        
    
    -- nombre de transcription par heure
    SELECT count(*) nb, DATE_FORMAT(r.created, "%D %b %Y %HH") grDate
    FROM resource r
    WHERE r.resource_class_id = 412
    GROUP BY grDate
    ORDER BY nb DESC;    

    -- temps de transcription et de création de l'item
    INSERT INTO exploDeleuze.trace_transcriptions (idConf,titreConf,nbFrag,deb,fin,duree,minWhisper,maxWhisper,minSql,maxSql)

    SELECT idConf, titreConf, COUNT(*) nbFrag, MIN(paramWhisper) deb, MAX(creaItem) fin, TIMEDIFF (MAX(creaItem), MIN(paramWhisper)) duree,
MIN(tempsWhisper) minWhisper, MAX(tempsWhisper) maxWhisper, MIN(tempsSql) minSql, MAX(tempsSql) maxSql
FROM (SELECT 
    l0.id idParam, l0.created paramWhisper, 
    l.id idWhisper, l.created whisper,
    l1.id idItem, l1.created creaItem,
    SUBSTRING(l1.message,48, LENGTH(l1.message)-47) idTrans,
    TIMEDIFF (l.created, l0.created) tempsWhisper,
    TIMEDIFF (l1.created, l.created) tempsSql,
    TIMEDIFF (l1.created, l0.created) tempsTotal,
    vTrans.value_resource_id idConf,
    vConf.value titreConf
    FROM log l 
    inner join log l0 on l0.id = (l.id-1)
    inner join log l1 on l1.id = (l.id+1)
    inner join value vTrans on vTrans.resource_id = SUBSTRING(l1.message,48, LENGTH(l1.message)-47) AND vTrans.property_id = 451
    inner join value vConf on vConf.resource_id = vTrans.value_resource_id AND vConf.property_id = 1
    WHERE l.context LIKE '{"output":"%'
     ) calc
 GROUP BY idConf  
ORDER BY `deb` DESC; 

    -- temps de création des fragments
    INSERT INTO exploDeleuze.trace_fragments (idConf,deb,fin,minCrea,maxCrea,nb,total)

    SELECT conf, MIN(crea) deb, MIN(finCrea) fin,  MIN(tempsCrea) minCrea, MAX(tempsCrea) maxCrea, COUNT(*) nb, SEC_TO_TIME(SUM(tempsCrea)) total 
    FROM ( 
        SELECT SUBSTRING(l.context, POSITION(":" IN l.context)+1, POSITION("," IN l.context)-POSITION(":" IN l.context)-1) conf, 
            l.id idCrea, l.created crea, 
            l1.id idFin, l1.created finCrea, 
            TIMEDIFF (l1.created, l.created) tempsCrea 
        FROM log l inner join log l1 on l1.id = (l.id+1) 
        WHERE l.message LIKE '%chaoticum media created%' 
    ) calc GROUP BY conf;

    -- create transcription annexe
 INSERT INTO transcriptions (idConf, idFrag, start, end, file, id, texte, agent, idDisque)
SELECT i.id confId, 
vFrag.value_resource_id fragId,  vFragDeb.value 'deb', vFragEnd.value 'end', CONCAT("files/original/",vFragMedia.storage_id,".",vFragMedia.extension) 'file',
vTrans.resource_id transId, vTransTitle.value transText, vTransAgent.value agent,
vFragSource.value_resource_id idPiste

--  , LENGTH(vTransTitle.value) nbCar
FROM item i
inner join value vTrans on vTrans.property_id = 451 and vTrans.value_resource_id = i.id
inner join value vTransTitle on vTransTitle.property_id = 1 and vTransTitle.resource_id = vTrans.resource_id
inner join value vTransAgent on vTransAgent.property_id = 2 and vTransAgent.resource_id = vTrans.resource_id
inner join value vFrag on vFrag.property_id = 531 and vFrag.resource_id = vTrans.resource_id
inner join value vFragDeb on vFragDeb.property_id = 543 and vFragDeb.resource_id = vFrag.value_resource_id
inner join value vFragEnd on vFragEnd.property_id = 524 and vFragEnd.resource_id = vFrag.value_resource_id
inner join media vFragMedia on vFragMedia.id = vFrag.value_resource_id
inner join value vFragSource on vFragSource.property_id = 451 and vFragSource.resource_id = vFrag.value_resource_id
--  WHERE vTrans.resource_id = 2059
-- ORDER BY nbCar DESC

-- create disque annexe
INSERT INTO disques (id, idConf, uri, face, plage)
SELECT m.id
 , m.item_id confId  
 , vUri.value uri, vFace.value face, vPlage.value plage
FROM media m
inner join value vUri on vUri.property_id = 11 and vUri.resource_id = m.id
inner join value vFace on vFace.property_id = 492 and vFace.resource_id = m.id
inner join value vPlage on vPlage.property_id = 484 and vPlage.resource_id = m.id
WHERE m.extension = "mp3" and vUri.value <> ''


    -- create conference annexe
 INSERT INTO conferences (id, titre, created, ref, source,promo,theme,num)
SELECT r.id confId, vTitle.value titre, vDate.value dt, vRef.value ref, vSrc.value src 
, vPromo.value promo, vTheme.value theme, vNum.value num
-- , LENGTH(vTitle.value) nbCar
FROM resource r
inner join value vTitle on vTitle.property_id = 1 and vTitle.resource_id = r.id
inner join value vDate on vDate.property_id = 7 and vDate.resource_id = r.id
inner join value vSrc on vSrc.property_id = 11 and vSrc.resource_id = r.id
inner join value vRef on vRef.property_id = 35 and vRef.resource_id = r.id
inner join value vTheme on vTheme.property_id = 195 and vTheme.resource_id = r.id
inner join value vNum on vNum.property_id = 203 and vNum.resource_id = r.id
inner join value vPromo on vPromo.property_id = 21 and vPromo.resource_id = r.id
WHERE r.resource_class_id = 47

   -- mettre à jour l'annexe des conférence avec les mots-clefs
UPDATE conferences as cref,  
    (
SELECT c.id, concat('[',group_concat(JSON_OBJECT('id', v.value_resource_id, 'label', mc.value)),']') o
FROM conferences c
inner join value v on v.resource_id = c.id and v.property_id = 3
inner join value mc on mc.resource_id = v.value_resource_id and mc.property_id = 1
group by c.id) as co
SET cref.sujets = co.o
WHERE cref.id = co.id;
UPDATE conferences set sujets = "[]" where length(sujets)=4;




    -- create concepts annexe
INSERT INTO concepts (id, label)
SELECT v.resource_id, v.value
FROM value v 
INNER JOIN resource r ON r.id = v.resource_id AND r.resource_class_id = 381
WHERE v.property_id = 1

    -- create timeline_concept annexe

 INSERT INTO timeline_concept (idAnno, idTrans, idConcept, start, end, confidence)
select v.value_annotation_id,
 t.id idTrans, vAnnoCpt.value_resource_id idConcept,
 vAnnoStart.value 'start', vAnnoEnd.value 'end', vAnnoConfi.value confi
from resource r
inner join transcriptions t on r.id = t.id
inner join value v on v.resource_id = r.id
inner join value vAnnoCpt on vAnnoCpt.resource_id = v.value_annotation_id and vAnnoCpt.property_id = 216
 inner join value vAnnoStart on vAnnoStart.resource_id = v.value_annotation_id and vAnnoStart.property_id = 543
 inner join value vAnnoEnd on vAnnoEnd.resource_id = v.value_annotation_id and vAnnoEnd.property_id = 524
 inner join value vAnnoConfi on vAnnoConfi.resource_id = v.value_annotation_id and vAnnoConfi.property_id = 404

 */