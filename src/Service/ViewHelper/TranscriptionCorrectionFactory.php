<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\TranscriptionCorrection;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TranscriptionCorrectionFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new TranscriptionCorrection(
            $services->get('Omeka\ApiManager'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('ViewHelperManager')->get('chaoticumSeminarioSql'),
            $services->get('Omeka\Logger')
        );
    }
}
