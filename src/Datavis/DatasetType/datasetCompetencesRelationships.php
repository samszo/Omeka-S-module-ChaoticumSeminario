<?php
namespace ChaoticumSeminario\Datavis\DatasetType;

use Datavis\Api\Representation\DatavisVisRepresentation;
use Datavis\DatasetType\AbstractDatasetType;
use Laminas\Form\Fieldset;
use Laminas\Form\Element;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Representation\SiteRepresentation;

class datasetCompetencesRelationships extends AbstractDatasetType
{
    /**
     * Get the label of this dataset type.
     *
     * @return string
     */
    public function getLabel() : string
    {
        return 'Relations entre les compétences'; // @translate
    }

    /**
     * Get the description of this dataset type.
     *
     * @return string
     */
    public function getDescription() : ?string
    {
        return 'Visualise les relations entre les compétences.'; // @translate
    }

    /**
     * Get the names of the diagram types that are compatible with this dataset.
     *
     * @return array
     */
    public function getDiagramTypeNames() : array
    {
        return ['diagramCompetencesRelationships'];
    }

    /**
     * Add the form elements used for the dataset data.
     *
     * @param SiteRepresentation $site
     * @param Fieldset $fieldset
     */
    public function addElements(SiteRepresentation $site, Fieldset $fieldset) : void
    {
        $fieldset->add([
            'type' => Element\Select::class,
            'name' => 'typeQuery',
            'options' => [
                'label' => 'Quel type de requêtes pour récupérer les compétences ?', // @translate
                'value_options' => [
                    'numCompForItems' => 'Compétences pour les documents', // @translate
                    'numCompForAutors' => 'Compétences pour les auteurs', // @translate
                ],
            ],
            'attributes' => [
                'value' => 'numCompForItems',
                'required' => true,
            ]
        ]);
    }

    /**
     * Generate and return the JSON dataset given a visualiation.
     *
     * @param ServiceManager $services
     * @param DatavisVisRepresentation $vis
     * @return array
     */
    public function getDataset(ServiceManager $services, DatavisVisRepresentation $vis) : array
    {
        $csSql =$services->get('ViewHelperManager')->get('chaoticumSeminarioSql');
        $rs = $csSql->getDataForVis($vis,$this->getItemIds($services, $vis));
        $api = $services->get('Omeka\ApiManager');
        $url = $services->get('ViewHelperManager')->get('Url');
        $slug = $vis->site()->slug();
        $dtsData = $vis->datasetData();
        /* 
        * Format du dataset retourné :{
        *   nodes: [
        *     {
        *        id: <int>,
        *        label: <string>,
        *        comment: <string>,
        *        url: <string>,
        *        group_id: <int>,
        *        group_label: <string>
        *     }
        *   ],
        *   links: [
        *     {
        *       source: <int>,
        *       source_label: <string>,
        *       source_url: <string>,
        *       target: <int>,
        *       target_label: <string>,
        *       target_url: <string>,
        *       link_id: <int>,
        *       link_label: <string>,
        *     }
        *   ]
        * }*/
        $dataset = [
            'nodes' => [],
            'links' => []
        ]; 
        $dbItems=[];
        foreach ($rs as $r) {
            //ajoute le noeud compétence
            if (!isset($dbItems[$r["idComp"]])) {
                $dbItems[$r["idComp"]]=[
                    "id"=>$r["idComp"],
                    "size"=>0
                ];
                $urlComp = $this->getUrlNode($url, $slug,$r["idComp"]);
                $n = [
                    "id"=>$r["idComp"],
                    "label"=>$r["titleComp"],
                    "comment"=>"",
                    "size"=>1,
                    "url"=>$urlComp,
                    "group_id"=>$r["grIdComp"],
                    "group_label"=>$r["grLabelComp"],
                ];
                $dataset['nodes'][]=$n;
            }else{
                $dbItems[$r["idComp"]]["size"]+=1;
            }
            //ajoute les items en lien avec cette compétence
            if (!isset($dbItems[$r["idSrc"]])) {
                //$oItem = $api->read('items', $idItem)->getContent();
                $itemNode = [
                    "id"=>$r["idSrc"],
                    "label"=>$r["titleSrc"],
                    "comment"=>"",
                    "size"=>1,
                    "url"=>$this->getUrlNode($url, $slug,$r["idSrc"]),
                    "group_id"=>$r["grIdSrc"],
                    "group_label"=>$r["grLabelSrc"]
                ];
                $dataset['nodes'][]=$itemNode;
                $dbItems[$r["idSrc"]]=$itemNode;
            }else{
                $itemNode = $dbItems[$r["idSrc"]];
                $dbItems[$r["idSrc"]]["size"]+=1;
            }   
            //ajoute le lien entre la compétence et l'item source
            //TODO: prendre en compte la propriété qui sert pour le calcul des compétences = oa:scope
            $link = [
                "target"=>$r["idComp"],
                "target_label"=>$r["titleComp"],
                "target_url"=>$urlComp,
                "source"=>$itemNode["id"],
                "source_label"=>$itemNode["label"],
                "source_url"=>$itemNode["url"],
                "link_id"=>count($dataset['links']),
                "link_label"=>$r["grLabelSrc"]." => ".$r["grLabelComp"],// @translate
                ];
            $dataset['links'][]=$link;
            //ajoute la destination si elle existe
            if($r["idDst"] && $r["idDst"]!=$r["idSrc"]){
                //ajoute les items en lien avec cette compétence
                if (!isset($dbItems[$r["idDst"]])) {
                    //$oItem = $api->read('items', $idItem)->getContent();
                    $itemNode = [
                        "id"=>$r["idDst"],
                        "label"=>$r["titleDst"],
                        "comment"=>"",
                        "size"=>1,
                        "url"=>$this->getUrlNode($url, $slug,$r["idDst"]),
                        "group_id"=>$r["grIdDst"],
                        "group_label"=>$r["grLabelDst"]
                    ];
                    $dataset['nodes'][]=$itemNode;
                    $dbItems[$r["idDst"]]=$itemNode;
                }else{
                    $itemNode = $dbItems[$r["idDst"]];
                    $dbItems[$r["idDst"]]["size"]+=1;       
                }
                //avec le lien entre l'item source et l'item destination
                $link = [
                    "target"=>$itemNode["id"],
                    "target_label"=>$itemNode["label"],
                    "target_url"=>$itemNode["url"],
                    "source"=>$r["idSrc"],
                    "source_label"=>$dbItems[$r["idSrc"]]["label"],
                    "source_url"=>$dbItems[$r["idSrc"]]["url"],
                    "link_id"=>count($dataset['links']),
                    "link_label"=>$r["grLabelSrc"]." => ".$r["grLabelDst"],// @translate
                    ];
                $dataset['links'][]=$link;  
            }
        } 
        //met à jour les poids des noeuds items
        foreach ($dataset['nodes'] as &$node) {
            if (isset($dbItems[$node["id"]])) {
                $node["size"]=$dbItems[$node["id"]]["size"];
            }
        }
        return $dataset;
    }

    function getUrlNode($url, $slug, string $idNode) : string
    {
        return $url(
            'site/resource-id',
            [
                'site-slug' => $slug,
                'controller' => 'item',
                'id' => $idNode,
            ],
            [
                'force_canonical' => false,
            ]
        );
    }   
}