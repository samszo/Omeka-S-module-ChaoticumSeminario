<?php declare(strict_types=1);

namespace ChaoticumSeminario\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'chaoticumseminario_url_base_from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Url de base source pour correction dns', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_url_base_from',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_url_base_to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Url ou ip de destination pour correction dns', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_url_base_to',
                ],
            ]);
    }
}
