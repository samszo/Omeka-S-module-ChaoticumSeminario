<?php declare(strict_types=1);

namespace ChaoticumSeminario;

return [
    'view_helpers' => [
        'factories' => [
            'chaoticumSeminario' => Service\ViewHelper\ChaoticumSeminarioFactory::class,
            'googleSpeechToText' => Service\ViewHelper\GoogleSpeechToTextFactory::class,
        ],

    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'block_layouts' => [
        'invokables' => [
            'chaoticumSeminario' => Site\BlockLayout\ChaoticumSeminario::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\BatchEditFieldset::class => Form\BatchEditFieldset::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\ChaoticumSeminarioFieldset::class => Form\ChaoticumSeminarioFieldset::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'chaoticumseminario' => [
        'config' => [
            'chaoticumseminario_url_base_from' => '',
            'chaoticumseminario_url_base_to' => '',
        ],
        'user_settings' => [
            'chaoticumseminario_google_credentials' => '',
        ],
        'block_settings' => [
            'chaoticumSeminario' => [
                'heading' => '',
                'media_id' => null,
            ],
        ],
    ],
];
