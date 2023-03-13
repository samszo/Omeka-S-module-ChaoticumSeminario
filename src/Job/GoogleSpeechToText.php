<?php declare(strict_types=1);

namespace ChaoticumSeminario\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class GoogleSpeechToText extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BULK_LIMIT = 100;

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

        $ids = $this->getArg('ids');
        if (!$ids) {
            $logger->warn(
                'No item set to extract speech to text.' // @translate
            );
            return;
        }

        // Check existence and rights.
        $itemIds = $api->search('items', ['id' => $ids], ['returnScalar' => 'id'])->getContent();
        if (count($itemIds) < count($ids)) {
            $logger->warn(new Message(
                'These items are not available: #%s', // @translate
                implode(', #', array_diff($ids, $itemIds))
            ));
        }

        if (!$itemIds) {
            return;
        }

        $totalToProcess = count($itemIds);

        $logger->info(new Message(
            'Processing %d resources.', // @translate
            $totalToProcess
        ));

        $googleSpeechToText = $services->get('ViewHelperManager')->get('googleSpeechToText');

        $totalProcessed = 0;
        foreach (array_chunk($itemIds, self::BULK_LIMIT) as $listItemIds) {
            /** @var \Omeka\Api\Representation\AbstractRepresentation[] $resources */
            $resources = $api
                ->search('items', [
                    'id' => $listItemIds,
                ])
                ->getContent();
            if (empty($resources)) {
                continue;
            }

            foreach ($resources as $resource) {
                if ($this->shouldStop()) {
                    $logger->warn(new Message(
                        'The job "%s" was stopped.', // @translate
                        'Google Speech to Text'
                    ));
                    break 2;
                }

                $result = $googleSpeechToText([
                    'service' => 'speechToText',
                    'frag' => $resource,
                ]);

                ++$totalProcessed;

                // Avoid memory issue.
                unset($resource);
            }

            // Avoid memory issue.
            unset($resources);
            $entityManager->clear();
        }

        $logger->info(new Message(
            'End of the job: %1$d/%2$d processed.', // @translate
            $totalProcessed, $totalToProcess
        ));
    }
}
