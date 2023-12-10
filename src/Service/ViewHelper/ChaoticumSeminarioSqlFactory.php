<?php
namespace ChaoticumSeminario\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ChaoticumSeminario\View\Helper\ChaoticumSeminarioSql;

class ChaoticumSeminarioSqlFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $conn = $services->get('Omeka\Connection');

        return new ChaoticumSeminarioSql($api, $conn);
    }
}