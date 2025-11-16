<?php declare(strict_types=1);

namespace ChaoticumSeminario;

return [
    'view_helpers' => [
        'factories' => [
            'chaoticumSeminarioSql' => Service\ViewHelper\ChaoticumSeminarioSqlFactory::class,
            'chaoticumSeminario' => Service\ViewHelper\ChaoticumSeminarioFactory::class,
            'googleSpeechToText' => Service\ViewHelper\GoogleSpeechToTextFactory::class,
            'googleSpeechToTextCredentials' => Service\ViewHelper\GoogleSpeechToTextCredentialsFactory::class,
            'googleGeminiCredentials' => Service\ViewHelper\GoogleGeminiCredentialsFactory::class,
            'whisperSpeechToText' => Service\ViewHelper\WhisperSpeechToTextFactory::class,
            'transformersPipeline' => Service\ViewHelper\TransformersPipelineFactory::class,
            'anythingLLMCredentials' => Service\ViewHelper\AnythingLLMCredentialsFactory::class,    
            'anythingLLM' => Service\ViewHelper\AnythingLLMFactory::class,    
            'chaoticumSeminarioCredentials' => Service\ViewHelper\ChaoticumSeminarioCredentialsFactory::class,
            'pdfToMarkdown' => Service\ViewHelper\PdfToMarkdownFactory::class,  
            'semaforCredentials' => Service\ViewHelper\SemaforCredentialsFactory::class
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
            'chaoticumSeminarioExplore' => Site\BlockLayout\ChaoticumSeminarioExplore::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\BatchEditFieldset::class => Form\BatchEditFieldset::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\ChaoticumSeminarioFieldset::class => Form\ChaoticumSeminarioFieldset::class,
            Form\ChaoticumSeminarioExploreFieldset::class => Form\ChaoticumSeminarioExploreFieldset::class,
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
            'chaoticumseminario_google_credentials_default' => 0,
            'chaoticumseminario_google_gemini_key_default' => 0,
            'chaoticumseminario_url_base_from' => '',
            'chaoticumseminario_url_base_to' => '',
            'chaoticumseminario_url_anythingllm_api' => 'http://localhost:3001/api/v1/',
            'chaoticumseminario_anonymous_mail' => '',
            'chaoticumseminario_anonymous_pwd' => '',
            'chaoticumseminario_anonymous_key_identity' => '',
            'chaoticumseminario_anonymous_key_credential' => '',
            'chaoticumseminario_moteur_pdf_conversion' => '',
            'chaoticumseminario_url_semafor_api' => '',
        ],
        'user_settings' => [
            'chaoticumseminario_google_credentials' => '',
            'chaoticumseminario_google_gemini_key' => '',
            'chaoticumseminario_anythingllm_login' => '',
            'chaoticumseminario_anythingllm_key' => '',
            'chaoticumseminario_anythingllm_workspace' => '',
            'chaoticumseminario_semafor_client_id' => '',
            'chaoticumseminario_semafor_client_secret' => '',
        ],
        'block_settings' => [
            'chaoticumSeminario' => [
                'heading' => '',
                'media_id' => null,
            ],
            'chaoticumSeminarioExplore' => [
                'heading' => '',
                'item_id' => null,
            ],
        ],
    ],
];
