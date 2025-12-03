<?php declare(strict_types=1);

namespace ChaoticumSeminario;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use ChaoticumSeminario\Form\BatchEditFieldset;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Settings\SettingsInterface;
use ChaoticumSeminario\Form\Element\BatchEditSemafor;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        require_once __DIR__ . '/vendor/autoload.php';
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if ($module && version_compare($module->getIni('version') ?? '', '3.4.41', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Generic', '3.4.41'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
        $module = $services->get('Omeka\ModuleManager')->getModule('Annotate');
        if (!$module) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Annotate', '3.4.3.8'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }


    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );

        /*
        // Extend the batch edit form via js.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            '*',
            'view.batch_edit.before',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'formAddElementsResourceBatchUpdateForm']
        );
        */
        $sharedEventManager->attach(
            'Omeka\Form\ResourceBatchUpdateForm',
            'form.add_elements',
            function (Event $event) {
                $form = $event->getTarget();
                $form->add([
                    'type' => BatchEditSemafor::class,
                    'name' => 'BatchEditSemafor',
                ]);
                $this->formAddElementsResourceBatchUpdateForm($event);
            }
        );
        /*
        $eventIds = [
            'Omeka\Api\Adapter\ItemAdapter',
            'Omeka\Api\Adapter\ItemSetAdapter',
            'Omeka\Api\Adapter\MediaAdapter',
        ];
        foreach ($eventIds as $eventId) {
            $sharedEventManager->attach(
                $eventId,
                'api.preprocess_batch_update',
                function (Event $event) {
                    $data = $event->getParam('data');
                    $rawData = $event->getParam('request')->getContent();
                    $event->setParam('data', $data);
                }
            );
        }
        */


        // Ajout du paramètre utilisateur
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'handleUserSettings']
        );
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/chaoticum-seminario-admin.css', 'ChaoticumSeminario'));
        /*
        $view->headScript()
            ->appendFile($assetUrl('js/chaoticum-seminario-admin.js', 'ChaoticumSeminario'), 'text/javascript', ['defer' => 'defer']);
        */
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        // $resourceType = $form->getOption('resource_type');

        /** @var \ChaoticumSeminario\\Form\BatchEditFieldset $fieldset */
        $fieldset = $formElementManager->get(BatchEditFieldset::class);

        /*Uniquement si on demande une conversion google
        Vérification des droits en amont.
        $googleSpeechToTextCredentials = $services->get('ViewHelperManager')->get('googleSpeechToTextCredentials');
        if (!$googleSpeechToTextCredentials()) {
            $fieldset->get('chaoticumseminario_google_speech_to_text')
                ->setLabel('Convert speech to text via Google (disabled: credentials not set)') // @translate
                ->setAttribute('disabled', 'disabled')
                ->setAttribute('value', 0);
        }
        */

        $form->add($fieldset);
    }

    /**
     * Vérifie puis lance la tâche de traitement chaotique texte.
     */
    public function handleResourceBatchUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        if (empty($data['chaoticum_seminario']['chaoticumseminario_google_speech_to_text'])
            && empty($data['chaoticum_seminario']['chaoticumseminario_whisper_speech_to_text'])
            && empty($data['chaoticum_seminario']['chaoticumseminario_transformer_token_classification'])
            && empty($data['chaoticum_seminario']['chaoticumseminario_anythingllm_addDoc'])
            && empty($data['chaoticum_seminario']['chaoticumseminario_pdfToMarkdown']) 
            && empty($data['BatchEditSemafor'])  
        ) {
            return;
        }

        $ids = (array) $request->getIds();
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return;
        }

        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $url = $plugins->get('url');
        $messenger = $plugins->get('messenger');
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $params = [
            'ids' => $ids,
            'idFirst' => $ids[0],
            'idLast' => $ids[count($ids)-1],
        ];


        if(!empty($data['chaoticum_seminario']['chaoticumseminario_google_speech_to_text'])){
            $params['service']='Google';
            $this->createJob(\ChaoticumSeminario\Job\GoogleSpeechToText::class, $params, $url, $dispatcher, $messenger);                
        }

        if(!empty($data['chaoticum_seminario']['chaoticumseminario_whisper_speech_to_text'])){
            $params['service']='Whisper';
            $this->createJob(\ChaoticumSeminario\Job\WhisperSpeechToText::class, $params, $url, $dispatcher, $messenger);                
        }

        if(!empty($data['chaoticum_seminario']['chaoticumseminario_transformer_token_classification'])){
            $params['service']='Transformers';
            $params['pipeline']='tokenClassification';
            $this->createJob(\ChaoticumSeminario\Job\TransformersPipeline::class, $params, $url, $dispatcher, $messenger);                
        }

        if(!empty($data['chaoticum_seminario']['chaoticumseminario_anythingllm_addDoc'])
            && $data['chaoticum_seminario']['chaoticumseminario_anythingllm_addDoc']!='no'){
            $params['service']='anythingllm';
            $params['pipeline']='addDoc';
            $params['chunk']=$data['chaoticum_seminario']['chaoticumseminario_anythingllm_addDoc'];
            $this->createJob(\ChaoticumSeminario\Job\AnythinLLM::class, $params, $url, $dispatcher, $messenger);                
        }

        if(!empty($data['chaoticum_seminario']['chaoticumseminario_pdfToMarkdown'])
            && $data['chaoticum_seminario']['chaoticumseminario_pdfToMarkdown']!='no'){
            $params['pipeline']='pdfToMarkdown';
            $params['service']=$data['chaoticum_seminario']['chaoticumseminario_pdfToMarkdown'];
            $this->createJob(\ChaoticumSeminario\Job\PdfToMarkdown::class, $params, $url, $dispatcher, $messenger);                
        }

        if(!empty($data['BatchEditSemafor'])
            && $data['BatchEditSemafor']['property']!='' && $data['BatchEditSemafor']['type']!=''){
            $params['pipeline']='addCompetences';
            $params['type']=$data['BatchEditSemafor']['type'];
            $params['scope']=$data['BatchEditSemafor']['property'];
            $this->createJob(\ChaoticumSeminario\Job\Semafor::class, $params, $url, $dispatcher, $messenger);                
        }

   }

    function createJob($jobName, $params, $url, $dispatcher, $messenger): void
    {
        $action = isset($params['pipeline']) ? $params['pipeline'] : "speech to text";
        $idFirstLast = isset($params['idFirst']) ? ' ids='.$params['idFirst'].' -> '.$params['idLast'] : "";
        $job = $dispatcher->dispatch($jobName, $params);
        $message = new \Omeka\Stdlib\Message(
            'Extracting '.$action.' via a '.$params['service'].' derivated background job='.$job->getId()
            . $idFirstLast
        );
        /*TODO: corriger erreur url
        $message = new \Omeka\Stdlib\Message(
            'Extracting '.$action.' via a '.$params['service'].' derivated background job (%1$sjob #%2$d%3$s, %4$slogs%3$s)', // @translate,
            sprintf('<a href="%s">',
                htmlspecialchars($url->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', $this->isModuleActive('Log') ? $url->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $url->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
        );
        */
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    /**
     * Empèche les utilisateurs de voir le compte Google d'un autre utilisateur,
     * y compris l'admin.
     */
    public function handleUserSettings(Event $event): void
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Mvc\Status $status */
        $status = $services->get('Omeka\Status');
        if ($status->isAdminRequest()) {
            /** @var \Laminas\Router\Http\RouteMatch $routeMatch */
            $routeMatch = $status->getRouteMatch();
            if (!in_array($routeMatch->getParam('controller'), ['Omeka\Controller\Admin\User', 'user'])) {
                return;
            }
            $managedUserId = (int) $routeMatch->getParam('id');
            $userId = (int) $services->get('Omeka\AuthenticationService')->getIdentity()->getId();
            if ($managedUserId === $userId) {
                $this->handleAnySettings($event, 'user_settings');
            }
        }
    }
}
