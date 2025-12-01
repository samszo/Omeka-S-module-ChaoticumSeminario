<?php
namespace ChaoticumSeminario\Service\Form\Element;

use Interop\Container\ContainerInterface;
use ChaoticumSeminario\Form\Element\BatchEditSemafor;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BatchEditSemaforFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new BatchEditSemafor;
        $element->setFormElementManager($services->get('FormElementManager'));
        return $element;
    }
}
