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
                'name' => 'chaoticumseminario_google_credentials_default',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Id de l’utilisateur dont le compte Google est utilisé par défaut', // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_google_credentials_default',
                ],
            ])
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
            ])
            ->add([
                'name' => 'chaoticumseminario_url_anythingllm_api',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Url de l'API AnythingLLM", // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_url_anythingllm_api',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_anonymous_mail',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Mail de l'utilisateur annonyme", // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anonymous_mail',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_anonymous_pwd',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Mot de passe de l'utilisateur annonyme", // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anonymous_pwd',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_anonymous_key_identity',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Identité d'API de l'utilisateur annonyme", // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anonymous_key_identity',
                ],
            ])
            ->add([
                'name' => 'chaoticumseminario_anonymous_key_credential',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Clef d'API de l'utilisateur annonyme", // @translate
                ],
                'attributes' => [
                    'id' => 'chaoticumseminario_anonymous_key_credential',
                ],
            ]);
    }
}
