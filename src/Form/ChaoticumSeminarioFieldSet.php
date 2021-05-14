<?php 
namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class ChaoticumSeminarioFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][heading]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Block title', // @translate
                    'info' => 'Heading for the block, if any.', // @translate
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][params]',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Params', // @translate
                    'info' => 'The params are passed directly to the block layout.', // @translate
                ],
            ]);
    }
}