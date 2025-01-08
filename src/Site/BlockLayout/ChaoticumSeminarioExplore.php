<?php declare(strict_types=1);

namespace ChaoticumSeminario\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

class ChaoticumSeminarioExplore extends AbstractBlockLayout
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/block-layout/chaoticum-seminario-explore';

    public function getLabel()
    {
        return 'Chaoticum Seminario Explore'; // @translate
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
        $defaultSettings = $services->get('Config')['chaoticumseminario']['block_settings']['chaoticumSeminarioExplore'];
        $blockFieldset = \ChaoticumSeminario\Form\ChaoticumSeminarioExploreFieldset::class;

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
        $assetUrl = $view->plugin('assetUrl');

        try {
            $item = $view->api()->read('items', (int) $block->dataValue('item_id'))->getContent();
        } catch (\Exception $e) {
            $item = null;
        }

        $credentials = $view->chaoticumSeminarioCredentials();

        $vars = [
            'block' => $block,
            'heading' => $block->dataValue('heading', ''),
            'item' => $item,
            'credentials'=>$credentials,
            'assetUrl'=>$assetUrl('', 'ChaoticumSeminario',false,false)
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
            ->appendFile($assetUrl('js/d3.min.js', 'ChaoticumSeminario'));
        $view->headScript()
            ->appendFile($assetUrl('js/bootstrap5.3.bundle.min.js', 'ChaoticumSeminario'));
        //$view->headScript()
        //    ->appendFile($assetUrl('js/all.min.js', 'ChaoticumSeminario'));                        
        $view->headScript()
            ->appendFile($assetUrl('modules/main.js', 'ChaoticumSeminario'),"module");

        $view->headLink()
            ->prependStylesheet($assetUrl('css/main.css', 'ChaoticumSeminario'));
        $view->headLink()
            ->prependStylesheet($assetUrl('css/bootstrap5.3.min.css', 'ChaoticumSeminario'));
        $view->headLink()
            ->prependStylesheet($assetUrl('css/all.min.css', 'ChaoticumSeminario'));
            
    }
}
