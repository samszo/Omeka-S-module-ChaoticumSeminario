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
                    'numCompForItems' => 'Compétences pour les items', // @translate
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
            $urlNode = $this->getUrlNode($url, $slug,$r["id"]);
            $n = [
                "id"=>$r["id"],
                "label"=>$r["title"],
                "comment"=>"",
                "size"=>$r["nb_items"],
                "url"=>$urlNode,
                "group_id"=>$r["grId"],
                "group_label"=>$r["grLabel"],
             ];
            $dataset['nodes'][]=$n;
            //ajoute les items en lien avec cette compétence
            $idsItems = explode(",",$r["idsItems"]);
            foreach ($idsItems as $idItem) {
                if (!isset($dbItems[$idItem])) {
                    $oItem = $api->read('items', $idItem)->getContent();
                    $itemNode = [
                        "id"=>$oItem->id(),
                        "label"=>$oItem->displayTitle(),
                        "comment"=>"",
                        "size"=>1,
                        "url"=>$this->getUrlNode($url, $slug,$oItem->id()),
                        "group_id"=>$oItem->resourceClass()->id(),
                        "group_label"=>$oItem->resourceClass()->label()
                    ];
                    $dataset['nodes'][]=$itemNode;
                    $dbItems[$idItem]=$itemNode;
                }else{
                    $itemNode = $dbItems[$idItem];
                    $dbItems[$idItem]["size"]+=1;
                }   
                //ajoute le lien entre la compétence et l'item
                $link = [
                    "source"=>$r["id"],
                    "source_label"=>$r["title"],
                    "source_url"=>$urlNode,
                    "target"=>$itemNode["id"],
                    "target_label"=>$itemNode["label"],
                    "target_url"=>$itemNode["url"],
                    "link_id"=>count($dataset['links']),
                    "link_label"=>"A comme compétence",// @translate
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