<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Recherche Wikidata pour les items "Reference transcription" : cherche le
 * texte de dcterms:description sur Wikidata, et pour le type "personne"
 * retrouve ou crée un item "ref Personne" puis relie tout à la transcription.
 *
 * Comme pour TranscriptionCorrection : les mises à jour partielles avec
 * collectionAction=replace doivent toujours resoumettre le JSON-LD complet de
 * l'item (sinon ValueHydrator supprime toutes les valeurs non resoumises,
 * quel que soit leur terme de propriété - @see ValueHydrator::hydrate()).
 * En revanche collectionAction=append avec isPartial=true est sûr même en ne
 * soumettant qu'une seule propriété : chaque valeur soumise crée toujours une
 * Value neuve et rien n'est supprimé.
 */
class WikidataReference extends AbstractHelper
{
    const WIKIDATA_API = 'https://www.wikidata.org/w/api.php';

    const TYPE_TEMPLATES = [
        'personne' => 'ref Personne',
    ];

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var ChaoticumSeminario
     */
    protected $cs;

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct(ApiManager $api, ChaoticumSeminario $cs, Logger $logger)
    {
        $this->api = $api;
        $this->cs = $cs;
        $this->logger = $logger;
    }

    public function __invoke(array $params = [])
    {
        switch ($params['action'] ?? null) {
            case 'preview':
                return $this->preview($params['reference'], $params['query'] ?? null);
            case 'run':
                return $this->run($params['reference'], $params['transcription'], $params['qid']);
            default:
                return null;
        }
    }

    /**
     * Transcription ciblée par défaut par une référence (dcterms:source).
     */
    public function getDefaultTranscription(ItemRepresentation $reference): ?ItemRepresentation
    {
        $source = $reference->value('dcterms:source');
        if (!$source) {
            return null;
        }
        $resource = $source->valueResource();
        return $resource instanceof ItemRepresentation ? $resource : null;
    }

    /**
     * Aperçu : lance la recherche Wikidata (sur dcterms:description, ou sur
     * $query si fourni - recherche libre déclenchée manuellement quand la
     * recherche automatique ne renvoie rien) et, si jdc:surTout=oui, calcule
     * les transcriptions sœurs. Ne modifie rien.
     */
    public function preview(ItemRepresentation $reference, ?string $query = null): array
    {
        $type = (string) $reference->value('dcterms:type');
        $description = trim((string) $reference->value('dcterms:description'));
        $searchQuery = trim((string) $query) !== '' ? trim($query) : $description;
        $surTout = mb_strtolower((string) $reference->value('jdc:surTout')) === 'oui';
        $transcription = $this->getDefaultTranscription($reference);
        $supportsCreation = isset(self::TYPE_TEMPLATES[$type]);

        $result = [
            'type' => $type,
            'description' => $description,
            'searchQuery' => $searchQuery,
            'surTout' => $surTout,
            'supportsCreation' => $supportsCreation,
            'transcription' => $transcription,
            'candidates' => [],
            'searchError' => null,
            'siblings' => [],
        ];

        if ($searchQuery !== '') {
            try {
                $result['candidates'] = $this->searchWikidata($searchQuery);
            } catch (\Throwable $e) {
                $result['searchError'] = $e->getMessage();
            }
        }

        if ($surTout && $description !== '') {
            $result['siblings'] = $this->findSiblingTranscriptions($description, $transcription ? $transcription->id() : null);
        }

        return $result;
    }

