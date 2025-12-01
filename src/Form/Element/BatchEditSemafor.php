<?php
namespace ChaoticumSeminario\Form\Element;

use Omeka\Form\Element\PropertySelect;
use Laminas\Form\Element;
use Laminas\ServiceManager\ServiceLocatorInterface;

class BatchEditSemafor extends Element
{
    protected $formElements;
    protected $propertyElement;
    protected $typeElement;

    public function setFormElementManager(ServiceLocatorInterface  $formElements)
    {
        $this->formElements = $formElements;
    }

    public function init()
    {

        $this->setAttribute('data-collection-action','replace');
        $this->setLabel('Add competences with Semafor'); // @translate
        $this->propertyElement = $this->formElements->get(PropertySelect::class)
            ->setName('BatchEditSemafor[property]')
            ->setEmptyOption('Select property') // @translate
            ->setAttributes([
                'class' => 'chosen-select',
                'data-placeholder' => 'Select property', // @translate
            ]);
        $this->typeElement = (new Element\Select('BatchEditSemafor[type]'))
            ->setEmptyOption('[No change]') // @translate
            ->setValueOptions([
                'create' => 'Create competence', // @translate
                'update' => 'Update competence', // @translate
                'delete' => 'Delete competence', // @translate
            ]);
    }

    public function getPropertyElement()
    {
        return $this->propertyElement;
    }

    public function getTypeElement()
    {
        return $this->typeElement;
    }
}
