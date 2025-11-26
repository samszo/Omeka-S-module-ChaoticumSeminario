<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Permissions\Acl;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;
use Omeka\Api\Exception\RuntimeException;

class SemaforCredentials extends AbstractHelper
{
    /**
     * @var Acl
     */
    protected $acl;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var UserSettings
     */
    protected $userSettings;

    protected $url;
    protected $clientId;
    protected $secret;
    protected $token;
    protected $grantType = 'client_credentials';
    /**
     * @var array
     */
    protected $credentials;

    public function __construct(
        Acl $acl,
        Settings $settings,
        UserSettings $userSettings
    ) {
        $this->acl = $acl;
        $this->settings = $settings;
        $this->userSettings = $userSettings;
    }

    /**
     * Get user AnythingLLM credentials if any, else the ones of the user set in config.
     */
    public function __invoke(): array
    {
        if (isset($this->credentials)) {
            return $this->credentials;
        }

        $this->credentials = [];

        $user = $this->acl->getAuthenticationService()->getIdentity();
        if (!$user) {
            throw new RuntimeException('User not allow do to use Semafor');
            return $this->credentials;
        }

        $this->userSettings->setTargetId($user->getId());
        $this->clientId = $this->userSettings->get('chaoticumseminario_semafor_client_id');
        if(empty($this->clientId)) {
            throw new RuntimeException('User not set Semafor Client Id');
        }   
        $this->url =  $this->settings->get('chaoticumseminario_url_semafor_api');
        if(empty($this->url)) {
            throw new RuntimeException('Module not set Semafor Url');
        }   
        $this->secret = $this->userSettings->get('chaoticumseminario_semafor_client_secret');
        if(empty($this->secret)) {
            throw new RuntimeException('User not set Semafor Client Secret');
        }   
        
        $this->getToken();

        $this->credentials = ['client_id'=>$this->clientId,'url'=>$this->url,'token'=>$this->token];
        return $this->credentials;
    }

    private function getToken(): string {
        // Vérifier si le token est déjà stocké et s'il est encore valide
        $tokenFile = sys_get_temp_dir().'token'.$this->clientId.'.txt';

        if (file_exists($tokenFile)) {
            $tokenData = @json_decode(file_get_contents($tokenFile), true);
        }else{
            $tokenData = null;
        }       
        $this->token = null;

        if ($tokenData && isset($tokenData['token']) && isset($tokenData['expires_at']) && $tokenData['expires_at'] > time()) {
            // Utiliser le token existant
            $this->token = $tokenData['token'];
        } else {
            // Récupérer un nouveau token  
            $url =  $this->settings->get('chaoticumseminario_url_semafor_token');
            if(empty($url)) {
                throw new RuntimeException('Module not set Semafor Token Url');
            }
            $authDataSemafor = array(
                'client_id' => $this->clientId,
                'client_secret' => $this->secret,
                'grant_type' => $this->grantType,
            );  

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($authDataSemafor));
            $authResponse = curl_exec($ch);
            curl_close($ch);

            if ($authResponse === false) {
                throw new RuntimeException('Erreur cURL lors de la récupération du token : ' . curl_error($ch));
            }

            $authResponseData = json_decode($authResponse, true);
            if (isset($authResponseData['access_token']) && isset($authResponseData['expires_in'])) {
                $this->token = $authResponseData['access_token'];
                $expiresAt = time() + $authResponseData['expires_in'];

                // Stocker le token et sa date d'expiration
                file_put_contents($tokenFile, json_encode(array(
                    'token' => $token,
                    'expires_at' => $expiresAt
                )));
            } else {
                throw new RuntimeException('Erreur lors de la récupération du token : réponse invalide');
            }
        }

        return $this->token;
    }

}
