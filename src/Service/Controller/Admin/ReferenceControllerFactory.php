<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\Controller\Admin;

use ChaoticumSeminario\Controller\Admin\ReferenceController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ReferenceControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ReferenceController(
            $services->get('Omeka\ApiManager'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('ViewHelperManager')->get('wikidataReference'),
            $services->get(\Omeka\Job\Dispatcher::class)
        );
    }
}
