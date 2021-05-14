<?php
namespace ChaoticumSeminario;

return [

    'view_helpers' => [
        
        'invokables' => [
            'ChaoticumSeminarioViewHelper' => View\Helper\ChaoticumSeminarioViewHelper::class,
        ],                
        'factories'  => [
            'ChaoticumSeminarioFactory' => Service\ViewHelper\ChaoticumSeminarioFactory::class,
        ],

    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],    
    'block_layouts' => [
        'invokables' => [
            'ChaoticumSeminario' => Site\BlockLayout\ChaoticumSeminario::class,
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
    'ChaoticumSeminario' => [
        'block_settings' => [
            'ChaoticumSeminario' => [
                'heading' => '',
                'params'  =>'',
            ],
        ],
    ],
];
