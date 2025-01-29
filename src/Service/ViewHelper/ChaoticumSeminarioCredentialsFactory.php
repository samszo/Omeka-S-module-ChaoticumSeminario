<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\ChaoticumSeminarioCredentials;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ChaoticumSeminarioCredentialsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ChaoticumSeminarioCredentials(
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\User'),
            $services->get('Omeka\ApiManager')
        );
    }
}
