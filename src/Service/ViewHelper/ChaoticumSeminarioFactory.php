<?php
namespace ChaoticumSeminario\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ChaoticumSeminario\View\Helper\ChaoticumSeminarioViewHelper;

/* merci beaucoup à Daniel Berthereau pour le module DeritativeMedia
*/
class ChaoticumSeminarioFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $arrS = [
            'api'=>$services->get('Omeka\ApiManager')
            ,'conn' => $services->get('Omeka\Connection')
            ,'logger' => $services->get('Omeka\Logger')
            ,'store' => $services->get('Omeka\File\Store')
            ,'basePath' => $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')
            ,'cli' => $services->get('Omeka\Cli')
            ,'tempFileFactory' => $services->get('Omeka\File\TempFileFactory')
            ,'entityManager' => $services->get('Omeka\EntityManager')
        ]; 

        return new ChaoticumSeminarioViewHelper($arrS);
    }
}