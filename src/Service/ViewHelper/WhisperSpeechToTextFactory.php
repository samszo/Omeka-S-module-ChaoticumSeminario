<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\WhisperSpeechToText;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class WhisperSpeechToTextFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new WhisperSpeechToText(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Logger'),
            $services->get('ViewHelperManager')->get('chaoticumSeminario'),
            $services->get('Config')
        );
    }
}
