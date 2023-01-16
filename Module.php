<?php declare(strict_types=1);

/*

 */

namespace ChaoticumSeminario;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\Mvc\MvcEvent;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        // The autoload doesn’t work with GetId3.
        // @see \IiifServer\Service\ControllerPlugin\MediaDimensionFactory
        require_once __DIR__ . '/vendor/autoload.php';
    }
}
