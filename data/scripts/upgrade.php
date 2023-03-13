<?php declare(strict_types=1);

namespace ChaoticumSeminario;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '0.0.3', '<')) {
    $sql = <<<'SQL'
UPDATE site_page_block
SET layout = "chaoticumSeminario"
WHERE layout = "ChaoticumSeminario";
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '0.0.4', '<')) {
    /**
     * Convertir les métadonnées transcriptions.
     * Chaque mot devient un numéro de référence avec un orateur, un concept,
     * un début, une fin, un pourcentage de confiance.
     *
     * Il y avait six possibilités de faire :
     * - convertir ces informations en item "mot transcrit" et faire des liens (le plus simple).
     * - utiliser le "hasConcept" et  les informations en annotations de valeur (le plus facile à gérer dans Omeka, mais risque de poser des problème de doublons).
     * - ajouter une propriété "mot" et  les informations en annotations de valeur (le plus facile à gérer dans Omeka).
     * - utiliser les annotations web (ce ne sont pas des annotations en tant que telle).
     * - utiliser un format avec une valeur unique du genre "mot/speaker/debut/fin/confiance" (trop complexe à interroger).
     * - utiliser un type de données "mot" (trop complexe à mettre en place et nécessite des développements en cas d'ajout d'informations).
     *
     * La 3e solution a été préférée dans un premier temps, mais il est possible de convertir dans la 1e, la 2e ou la 4e.
     */

    $templateTranscriptionId = $api->searchOne('resource_templates', ['label' => 'Transcription'], ['returnScalar' => 'id'])->getContent();
    $curationDataId = $api->searchOne('properties', ['term' => 'curation:data'], ['returnScalar' => 'id'])->getContent();
    // $jdcHasConceptId = $api->searchOne('properties', ['term' => 'jdc:hasConcept'], ['returnScalar' => 'id'])->getContent();

    // Complexe à faire avec des requêtes.
    /*
    $sql = <<<SQL
# Créer autant de curation:data (ou autre) qu'il y a de jdc:hasConcept dans des items ayant le modèle "Transcription".
INSERT INTO `value` (resource_id, property_id, value_resource_id, type, lang, value, uri, is_public, value_annotation_id)
SELECT DISTINCT `value`.`resource_id`, :curation_data_id, NULL, "literal", NULL, VALUE, NULL, 1, NULL
FROM `resource`
JOIN `item` ON `item`.`id` = `resource`.`id`
JOIN `value` ON `value`.`resource_id` = `resource`.`id` AND `value`.`property_id` = :jdc_has_concept_id
;

# Créer les annotations de valeurs et les attacher aux curation:data correspondantes.
# Actuellement, les annotations de valeurs n'ont pas de modèle.
INSERT INTO `resource` (owner_id	, resource_class_id, resource_template_id, thumbnail_id, title, is_public, created, modified, resource_type)
SELECT DISTINCT `resource`.`owner_id`, NULL, NULL, NULL, NULL, 1, `resource`.`created`, NULL, "Omeka\\Entity\ValueAnnotation"
FROM `resource`
JOIN `item` ON `item`.`id` = `resource`.`id`
JOIN `value` ON `value`.`resource_id` = `resource`.`id` AND `value`.`property_id` = :jdc_has_concept_id
;
SQL;
    $bind = [
        'curation_data_id' => $curationDataId,
        'jdc_has_concept_id' => $jdcHasConceptId,
    ];
    $types = [
        'curation_data_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        'jdc_has_concept_id' => \Doctrine\DBAL\ParameterType::INTEGER,
    ];
    $connection->executeStatement($sql, $bind, $types);
    */

    $itemIds = $api->search('items', ['resource_template_id' => $templateTranscriptionId], ['returnScalar' => 'id'])->getContent();

    $message = new Message(
        '%d transcriptions à mettre à jour.', // @translate
        count($itemIds)
    );
    $messenger->addSuccess($message);
    $logger->notice((string) $message);

    foreach (array_chunk($itemIds, 1000) as $idsChunk) {
        $items = $api->search('items', ['id' => $idsChunk])->getContent();
        /** @var \Omeka\Api\Representation\ItemRepresentation[] $items */
        foreach ($items as $item) {
            $itemId = $item->id();
            // jsonSerialize() ne serialize pas les resources liées.
            $data = json_decode(json_encode($item), true);
            if (empty($data['jdc:hasConcept'])) {
                continue;
            }
            // Créer autant de curation:data (ou autre) qu'il y a de jdc:hasConcept dans des items ayant le modèle "Transcription".

            // Pour le titre, utiliser le premier oa:hasSource avec media/dcterms:isReferencedBy
            // et ajouter un index partant de 1 (numéro du mot transcrit dans le fragment).
            /** @var \Omeka\Api\Representation\MediaRepresentation $mediaSource */
            $mediaSource = null;
            $sources = $item->value('oa:hasSource', ['type' => ['resource', 'resource:media'], 'all' => true]);
            foreach ($sources as $value) {
                $vr = $value->valueResource();
                if ($vr instanceof \Omeka\Api\Representation\MediaRepresentation) {
                    $mediaSource = $vr;
                    break;
                }
            }
            if (!$mediaSource) {
                if ($sources) {
                    $mediaSource = $sources[0]->valueResource();
                    $logger->notice(sprintf('L’item #%d Transcription n’a pas de media source.', $itemId));
                } else {
                    $logger->warn(sprintf('L’item #%d Transcription n’a pas de media source ni de référence.', $itemId));
                }
            }
            if ($mediaSource) {
                $baseIndex = ($mediaSource instanceof \Omeka\Api\Representation\MediaRepresentation ? 'm' : 'r') . $mediaSource->id();
                $baseIndexTitle = $mediaSource->value('dcterms:isReferencedBy');
                if ($baseIndexTitle) {
                    $baseIndexTitle = pathinfo((string) $baseIndexTitle, PATHINFO_FILENAME);
                } else {
                    $baseIndexTitle = pathinfo($mediaSource->source() ?: '', PATHINFO_FILENAME);
                    $logger->warn(sprintf('Le media #%d de l’item #%d Transcription n’a pas "dcterms:isReferencedBy.', $mediaSource->id(), $itemId));
                }
            } else {
                $baseIndex = 0;
                $baseIndexTitle = '';
            }

            $speakers = [];
            $conceptIds = [];
            $concepts = [];
            $starts = [];
            $ends = [];
            $confidences = [];

            // Utilise prefered label et titre, mais pas d'identifiant précis.
            foreach ($item->value('jdc:hasConcept', ['all' => true]) as $value) {
                $vr = $value->valueResource();
                $conceptIds[] = $vr->id();
                $concepts[] = $vr->displayTitle();
            }
            foreach ($item->value('oa:start', ['all' => true]) as $value) {
                $starts[] = $value->value();
            }
            foreach ($item->value('oa:end', ['all' => true]) as $value) {
                $ends[] = $value->value();
            }
            foreach ($item->value('lexinfo:confidence', ['all' => true]) as $value) {
                $confidences[] = $value->value();
            }
            foreach ($item->value('dbo:speaker', ['all' => true]) as $value) {
                $speakers[] = $value->value();
            }

            if (count($concepts) !== count($speakers)
                || count($concepts) !== count($starts)
                || count($concepts) !== count($ends)
                || count($concepts) !== count($confidences)
            ) {
                $logger->err(sprintf('L’item #%d Transcription ne peut pas être traité : le nombre d’orateurs, concepts, débuts, fins et confiances n’est pas le même.', $itemId));
                continue;
            }

            foreach (array_keys($concepts) as $key) {
                $data['curation:data'][] = [
                    'property_id' => $curationDataId,
                    'type' => 'literal',
                    '@value' => $baseIndex . '/' . $starts[$key] . '/' . $conceptIds[$key] . ' [' . $baseIndexTitle . '] (' . $concepts[$key] . ')',
                    '@annotation' => [
                        'jdc:hasConcept' => [$data['jdc:hasConcept'][$key]],
                        'oa:start' => [$data['oa:start'][$key]],
                        'oa:end' => [$data['oa:end'][$key]],
                        'lexinfo:confidence' => [$data['lexinfo:confidence'][$key]],
                        'dbo:speaker' => [$data['dbo:speaker'][$key]],
                    ],
                ];
            }
            unset(
                $data['jdc:hasConcept'],
                $data['oa:start'],
                $data['oa:end'],
                $data['lexinfo:confidence'],
                $data['dbo:speaker']
            );

            $api->update('items', $itemId, $data, [], ['isPartial' => true]);
            $logger->info(sprintf('L’item #%d a été mis à jour.', $itemId));
        }

        // Pour résoudre le problème de doctrine/entitymanager clear, il faut
        // plus de mémoire, mais c'est suffisant sur la base actuelle.

        // Avoid memory issue.
        // $entityManager->clear();

        // Avoid doctrine issue.
        $services->get('Omeka\AuthenticationService')->getIdentity();
        $api->read('users', $item->owner()->id(), [], ['responseContent' => 'resource'])->getContent();
    }

    $message = new Message(
        'Les métadonnées transcriptions ont été converties en annotation de valeur. Consulter les logs pour plus d’info.' // @translate
    );
    $messenger->addSuccess($message);
}
