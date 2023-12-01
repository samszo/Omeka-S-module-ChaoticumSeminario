<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\GoogleSpeechToTextCredentials;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GoogleSpeechToTextCredentialsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new GoogleSpeechToTextCredentials(
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\User')
        );
    }
}
