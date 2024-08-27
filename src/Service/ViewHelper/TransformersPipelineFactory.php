<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\TransformersPipeline;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TransformersPipelineFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new TransformersPipeline(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Logger'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('Config')
        );
    }
}
