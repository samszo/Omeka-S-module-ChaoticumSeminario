<?php declare(strict_types=1);

namespace ChaoticumSeminario\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Manager as ApiManager;

class CorrectionController extends AbstractActionController
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
     * @var \ChaoticumSeminario\View\Helper\TranscriptionCorrection
     */
    protected $transcriptionCorrection;

    public function __construct(ApiManager $api, $cs, $transcriptionCorrection)
    {
        $this->api = $api;
        $this->cs = $cs;
        $this->transcriptionCorrection = $transcriptionCorrection;
    }

    /**
     * GET /admin/correction
     * Liste des items "Correction transcription", triable par date et Status.
     */
    public function browseAction()
    {
        $rt = $this->cs->getResourceTemplate('Correction transcription');

        $sortBy = $this->params()->fromQuery('sort_by', 'created');
        if (!in_array($sortBy, ['created', 'modified', 'curation:status'], true)) {
            $sortBy = 'created';
        }
        $sortOrder = $this->params()->fromQuery('sort_order', 'desc');
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $query = $this->params()->fromQuery();
        $query['resource_template_id'] = $rt->id();
        $query['sort_by'] = $sortBy;
        $query['sort_order'] = $sortOrder;

        $response = $this->api->search('items', $query);
        $this->paginator($response->getTotalResults());

        $view = new ViewModel();
        $view->setVariable('corrections', $response->getContent());
        $view->setVariable('sortBy', $sortBy);
        $view->setVariable('sortOrder', $sortOrder);
        return $view;
    }

    /**
     * GET /admin/correction/:id
     * Détail d'une correction : aperçu des correspondances + lancement.
     */
    public function showAction()
    {
        $correction = $this->api->read('items', $this->params('id'))->getContent();
        $preview = ($this->transcriptionCorrection)(['action' => 'preview', 'correction' => $correction]);

        $view = new ViewModel();
        $view->setVariable('correction', $correction);
        $view->setVariable('preview', $preview);
        return $view;
    }

    /**
     * POST /admin/correction/:id/run
     * Exécute la correction et affiche le bilan.
     */
    public function runAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/correction/id', ['id' => $this->params('id'), 'action' => 'show']);
        }

        set_time_limit(120);

        $correction = $this->api->read('items', $this->params('id'))->getContent();

        $transcriptionId = $this->params()->fromPost('transcription_id');
        if ($transcriptionId) {
            $transcription = $this->api->read('items', $transcriptionId)->getContent();
        } else {
            $transcription = $this->transcriptionCorrection->getDefaultTranscription($correction);
        }

        $view = new ViewModel();
        $view->setVariable('correction', $correction);

        if (!$transcription) {
            $view->setVariable('bilan', null);
            $view->setVariable('error', 'Aucune transcription cible trouvée pour cette correction.'); // @translate
            return $view;
        }

        $bilan = ($this->transcriptionCorrection)(['action' => 'run', 'correction' => $correction, 'transcription' => $transcription]);
        $view->setVariable('bilan', $bilan);
        return $view;
    }

    /**
     * POST /admin/correction/:id/run-all
     * Exécute la correction sur toutes les transcriptions sœurs (jdc:surTout = oui).
     */
    public function runAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/correction/id', ['id' => $this->params('id'), 'action' => 'show']);
        }

        set_time_limit(300);

        $correction = $this->api->read('items', $this->params('id'))->getContent();
        $defaultTranscription = $this->transcriptionCorrection->getDefaultTranscription($correction);
        $siblings = $this->transcriptionCorrection->findSiblingTranscriptions(
            $correction,
            $defaultTranscription ? $defaultTranscription->id() : null
        );

        $bilans = [];
        foreach ($siblings as $sibling) {
            $bilans[] = ($this->transcriptionCorrection)(['action' => 'run', 'correction' => $correction, 'transcription' => $sibling]);
        }

        $view = new ViewModel();
        $view->setVariable('correction', $correction);
        $view->setVariable('bilans', $bilans);
        return $view;
    }
}
