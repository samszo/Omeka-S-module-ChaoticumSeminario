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

        return new ApiController($api, $chaoticumSeminarioSql,$acl);
    }
}
