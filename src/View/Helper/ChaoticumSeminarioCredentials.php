<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;
use Laminas\Authentication\AuthenticationService;
//use Omeka\Settings\ApiManager;

class ChaoticumSeminarioCredentials extends AbstractHelper
{
    /**
     * @var 
     */
    protected $api;

    /**
     * @var AuthenticationService
     */
    protected $auth;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var UserSettings
     */
    protected $userSettings;

    /**
     * @var array
     */
    protected $credentials;

    /**
     * @var array
     */
    protected $actant;

    public function __construct(
        AuthenticationService $auth,
        Settings $settings,
        UserSettings $userSettings,
         $api
    ) {

        $this->auth = $auth;
        $this->settings = $settings;
        $this->userSettings = $userSettings;
        $this->api = $api;
    }

    /**
     * Get user credentials if any, else the ones of the user set in config.
     */
    public function __invoke($params=[]): array
    {
        if (isset($this->credentials)) {
            return $this->credentials;
        }
        $this->credentials = [];

        if(isset($params['logout'])){
            $this->auth->clearIdentity();
            return true;
        }
    
        if(isset($params['getActantAnonyme'])){
            $actant = $this->getActantAnonyme();       
            $this->auth->clearIdentity();
            return $actant;
        }
    
        //récupère l'actant
        $user = $this->auth->getIdentity();
        $this->actant = $this->ajouteActant($user);
        if(isset($data['getActant'])){
            return $this->actant;
        }
        //récupère les clef d'API
        $apiIdentity=$this->settings->get('chaoticumseminario_anonymous_key_identity');
        $apiCredential=$this->settings->get('chaoticumseminario_anonymous_key_credential');

        $this->credentials = [
            'userMail'=>$user->getEmail(),
            'userName'=>$user->getName(),
            'userRole'=>$user->getRole(),
            'actant'=>$this->actant,
            "apiIdentity"=>$apiIdentity,"apiCredential"=>$apiCredential
        ];        
        return $this->credentials;
    }

     /** récupère l'actant anonyme
     *
     * @return array
     */
    protected function getActantAnonyme()
    {
        $adapter = $this->auth->getAdapter();
        $adapter->setIdentity($this->settings->get('chaoticumseminario_anonymous_mail'));
        $adapter->setCredential($this->settings->get('chaoticumseminario_anonymous_pwd'));
        $user = $this->auth->authenticate()->getIdentity();                      
        return $this->api->read('users', ['email'=>$this->settings->get('chaoticumseminario_anonymous_mail')])->getContent();
    }

    /** Ajoute un actant
     *
     * @param object $user
     * @return o:item
     */
    protected function ajouteActant($user)
    {

        //vérifie la présence de l'item pour gérer les mises à jour
        $foafAN =  $this->api->search('properties', ['term' => 'foaf:accountName'])->getContent()[0];

        $rc =  $this->api->search('resource_classes', ['term' => 'jdc:Actant',])->getContent()[0];
        $foafA =  $this->api->search('properties', ['term' => 'foaf:account',])->getContent()[0];
        $service =  $this->api->search('properties', ['term' => 'oa:annotationService',])->getContent()[0];

        if(!$user)$user=$this->getActantAnonyme();
        $itemU=$this->api->read('users',  $user->getId())->getContent();

        //création de l'item
        $oItem = [];
        $valueObject = [];
        $valueObject['property_id'] = $foafA->id();
        $valueObject['@value'] = "Chaoticum Seminario";
        $valueObject['type'] = 'literal';
        $oItem[$foafA->term()][] = $valueObject;    
        $valueObject = [];
        $valueObject['property_id'] = $service->id();
        $valueObject['@id'] = $itemU->adminUrl();
        $valueObject['o:label'] = 'omeka user';
        $valueObject['type'] = 'uri';
        $oItem[$service->term()][] = $valueObject;    

        $param = array();
        $param['property'][0]['property']= $foafAN->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$user->getName(); 
        $result = $this->api->search('items',$param)->getContent();
        if(count($result)){
            $result = $result[0];
            //vérifie s'il faut ajouter le compte
            $comptes = $result->value($foafA->term(),['all'=>true]);
            foreach ($comptes as $c) {
            $v = $c->asHtml();
            if($v=="Chaoticum Seminario")return $result;
            }
            $this->api->update('items', $result->id(), $oItem, [], ['continueOnError' => true,'isPartial'=>1, 'collectionAction' => 'append']);
        }else{
            $valueObject = [];
            $valueObject['property_id'] = $foafAN->id();
            $valueObject['@value'] = $user->getName();
            $valueObject['type'] = 'literal';
            $oItem[$foafAN->term()][] = $valueObject;    
            $oItem['o:resource_class'] = ['o:id' => $rc->id()];
            $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        }              
        return $result;

    }    

}
