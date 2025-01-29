<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\AnythingLLMCredentials;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AnythingLLMCredentialsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AnythingLLMCredentials(
            $services->get('Omeka\Acl'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\User')
        );
    }
}
