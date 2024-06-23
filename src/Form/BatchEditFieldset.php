<?php declare(strict_types=1);

namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        // Omeka ne gère pas les fieldsets, mais cela permet d'avoir un titre.
        $this
            ->setName('chaoticum_seminario')
            ->setOptions([
                'label' => 'Chaoticum Seminario', // @translate
            ])
            ->setAttributes([
                'id' => 'chaoticum_seminario',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'chaoticumseminario_google_speech_to_text',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Convert speech to text via Google', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_google_speech_to_text',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
         ;
    }
}
