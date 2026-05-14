<?php declare(strict_types=1);

namespace ChaoticumSeminario\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

class ChaoticumSeminarioConferences extends AbstractBlockLayout
{
    const PARTIAL_NAME = 'common/block-layout/chaoticum-seminario-conferences';

    public function getLabel()
    {
        return 'Chaoticum Seminario Conferences'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        $services = $site->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $defaultSettings = $services->get('Config')['chaoticumseminario']['block_settings']['chaoticumSeminarioConferences'];
        $blockFieldset = \ChaoticumSeminario\Form\ChaoticumSeminarioConferencesFieldset::class;

        $data = $block ? $block->data() + $defaultSettings : $defaultSettings;

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $formElementManager->get($blockFieldset);
        $fieldset->populateValues($dataForm);

        return $view->formCollection($fieldset, false);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $api = $view->api();

        $conferenceTemplate = $block->dataValue('conference_template', 'Cours');
        $transcriptionTemplate = $block->dataValue('transcription_template', 'Transcription');
        $itemSetId = (int) $block->dataValue('item_set_id', 0);
        $perPage = (int) $block->dataValue('per_page', 10);

        $query = ['resource_template_label' => $conferenceTemplate, 'sort_by' => 'dcterms:valid', 'sort_order' => 'desc'];
        if ($itemSetId) {
            $query['item_set_id'] = $itemSetId;
        }

        try {
            $response = $api->search('items', $query + ['per_page' => $perPage]);
            $conferences = $response->getContent();
            $totalConferences = $response->getTotalResults();
        } catch (\Exception $e) {
            $conferences = [];
            $totalConferences = 0;
        }

        $conferencesByTheme = [];
        foreach ($conferences as $conference) {
            $themeValue = $conference->value('curation:theme');
            $theme = $themeValue ? $themeValue->asHtml() : '';

            $transcriptions = [];
            try {
                $transResponse = $api->search('items', [
                    'resource_template_label' => $transcriptionTemplate,
                    'property' => [[
                        'joiner' => 'and',
                        'property' => 'dcterms:isReferencedBy',
                        'type' => 'res',
                        'text' => $conference->id(),
                    ]],
                ]);
                $transcriptions = $transResponse->getContent();
            } catch (\Exception $e) {
                $transcriptions = [];
            }

            $conferencesByTheme[$theme][] = [
                'item' => $conference,
                'transcriptions' => $transcriptions,
            ];
        }
        ksort($conferencesByTheme);

        $vars = [
            'block' => $block,
            'heading' => $block->dataValue('heading', ''),
            'conferencesByTheme' => $conferencesByTheme,
            'totalConferences' => $totalConferences,
        ];
        return $view->partial(self::PARTIAL_NAME, $vars);
    }

    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        return strip_tags($this->render($view, $block));
    }

    public function prepareRender(PhpRenderer $view): void
    {
        $assetUrl = $view->plugin('assetUrl');
        $view->headScript()
            ->appendFile($assetUrl('js/bootstrap5.3.bundle.min.js', 'ChaoticumSeminario'));
        $view->headLink()
            ->prependStylesheet($assetUrl('css/main.css', 'ChaoticumSeminario'))
            ->prependStylesheet($assetUrl('css/bootstrap5.3.min.css', 'ChaoticumSeminario'))
            ->prependStylesheet($assetUrl('css/all.min.css', 'ChaoticumSeminario'));
    }
}
