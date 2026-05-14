<?php declare(strict_types=1);

namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class ChaoticumSeminarioConferencesFieldset extends Fieldset
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
                'name' => 'o:block[__blockIndex__][o:data][item_set_id]',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Item set id', // @translate
                    'info' => 'Filter conferences by item set (optional).', // @translate
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][conference_template]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Conference resource template', // @translate
                    'info' => 'Label of the resource template for conferences (e.g. "Cours").', // @translate
                ],
                'attributes' => [
                    'placeholder' => 'Cours',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][transcription_template]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Transcription resource template', // @translate
                    'info' => 'Label of the resource template for transcriptions (e.g. "Transcription").', // @translate
                ],
                'attributes' => [
                    'placeholder' => 'Transcription',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][per_page]',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Conferences per page', // @translate
                ],
                'attributes' => [
                    'value' => 10,
                    'min' => 1,
                    'max' => 100,
                ],
            ]);
    }
}
