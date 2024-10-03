<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Permissions\Acl;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;

class AnythingLLMCredentials extends AbstractHelper
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
            return $this->credentials;
        }

        $this->userSettings->setTargetId($user->getId());
        $this->credentials = $this->userSettings->get('chaoticumseminario_anythingllm_login');

        return ['login'=>$this->credentials];
    }
}
