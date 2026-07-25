<?php
namespace ChaoticumSeminario\Service\Controller\Site;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ChaoticumSeminario\Controller\Site\ApiController;

class ApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $chaoticumSeminarioSql = $services->get('ViewHelperManager')->get('chaoticumSeminarioSql');
        $acl = $services->get('Omeka\Acl');
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $cs = $services->get('ViewHelperManager')->get('chaoticumSeminario');
        return new ApiController($api, $chaoticumSeminarioSql, $acl, $dispatcher, $cs);
    }
}
