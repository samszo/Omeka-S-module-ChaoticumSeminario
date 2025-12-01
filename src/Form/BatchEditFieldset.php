<?php declare(strict_types=1);

namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;
use ChaoticumSeminario\Form\Element\BatchEditSemafor;

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

            ->add([
                'name' => 'chaoticumseminario_whisper_speech_to_text',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Convert speech to text via Whisper', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_whisper_speech_to_text',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_transformer_token_classification',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Extract token classification from title', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_transformer_token_classification',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Select::class,
                'name' => 'chaoticumseminario_pdfToMarkdown',
                'options' => [
                    'label' => 'Transcript PDF to mardown',
                    'value_options' => [
                        'no' => 'no transcription',
                        'marker' => 'datalab-to marker',
                        'Gemini' => 'Google Gemini',
                        'PdfToMarkdownParser' => 'php Pdf To Markdown Parser',
                    ],
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_pdfToMarkdown',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Select::class,
                'name' => 'chaoticumseminario_anythingllm_addDoc',
                'options' => [
                    'label' => 'Add item to AnythingLLM RAG',
                    'value_options' => [
                        'no' => 'no adding',
                        '0' => 'Transcription',
                        '1' => 'HAL',
                        '2' => 'Title and description',
                    ],
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anythingllm_addDoc',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                    'type' => BatchEditSemafor::class,
                    'name' => 'chaoticumseminario_semafor_addCompetences',
                ])
            /*                        
            ->add([
                'type' => PropertySelect::class,
                'name' => 'chaoticumseminario_semafor_addCompetences',
                'options' => [
                    'label' => 'Add competence to item whith Semafor from specific property', // @translate
                    'required' => false,
                    'empty_option' => '[Do nothing]',
                ],
                'attributes' => [
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select one…', // @translate
                    'id' => 'chaoticumseminario_semafor_specificCompetences',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])           
            */
        ;
    }
}
