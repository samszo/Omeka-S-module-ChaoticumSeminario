<?php declare(strict_types=1);

namespace ChaoticumSeminario\Service\ViewHelper;

use ChaoticumSeminario\View\Helper\GoogleSpeechToText;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GoogleSpeechToTextFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $filename = OMEKA_PATH . '/modules/ChaoticumSeminario/config/code_secret_client.json';
        $credentials = file_exists($filename) && is_readable($filename) && filesize($filename)
            ? json_decode(file_get_contents($filename), true)
            : [];
        return new GoogleSpeechToText(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Config'),
            $services->get('Omeka\Logger'),
            $credentials
        );
    }
}
