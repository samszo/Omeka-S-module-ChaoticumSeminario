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

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );

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
        $form->add($fieldset);
    }

    /**
     * Vérifie puis lance la tâche d'extraction du texte.
     */
    public function handleResourceBatchUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        if (empty($data['chaoticum_seminario']['chaoticumseminario_google_speech_to_text'])) {
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

        $params = [
            'ids' => $ids,
        ];

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\ChaoticumSeminario\Job\GoogleSpeechToText::class, $params);

        $message = new \Omeka\Stdlib\Message(
            'Extracting speech to text via a derivated background job (%1$sjob #%2$d%3$s, %4$slogs%3$s)', // @translate,
            sprintf('<a href="%s">',
                htmlspecialchars($url->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', $this->isModuleActive('Log') ? $url->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $url->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
        );
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