    /**
     * Exécute l'ajout de la référence sur la transcription donnée et retourne le bilan.
     */
    public function run(ItemRepresentation $reference, ItemRepresentation $transcription, string $qid): array
    {
        $type = (string) $reference->value('dcterms:type');

        $bilan = [
            'reference' => ['id' => $reference->id(), 'title' => $reference->displayTitle()],
            'transcription' => ['id' => $transcription->id(), 'title' => $transcription->displayTitle()],
            'qid' => $qid,
            'person' => null,
            'errors' => [],
            'status_updated' => false,
        ];

        if (!isset(self::TYPE_TEMPLATES[$type])) {
            $bilan['errors'][] = 'Le type "' . $type . '" n\'est pas pris en charge pour l\'ajout automatique de référence.'; // @translate
            return $bilan;
        }

        try {
            $entity = $this->getEntityDetails($qid);
            [$person, $created] = $this->findOrCreatePerson($entity);
            $bilan['person'] = [
                'id' => $person->id(),
                'title' => $person->displayTitle(),
                'created' => $created,
            ];

            // Ajout (append, sûr) d'une référence sur la transcription, tracée par oa:motivatedBy.
            $this->api->update('items', $transcription->id(), [
                'dcterms:references' => [[
                    'property_id' => $this->cs->getProperty('dcterms:references')->id(),
                    'value_resource_id' => $person->id(),
                    'type' => 'resource',
                    '@annotation' => [
                        'oa:motivatedBy' => [[
                            'property_id' => $this->cs->getProperty('oa:motivatedBy')->id(),
                            'value_resource_id' => $reference->id(),
                            'type' => 'resource',
                        ]],
                    ],
                ]],
            ], [], [
                'isPartial' => true,
                'collectionAction' => 'append',
                'continueOnError' => true,
            ]);

            // Status + isReferencedBy sur l'item de référence : resoumission complète
            // du JSON-LD, car collectionAction=replace efface tout ce qui n'est pas
            // resoumis (voir la note en tête de fichier).
            $referenceData = json_decode(json_encode($reference->getJsonLd()), true);
            $referenceData['curation:status'] = [[
                'property_id' => $this->cs->getProperty('curation:status')->id(),
                '@value' => 'Référencé',
                'type' => 'literal',
            ]];
            $referenceData['dcterms:isReferencedBy'][] = [
                'property_id' => $this->cs->getProperty('dcterms:isReferencedBy')->id(),
                'value_resource_id' => $person->id(),
                'type' => 'resource',
            ];
            $this->api->update('items', $reference->id(), $referenceData, [], [
                'isPartial' => true,
                'collectionAction' => 'replace',
                'continueOnError' => true,
            ]);
            $bilan['status_updated'] = true;
        } catch (\Throwable $e) {
            $bilan['errors'][] = 'Erreur lors de l\'ajout de la référence : ' . $e->getMessage(); // @translate
        }

        return $bilan;
    }

