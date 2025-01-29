<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\AnythingLLM;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AnythingLLMFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AnythingLLM(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Logger'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('Config'),
            $services->get('ViewHelperManager')->get('chaoticumSeminarioSql'),
            $services->get('ViewHelperManager')->get('anythingLLMCredentials'),
            $services->get('Omeka\HttpClient')

        );
    }
}
