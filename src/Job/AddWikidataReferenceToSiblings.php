<?php declare(strict_types=1);

namespace ChaoticumSeminario\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Ajoute une référence Wikidata (qid) à toutes les transcriptions sœurs d'un
 * item "Reference transcription" (jdc:surTout = oui). Lancé en tâche de fond
 * car chaque transcription implique un appel réseau à Wikidata (wbgetentities)
 * et une éventuelle création d'item "ref Personne".
 *
 * Traité par petits lots avec purge de l'EntityManager entre chaque lot
 * (comme Semafor.php/WhisperSpeechToText.php) : sans ça, Doctrine garde en
 * mémoire toutes les entités touchées (lecture/création/mise à jour d'items)
 * sur toute la durée du job, ce qui finit par épuiser la mémoire PHP dès que
 * la description de la référence correspond à beaucoup de transcriptions.
 */
class AddWikidataReferenceToSiblings extends AbstractJob
{
    /**
     * Chunk réduit par rapport aux autres jobs du module (100) : chaque
     * transcription implique ici un ou plusieurs appels réseau à Wikidata et
     * une création/mise à jour d'item, donc un coût mémoire par itération
     * bien plus élevé qu'un simple traitement de texte.
     *
     * @var int
     */
    const BULK_LIMIT = 10;

    public function perform(): void
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $referenceId = $this->getArg('referenceId');
        $qid = $this->getArg('qid');

        if (!$referenceId || !$qid) {
            $logger->err('Paramètres referenceId et qid requis.'); // @translate
            return;
        }

        /** @var \ChaoticumSeminario\View\Helper\WikidataReference $wikidataReference */
        $wikidataReference = $services->get('ViewHelperManager')->get('wikidataReference');

        try {
            $reference = $api->read('items', $referenceId)->getContent();
        } catch (\Throwable $e) {
            $logger->err(new Message(
                'Référence #%1$s introuvable : %2$s', // @translate
                $referenceId, $e->getMessage()
            ));
            return;
        }

        $description = (string) $reference->value('dcterms:description');
        $defaultTranscription = $wikidataReference->getDefaultTranscription($reference);
        $siblingIds = $wikidataReference->findSiblingTranscriptionIds(
            $description,
            $defaultTranscription ? $defaultTranscription->id() : null
        );

        $totalToProcess = count($siblingIds);
        $logger->info(new Message(
            'Référence #%1$s (qid %2$s) : %3$d transcription(s) sœur(s) à traiter.', // @translate
            $referenceId, $qid, $totalToProcess
        ));

        $totalProcessed = 0;
        foreach (array_chunk($siblingIds, self::BULK_LIMIT) as $idsChunk) {
            // Ré-hydrate des représentations fraîches à chaque lot : celles du
            // lot précédent ont été détachées par entityManager->clear().
            $reference = $api->read('items', $referenceId)->getContent();
            $siblings = $api->search('items', ['id' => $idsChunk])->getContent();

            foreach ($siblings as $sibling) {
                if ($this->shouldStop()) {
                    $logger->warn(new Message(
                        'Le job "%s" a été arrêté.', // @translate
                        'AddWikidataReferenceToSiblings'
                    ));
                    break 2;
                }

                $bilan = $wikidataReference([
                    'action' => 'run',
                    'reference' => $reference,
                    'transcription' => $sibling,
                    'qid' => $qid,
                ]);

                ++$totalProcessed;

                if (!empty($bilan['errors'])) {
                    $logger->warn(new Message(
                        'Transcription #%1$s : %2$s', // @translate
                        $sibling->id(), implode(' / ', $bilan['errors'])
                    ));
                } else {
                    $logger->info(new Message(
                        'Transcription #%1$s : référence ajoutée (personne #%2$s, %3$s).', // @translate
                        $sibling->id(),
                        $bilan['person']['id'] ?? '?',
                        !empty($bilan['person']['created']) ? 'créée' : 'déjà existante'
                    ));
                }

                unset($sibling, $bilan);
            }

            // Évite l'accumulation d'entités gérées par Doctrine sur la durée du job.
            unset($siblings, $reference);
            $entityManager->clear();
        }

        $logger->info(new Message(
            'Fin du job : %1$d/%2$d transcription(s) traitée(s).', // @translate
            $totalProcessed, $totalToProcess
        ));
    }
}
