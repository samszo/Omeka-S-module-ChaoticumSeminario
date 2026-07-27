<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Applique une correction (item "Correction transcription") à une transcription :
 * remplace un texte dans le titre, dans les segments ("part of speech") et dans
 * les concepts-mots ("a comme concept"), en traçant chaque annotation modifiée
 * via oa:motivatedBy.
 *
 * Les "value annotation" (le "@annotation" niché sous lexinfo:segmentation et
 * curation:data) ne sont pas modifiables via l'API dédiée ('value_annotations' :
 * @see \Omeka\Api\Adapter\ValueAnnotationAdapter délègue à AbstractAdapter qui
 * lève OperationNotImplementedException). La seule façon de les modifier est de
 * réécrire l'item Transcription en entier (même pattern que
 * ChaoticumSeminarioSql::corrections() et ChaoticumSeminario::ajouteMediaFrag()).
 */
class TranscriptionCorrection extends AbstractHelper
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var ChaoticumSeminario
     */
    protected $cs;

    /**
     * @var ChaoticumSeminarioSql
     */
    protected $sql;

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct(ApiManager $api, ChaoticumSeminario $cs, ChaoticumSeminarioSql $sql, Logger $logger)
    {
        $this->api = $api;
        $this->cs = $cs;
        $this->sql = $sql;
        $this->logger = $logger;
    }

    public function __invoke(array $params = [])
    {
        switch ($params['action'] ?? null) {
            case 'preview':
                return $this->preview($params['correction']);
            case 'run':
                return $this->run($params['correction'], $params['transcription']);
            case 'siblings':
                return $this->findSiblingTranscriptions($params['correction'], $params['excludeId'] ?? null);
            default:
                return null;
        }
    }

    /**
     * Transcription ciblée par défaut par une correction (dcterms:source).
     */
    public function getDefaultTranscription(ItemRepresentation $correction): ?ItemRepresentation
    {
        $source = $correction->value('dcterms:source');
        if (!$source) {
            return null;
        }
        $resource = $source->valueResource();
        return $resource instanceof ItemRepresentation ? $resource : null;
    }

    /**
     * Aperçu en lecture seule : compte les correspondances potentielles sans rien modifier.
     */
    public function preview(ItemRepresentation $correction): array
    {
        $remplacer = (string) $correction->value('jdc:remplacer');
        $par = (string) $correction->value('jdc:par');
        $surTout = mb_strtolower((string) $correction->value('jdc:surTout')) === 'oui';
        $transcription = $this->getDefaultTranscription($correction);

        $result = [
            'remplacer' => $remplacer,
            'par' => $par,
            'surTout' => $surTout,
            'transcription' => $transcription,
            'titleMatches' => 0,
            'segmentationMatches' => 0,
            'dataMatches' => 0,
            'siblings' => [],
        ];

        if (!$transcription || $remplacer === '') {
            return $result;
        }

        $result['titleMatches'] = $this->countMatches((string) $transcription->value('dcterms:title'), $remplacer);

        foreach ($transcription->value('lexinfo:segmentation', ['all' => true]) as $seg) {
            $anno = $seg->valueAnnotation();
            if (!$anno) {
                continue;
            }
            $result['segmentationMatches'] += $this->countMatches((string) $anno->value('lexinfo:partOfSpeech'), $remplacer);
        }

        $needleWords = $this->tokenizeWords($remplacer);
        if ($needleWords) {
            $needleWordsLower = array_map('mb_strtolower', $needleWords);
            $labels = [];
            foreach ($transcription->value('curation:data', ['all' => true]) as $d) {
                $anno = $d->valueAnnotation();
                $concept = $anno ? $anno->value('jdc:hasConcept') : null;
                $labels[] = $concept ? $concept->valueResource()->displayTitle() : null;
            }
            $result['dataMatches'] = count($this->findRuns($labels, $needleWordsLower));
        }

        if ($surTout && $remplacer !== '') {
            $result['siblings'] = $this->findSiblingTranscriptions($correction, $transcription->id());
        }

        return $result;
    }

    /**
     * Exécute la correction sur la transcription donnée et retourne le bilan.
     */
    public function run(ItemRepresentation $correction, ItemRepresentation $transcription): array
    {
        $remplacer = (string) $correction->value('jdc:remplacer');
        $par = (string) $correction->value('jdc:par');

        $bilan = [
            'correction' => ['id' => $correction->id(), 'title' => $correction->displayTitle()],
            'transcription' => ['id' => $transcription->id(), 'title_before' => (string) $transcription->value('dcterms:title')],
            'remplacer' => $remplacer,
            'par' => $par,
            'title' => ['changed' => false, 'before' => null, 'after' => null],
            'segmentation' => [],
            'data' => [],
            'errors' => [],
            'status_updated' => false,
            'annexe_updated' => false,
        ];

        if ($remplacer === '') {
            $bilan['errors'][] = 'La propriété "remplacer" de la correction est vide, aucune action possible.'; // @translate
            return $bilan;
        }

        try {
            $dataItem = json_decode(json_encode($transcription->getJsonLd()), true);
            $changed = false;

            // Titre.
            $titleValue = $dataItem['dcterms:title'][0]['@value'] ?? '';
            [$count, $newTitle] = $this->ciReplace($titleValue, $remplacer, $par);
            if ($count > 0) {
                $dataItem['dcterms:title'][0]['@value'] = $newTitle;
                $bilan['title'] = ['changed' => true, 'before' => $titleValue, 'after' => $newTitle];
                $changed = true;
            }

            // Segmentation ("part of speech").
            if (!empty($dataItem['lexinfo:segmentation'])) {
                foreach ($dataItem['lexinfo:segmentation'] as &$seg) {
                    $posValue = $seg['@annotation']['lexinfo:partOfSpeech'][0]['@value'] ?? null;
                    if ($posValue === null) {
                        continue;
                    }
                    [$count, $newPos] = $this->ciReplace($posValue, $remplacer, $par);
                    if ($count > 0) {
                        $seg['@annotation']['lexinfo:partOfSpeech'][0]['@value'] = $newPos;
                        $this->appendMotivatedBy($seg['@annotation'], $correction->id());
                        $bilan['segmentation'][] = [
                            'start' => $seg['@annotation']['oa:start'][0]['@value'] ?? null,
                            'end' => $seg['@annotation']['oa:end'][0]['@value'] ?? null,
                            'before' => $posValue,
                            'after' => $newPos,
                        ];
                        $changed = true;
                    }
                }
                unset($seg);
            }

            // Data / concepts-mots ("a comme concept").
            if ($this->applyDataCorrection($dataItem, $correction, $remplacer, $par, $bilan)) {
                $changed = true;
            }

            if ($changed) {
                $this->api->update('items', $transcription->id(), $dataItem, [], [
                    'isPartial' => true,
                    'collectionAction' => 'replace',
                    'continueOnError' => true,
                ]);

                // Rafraîchit les annexes SQL (transcriptions, timeline_concept) de
                // cette seule transcription, sans reconstruire toute la base (cf.
                // data/scripts/updateTranscriptionAnnexe.sql). Une erreur ici ne doit
                // pas faire perdre la correction déjà enregistrée : elle est juste
                // consignée dans le bilan.
                try {
                    ($this->sql)(['action' => 'updateTranscriptionAnnexe', 'idTrans' => $transcription->id()]);
                    $bilan['annexe_updated'] = true;
                } catch (\Throwable $e) {
                    $bilan['errors'][] = 'Erreur lors de la mise à jour des annexes : ' . $e->getMessage(); // @translate
                }

                // Attention : une mise à jour partielle avec collectionAction=replace
                // qui ne soumettrait que 'curation:status' effacerait toutes les
                // autres valeurs de l'item (ValueHydrator réutilise/supprime les
                // Value existantes globalement, pas par terme de propriété). Il faut
                // donc toujours resoumettre le JSON-LD complet de l'item, comme pour
                // la transcription ci-dessus.
                $correctionData = json_decode(json_encode($correction->getJsonLd()), true);
                $correctionData['curation:status'] = [[
                    'property_id' => $this->cs->getProperty('curation:status')->id(),
                    '@value' => 'Fait',
                    'type' => 'literal',
                ]];
                $this->api->update('items', $correction->id(), $correctionData, [], [
                    'isPartial' => true,
                    'collectionAction' => 'replace',
                    'continueOnError' => true,
                ]);
                $bilan['status_updated'] = true;
            }
        } catch (\Throwable $e) {
            $bilan['errors'][] = 'Erreur lors de la correction : ' . $e->getMessage(); // @translate
        }

        return $bilan;
    }

    /**
     * Modifie $dataItem['curation:data'] en place (remplacement des concepts-mots).
     * Retourne true si au moins une suite a été modifiée.
     */
    protected function applyDataCorrection(array &$dataItem, ItemRepresentation $correction, string $remplacer, string $par, array &$bilan): bool
    {
        $needleWords = $this->tokenizeWords($remplacer);
        if (!$needleWords || empty($dataItem['curation:data'])) {
            return false;
        }

        $needleWordsLower = array_map('mb_strtolower', $needleWords);
        $labels = array_map(
            function ($d) {
                return $d['@annotation']['jdc:hasConcept'][0]['display_title'] ?? null;
            },
            $dataItem['curation:data']
        );
        $runs = $this->findRuns($labels, $needleWordsLower);
        if (!$runs) {
            return false;
        }

        $parWords = $this->tokenizeWords($par);
        $parConceptIds = [];
        foreach ($parWords as $w) {
            $concept = $this->resolveConceptId($w, true);
            if ($concept === null) {
                $bilan['errors'][] = 'Impossible de résoudre ou créer le concept pour le mot "' . $w . '".'; // @translate
            }
            $parConceptIds[] = $concept['id'] ?? null;
        }

        $n = count($needleWords);
        $m = count($parWords);
        $conceptPropId = $this->cs->getProperty('jdc:hasConcept')->id();

        // Traite les suites en partant de la fin pour ne pas invalider les index
        // des suites précédentes lors des insertions/suppressions.
        foreach (array_reverse($runs) as $start) {
            $before = array_slice($labels, $start, $n);

            for ($k = 0; $k < min($n, $m); $k++) {
                if ($parConceptIds[$k] === null) {
                    continue;
                }
                $entryRef = &$dataItem['curation:data'][$start + $k];
                $entryRef['@annotation']['jdc:hasConcept'] = [[
                    'property_id' => $conceptPropId,
                    'value_resource_id' => $parConceptIds[$k],
                    'type' => 'resource',
                ]];
                $entryRef['@value'] = $this->renameDataLabel($entryRef['@value'] ?? '', $parConceptIds[$k], $parWords[$k]);
                $this->appendMotivatedBy($entryRef['@annotation'], $correction->id());
                unset($entryRef);
            }

            $approximated = false;
            if ($m < $n) {
                array_splice($dataItem['curation:data'], $start + $m, $n - $m);
            } elseif ($m > $n) {
                $template = $dataItem['curation:data'][$start + $n - 1];
                $newEntries = [];
                for ($k = $n; $k < $m; $k++) {
                    if ($parConceptIds[$k] === null) {
                        continue;
                    }
                    $entry = $template;
                    $entry['@annotation']['jdc:hasConcept'] = [[
                        'property_id' => $conceptPropId,
                        'value_resource_id' => $parConceptIds[$k],
                        'type' => 'resource',
                    ]];
                    $entry['@value'] = $this->renameDataLabel($entry['@value'] ?? '', $parConceptIds[$k], $parWords[$k]);
                    $this->appendMotivatedBy($entry['@annotation'], $correction->id());
                    $newEntries[] = $entry;
                }
                array_splice($dataItem['curation:data'], $start + $n, 0, $newEntries);
                $approximated = true;
            }

            $bilan['data'][] = [
                'before_words' => $before,
                'after_words' => array_slice($parWords, 0, $m),
                'approximated_timing' => $approximated,
            ];
        }

        return true;
    }

    /**
     * Cherche d'autres transcriptions dont le titre contient le texte "remplacer".
     */
    public function findSiblingTranscriptions(ItemRepresentation $correction, ?int $excludeId = null): array
    {
        $remplacer = (string) $correction->value('jdc:remplacer');
        if ($remplacer === '') {
            return [];
        }
        $query = [
            'resource_class_id' => $this->cs->getResourceClass('jdc:Transcription')->id(),
            'property' => [[
                'property' => $this->cs->getProperty('dcterms:title')->id(),
                'type' => 'in',
                'text' => $remplacer,
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
     * Reconstruit le libellé littéral d'une entrée curation:data (ex.
     * "m1915193/27.6/315927 [chaosMedia-...] (querella)") avec le nouveau
     * concept, en conservant le préfixe média/timecode inchangé.
     */
    protected function renameDataLabel(string $value, int $newConceptId, string $newWord): string
    {
        if (preg_match('/^(.*\/)\d+(\s*\[[^\]]*\])/u', $value, $m)) {
            return $m[1] . $newConceptId . $m[2] . ' (' . $newWord . ')';
        }
        return $value;
    }

    /**
     * Ajoute (sans écraser) une valeur oa:motivatedBy pointant vers la correction.
     */
    protected function appendMotivatedBy(array &$annotation, int $correctionId): void
    {
        $annotation['oa:motivatedBy'][] = [
            'property_id' => $this->cs->getProperty('oa:motivatedBy')->id(),
            'value_resource_id' => $correctionId,
            'type' => 'resource',
        ];
    }

    /**
     * Remplacement de texte insensible à la casse. Retourne [nombre de remplacements, nouveau texte].
     */
    protected function ciReplace(string $haystack, string $needle, string $replacement): array
    {
        if ($needle === '' || $haystack === '') {
            return [0, $haystack];
        }
        $new = preg_replace('/' . preg_quote($needle, '/') . '/ui', $replacement, $haystack, -1, $count);
        return [$count, $new ?? $haystack];
    }

    protected function countMatches(string $haystack, string $needle): int
    {
        if ($needle === '' || $haystack === '') {
            return 0;
        }
        return preg_match_all('/' . preg_quote($needle, '/') . '/ui', $haystack);
    }

    /**
     * Découpe un texte en mots, en reprenant la normalisation utilisée à la
     * création des concepts (WhisperSpeechToText::getTag()) : on ne garde que
     * ce qui suit la première apostrophe (élision : l', d', qu', j'...).
     */
    protected function tokenizeWords(string $text): array
    {
        $raw = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$raw) {
            return [];
        }
        return array_map(function ($w) {
            $pos = strpos($w, "'");
            return $pos === false ? $w : substr($w, $pos + 1);
        }, $raw);
    }

    /**
     * Recherche toutes les suites non chevauchantes de $labels correspondant à
     * $needleWordsLower (comparaison insensible à la casse). Retourne la liste
     * des index de départ.
     */
    protected function findRuns(array $labels, array $needleWordsLower): array
    {
        $n = count($needleWordsLower);
        if ($n === 0) {
            return [];
        }
        $runs = [];
        $count = count($labels);
        $i = 0;
        while ($i <= $count - $n) {
            $match = true;
            for ($k = 0; $k < $n; $k++) {
                $label = $labels[$i + $k];
                if ($label === null || mb_strtolower($label) !== $needleWordsLower[$k]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $runs[] = $i;
                $i += $n;
            } else {
                $i++;
            }
        }
        return $runs;
    }

    /**
     * Recherche (et éventuellement crée) le concept-mot correspondant à $word,
     * en réutilisant la table annexe déjà utilisée par WhisperSpeechToText::getTag().
     */
    protected function resolveConceptId(string $word, bool $createIfMissing): ?array
    {
        $rows = ($this->sql)(['action' => 'getConcept', 'label' => $word]);
        if ($rows) {
            return $rows[0];
        }
        if (!$createIfMissing) {
            return null;
        }

        $oItem = [];
        $oItem['o:resource_class'] = ['o:id' => $this->cs->getResourceClass('skos:Concept')->id()];
        $oItem['dcterms:title'][] = [
            'property_id' => $this->cs->getProperty('dcterms:title')->id(),
            '@value' => $word,
            'type' => 'literal',
        ];
        $oItem['skos:prefLabel'][] = [
            'property_id' => $this->cs->getProperty('skos:prefLabel')->id(),
            '@value' => $word,
            'type' => 'literal',
        ];
        $cpt = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        if (!$cpt) {
            return null;
        }
        ($this->sql)(['action' => 'addConcept', 'id' => $cpt->id(), 'label' => $word]);
        return ['id' => $cpt->id(), 'label' => $word];
    }
}