    /**
     * Cherche d'autres transcriptions dont le titre contient le texte de la description.
     */
    public function findSiblingTranscriptions(string $description, ?int $excludeId = null): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }
        $query = [
            'resource_class_id' => $this->cs->getResourceClass('jdc:Transcription')->id(),
            'property' => [[
                'property' => $this->cs->getProperty('dcterms:title')->id(),
                'type' => 'in',
                'text' => $description,
            ]],
        ];
        $items = $this->api->search('items', $query)->getContent();
        if ($excludeId) {
            $items = array_values(array_filter($items, function ($i) use ($excludeId) {
                return $i->id() != $excludeId;
            }));
        }
        return $items;
    }

    /**
     * Même recherche que findSiblingTranscriptions(), mais ne renvoie que les
     * ids (pas les représentations complètes) : utilisé par le job qui traite
     * les transcriptions sœurs par lots pour éviter de charger d'un coup
     * beaucoup d'items en mémoire (@see Job\AddWikidataReferenceToSiblings).
     */
    public function findSiblingTranscriptionIds(string $description, ?int $excludeId = null): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }
        $ids = $this->api->search('items', [
            'resource_class_id' => $this->cs->getResourceClass('jdc:Transcription')->id(),
            'property' => [[
                'property' => $this->cs->getProperty('dcterms:title')->id(),
                'type' => 'in',
                'text' => $description,
            ]],
        ], ['returnScalar' => 'id'])->getContent();
        if ($excludeId) {
            $ids = array_values(array_filter($ids, function ($id) use ($excludeId) {
                return $id != $excludeId;
            }));
        }
        return array_values(array_map('intval', $ids));
    }

    /**
     * Recherche Wikidata (wbsearchentities). Retourne une liste de candidats
     * [qid, label, description, uri].
     */
    public function searchWikidata(string $query, string $lang = 'fr'): array
    {
        $params = [
            'action' => 'wbsearchentities',
            'search' => $query,
            'language' => $lang,
            'uselang' => $lang,
            'type' => 'item',
            'limit' => 10,
            'format' => 'json',
        ];
        $data = $this->wikidataRequest($params);
        $candidates = [];
        foreach ($data['search'] ?? [] as $row) {
            $candidates[] = [
                'qid' => $row['id'],
                'label' => $row['label'] ?? $row['id'],
                'description' => $row['description'] ?? '',
                'uri' => $row['concepturi'] ?? ('http://www.wikidata.org/entity/' . $row['id']),
            ];
        }
        return $candidates;
    }

    /**
     * Détail d'une entité Wikidata (wbgetentities) : label, description, uri,
     * et naissance/mort (best-effort, à partir des claims P569/P570).
     */
    public function getEntityDetails(string $qid, string $lang = 'fr'): array
    {
        $params = [
            'action' => 'wbgetentities',
            'ids' => $qid,
            'languages' => $lang . '|en',
            'props' => 'labels|descriptions|claims',
            'format' => 'json',
        ];
        $data = $this->wikidataRequest($params);
        $entity = $data['entities'][$qid] ?? [];

        $label = $entity['labels'][$lang]['value'] ?? ($entity['labels']['en']['value'] ?? $qid);
        $description = $entity['descriptions'][$lang]['value'] ?? ($entity['descriptions']['en']['value'] ?? '');

        return [
            'qid' => $qid,
            'label' => $label,
            'description' => $description,
            'uri' => 'http://www.wikidata.org/entity/' . $qid,
            'birth' => $this->extractDateClaim($entity, 'P569'),
            'death' => $this->extractDateClaim($entity, 'P570'),
        ];
    }

    /**
     * Extrait une date best-effort ("YYYY", "YYYY-MM" ou "YYYY-MM-DD" selon la
     * précision Wikidata) d'une propriété de type "time" (ex. P569/P570).
     */
    protected function extractDateClaim(array $entity, string $property): ?string
    {
        $value = $entity['claims'][$property][0]['mainsnak']['datavalue']['value'] ?? null;
        if (!$value || !isset($value['time'], $value['precision'])) {
            return null;
        }
        if ($value['precision'] < 9) {
            // Précision trop grossière (siècle, millénaire...) pour être utile ici.
            return null;
        }
        // Format Wikidata : "+1818-05-05T00:00:00Z" (le signe "+" précède aussi les dates BCE négatives).
        if (!preg_match('/^([+-]\d+)-(\d{2})-(\d{2})T/', $value['time'], $m)) {
            return null;
        }
        $year = ltrim($m[1], '+');
        if ($value['precision'] == 9) {
            return $year;
        }
        if ($value['precision'] == 10) {
            return $year . '-' . $m[2];
        }
        return $year . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * Retrouve un item existant via dcterms:isReferencedBy = uri Wikidata, ou
     * en crée un nouveau au gabarit "ref Personne". Retourne [item, created].
     */
    protected function findOrCreatePerson(array $entity): array
    {
        $isReferencedById = $this->cs->getProperty('dcterms:isReferencedBy')->id();
        $existing = $this->api->search('items', [
            'property' => [[
                'property' => $isReferencedById,
                'type' => 'eq',
                'text' => $entity['uri'],
            ]],
            'limit' => 1,
        ])->getContent();
        if ($existing) {
            return [reset($existing), false];
        }

        $rt = $this->cs->getResourceTemplate('ref Personne');
        $oItem = [];
        $oItem['o:resource_template'] = ['o:id' => $rt->id()];
        $oItem['o:resource_class'] = ['o:id' => $rt->resourceClass()->id()];
        $oItem['dcterms:title'][] = [
            'property_id' => $this->cs->getProperty('dcterms:title')->id(),
            '@value' => $entity['label'],
            'type' => 'literal',
        ];
        if ($entity['description'] !== '') {
            $oItem['dcterms:description'][] = [
                'property_id' => $this->cs->getProperty('dcterms:description')->id(),
                '@value' => $entity['description'],
                'type' => 'literal',
            ];
        }
        $oItem['dcterms:isReferencedBy'][] = [
            'property_id' => $isReferencedById,
            '@id' => $entity['uri'],
            'type' => 'uri',
        ];
        if (!empty($entity['birth'])) {
            $oItem['bio:birth'][] = [
                'property_id' => $this->cs->getProperty('bio:birth')->id(),
                '@value' => $entity['birth'],
                'type' => 'numeric:timestamp',
            ];
        }
        if (!empty($entity['death'])) {
            $oItem['bio:death'][] = [
                'property_id' => $this->cs->getProperty('bio:death')->id(),
                '@value' => $entity['death'],
                'type' => 'numeric:timestamp',
            ];
        }

        $person = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        return [$person, true];
    }

    /**
     * Appelle l'API Wikidata en GET et décode la réponse JSON.
     */
    protected function wikidataRequest(array $params): array
    {
        $url = self::WIKIDATA_API . '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['User-Agent: ChaoticumSeminario/1.0 (https://jardindesconnaissances.univ-paris8.fr)'],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Requête Wikidata impossible : ' . $error);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException('Requête Wikidata a échoué (HTTP ' . $httpCode . ').');
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Réponse Wikidata invalide.');
        }
        return $data;
    }
}
