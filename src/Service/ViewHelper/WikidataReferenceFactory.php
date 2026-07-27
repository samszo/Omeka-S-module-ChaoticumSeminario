<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\WikidataReference;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class WikidataReferenceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new WikidataReference(
            $services->get('Omeka\ApiManager'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('Omeka\Logger')
        );
    }
}
