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
    const PARTIAL_NAME = 'common/block-layout/ChaoticumSeminario';

    public function getLabel()
    {
        return 'ChaoticumSeminario'; // @translate
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
        $defaultSettings = $services->get('Config')['ChaoticumSeminario']['block_settings']['ChaoticumSeminario'];
        $blockFieldset = \ChaoticumSeminario\Form\ChaoticumSeminarioFieldset::class;

        $data = $block ? $block->data() + $defaultSettings : $defaultSettings;

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $formElementManager->get($blockFieldset);
        $fieldset->populateValues($dataForm);

        $html = '<p>'
            . $view->translate('A simple block allow to display a partial from the theme.') // @translate
            . ' ' . $view->translate('It may be used for a static html content, like a list of partners, or a complex layout, since any Omeka feature is available in a view.') // @translate
            . '</p>';
        $html .= $view->formCollection($fieldset, false);
        return $html;
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        //$view->api()
        $vars = [
            'block' => $block,
            'heading' => $block->dataValue('heading', ''),
            'params' => $block->dataValue('params', ''),
        ];
        return $view->partial(self::PARTIAL_NAME, $vars);
    }

    public function getFulltextText(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        return strip_tags($this->render($view, $block));
    }

    public function prepareRender(PhpRenderer $view): void
    {
        $view->headScript()->appendFile($view->assetUrl('js/d3.min.js', 'ChaoticumSeminario'));
        $view->headScript()->appendFile($view->assetUrl('js/timelines-chart.min.js', 'ChaoticumSeminario'));

        $view->headLink()->prependStylesheet($view->assetUrl('css/main.css', 'ChaoticumSeminario'));
    }
}
