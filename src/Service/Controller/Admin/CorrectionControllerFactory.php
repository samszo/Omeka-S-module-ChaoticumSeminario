<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\Controller\Admin;

use ChaoticumSeminario\Controller\Admin\CorrectionController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CorrectionControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new CorrectionController(
            $services->get('Omeka\ApiManager'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('ViewHelperManager')->get('transcriptionCorrection')
        );
    }
}
