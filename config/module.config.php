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
        'block_settings' => [
            'chaoticumSeminario' => [
                'heading' => '',
                'media_id' => null,
            ],
        ],
    ],
];
