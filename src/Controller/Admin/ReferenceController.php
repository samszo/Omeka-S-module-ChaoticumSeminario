<?php declare(strict_types=1);

namespace ChaoticumSeminario\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Manager as ApiManager;
use Omeka\Job\Dispatcher;
use Omeka\Stdlib\Message;

class ReferenceController extends AbstractActionController
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var \ChaoticumSeminario\View\Helper\ChaoticumSeminario
     */
    protected $cs;

    /**
     * @var \ChaoticumSeminario\View\Helper\WikidataReference
     */
    protected $wikidataReference;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    public function __construct(ApiManager $api, $cs, $wikidataReference, Dispatcher $dispatcher)
    {
        $this->api = $api;
        $this->cs = $cs;
        $this->wikidataReference = $wikidataReference;
        $this->dispatcher = $dispatcher;
    }

    /**
     * GET /admin/reference
     * Liste des items "Reference transcription", triable par date/Status et
     * filtrable par Status.
     */
    public function browseAction()
    {
        $rt = $this->cs->getResourceTemplate('Reference transcription');

        $sortBy = $this->params()->fromQuery('sort_by', 'created');
        if (!in_array($sortBy, ['created', 'modified', 'curation:status'], true)) {
            $sortBy = 'created';
        }
        $sortOrder = $this->params()->fromQuery('sort_order', 'desc');
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }
        $statusFilter = trim((string) $this->params()->fromQuery('status_filter', ''));

        $statuses = $this->api->search('items', [
            'resource_template_id' => $rt->id(),
        ])->getContent();
        $statusOptions = [];
        foreach ($statuses as $item) {
            $s = (string) $item->value('curation:status');
            if ($s !== '') {
                $statusOptions[$s] = $s;
            }
        }
        ksort($statusOptions);

        $query = $this->params()->fromQuery();
        $query['resource_template_id'] = $rt->id();
        $query['sort_by'] = $sortBy;
        $query['sort_order'] = $sortOrder;
        if ($statusFilter !== '') {
            $query['property'] = [[
                'property' => $this->cs->getProperty('curation:status')->id(),
                'type' => 'eq',
                'text' => $statusFilter,
            ]];
        }

        $response = $this->api->search('items', $query);
        $this->paginator($response->getTotalResults());

        $view = new ViewModel();
        $view->setVariable('references', $response->getContent());
        $view->setVariable('sortBy', $sortBy);
        $view->setVariable('sortOrder', $sortOrder);
        $view->setVariable('statusFilter', $statusFilter);
        $view->setVariable('statusOptions', $statusOptions);
        return $view;
    }

    /**
     * GET /admin/reference/:id
     * Détail d'une référence : recherche Wikidata automatique + lancement.
     */
    public function showAction()
    {
        $reference = $this->api->read('items', $this->params('id'))->getContent();
        $query = $this->params()->fromQuery('q');
        $preview = ($this->wikidataReference)(['action' => 'preview', 'reference' => $reference, 'query' => $query]);

        $view = new ViewModel();
        $view->setVariable('reference', $reference);
        $view->setVariable('preview', $preview);
        return $view;
    }

    /**
     * POST /admin/reference/:id/run
     * Ajoute la référence choisie (qid) à la transcription et affiche le bilan.
     */
    public function runAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/reference/id', ['id' => $this->params('id'), 'action' => 'show']);
        }

        set_time_limit(60);

        $reference = $this->api->read('items', $this->params('id'))->getContent();
        $qid = $this->params()->fromPost('qid');

        $transcriptionId = $this->params()->fromPost('transcription_id');
        if ($transcriptionId) {
            $transcription = $this->api->read('items', $transcriptionId)->getContent();
        } else {
            $transcription = $this->wikidataReference->getDefaultTranscription($reference);
        }

        $view = new ViewModel();
        $view->setVariable('reference', $reference);

        if (!$qid) {
            $view->setVariable('bilan', null);
            $view->setVariable('error', 'Paramètre qid manquant.'); // @translate
            return $view;
        }
        if (!$transcription) {
            $view->setVariable('bilan', null);
            $view->setVariable('error', 'Aucune transcription cible trouvée pour cette référence.'); // @translate
            return $view;
        }

        $bilan = ($this->wikidataReference)(['action' => 'run', 'reference' => $reference, 'transcription' => $transcription, 'qid' => $qid]);
        $view->setVariable('bilan', $bilan);
        return $view;
    }

    /**
     * POST /admin/reference/:id/run-all
     * Lance un job de fond qui ajoute la référence choisie (qid) sur toutes
     * les transcriptions sœurs (jdc:surTout = oui) : chaque transcription
     * implique un appel réseau à Wikidata, ce qui serait trop long pour une
     * requête HTTP synchrone dès qu'il y a plusieurs sœurs.
     */
    public function runAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/reference/id', ['id' => $this->params('id'), 'action' => 'show']);
        }

        $reference = $this->api->read('items', $this->params('id'))->getContent();
        $qid = $this->params()->fromPost('qid');

        if (!$qid) {
            $this->messenger()->addError('Paramètre qid manquant.'); // @translate
            return $this->redirect()->toRoute('admin/reference/id', ['id' => $reference->id(), 'action' => 'show']);
        }

        $job = $this->dispatcher->dispatch(\ChaoticumSeminario\Job\AddWikidataReferenceToSiblings::class, [
            'referenceId' => $reference->id(),
            'qid' => $qid,
        ]);

        $message = new Message(
            'Ajout de la référence sur les transcriptions sœurs lancé en tâche de fond (%1$sjob #%2$d%3$s).', // @translate
            sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
            $job->getId(),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/reference/id', ['id' => $reference->id(), 'action' => 'show']);
    }
}
