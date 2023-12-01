<?php declare(strict_types=1);

namespace ChaoticumSeminario\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

class ChaoticumSeminario extends AbstractBlockLayout
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/block-layout/chaoticum-seminario';

    public function getLabel()
    {
        return 'Chaoticum Seminario'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        // Factory is not used to make rendering simpler.
        $services = $site->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $defaultSettings = $services->get('Config')['chaoticumseminario']['block_settings']['chaoticumSeminario'];
        $blockFieldset = \ChaoticumSeminario\Form\ChaoticumSeminarioFieldset::class;

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
        try {
            $media = $view->api()->read('media', (int) $block->dataValue('media_id'))->getContent();
        } catch (\Exception $e) {
            $media = null;
        }

        $vars = [
            'block' => $block,
            'heading' => $block->dataValue('heading', ''),
            'media' => $media,
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
            ->appendFile($assetUrl('js/d3.min.js', 'ChaoticumSeminario'))
            ->appendFile($assetUrl('js/timelines-chart.min.js', 'ChaoticumSeminario'));

        $view->headLink()
            ->prependStylesheet($assetUrl('css/main.css', 'ChaoticumSeminario'));
    }
}
