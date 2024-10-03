<?php declare(strict_types=1);

namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Chaoticum Seminario'; // @translate

    protected $elementGroups = [
        'chaoticum_seminario' => 'Chaoticum Seminario', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'chaoticum-seminario')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'chaoticumseminario_google_credentials',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'chaoticum_seminario',
                    'label' => 'Google credentials (json)', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_google_credentials',
                    'rows' => 5,
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_anythingllm_login',
                'type' => Element\Textarea::class,
                'options' => [
                    'element_group' => 'chaoticum_seminario',
                    'label' => 'AnythingLLM login', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anythingllm_login',
                    'rows' => 1,
                ],
            ])

        ;
    }
}
