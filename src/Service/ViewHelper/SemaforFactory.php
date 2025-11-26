<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\Semafor;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SemaforFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Semafor(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Logger'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('Config'),
            $services->get('ViewHelperManager')->get('chaoticumSeminarioSql'),
            $services->get('ViewHelperManager')->get('semaforCredentials'),
            $services->get('Omeka\HttpClient')

        );
    }
}
