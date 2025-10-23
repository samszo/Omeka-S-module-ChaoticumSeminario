<?php declare(strict_types=1);

namespace ChaoticumSeminario\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Permissions\Acl;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;

class GoogleGeminiCredentials extends AbstractHelper
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
    protected $key;

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
     * Get user Google credentials if any, else the ones of the user set in config.
     */
    public function __invoke(): array
    {
        if (isset($this->key)) {
            return $this->key;
        }

        $this->key = "";

        $user = $this->acl->getAuthenticationService()->getIdentity();
        if (!$user) {
            return $this->key;
        }

        $this->userSettings->setTargetId($user->getId());
        $key = $this->userSettings->get('chaoticumseminario_google_gemini_key');
        if (! $key) {
            $defaultKeyUserId = (int) $this->settings->get('chaoticumseminario_google_gemini_key_default');
            if ($defaultKeyUserId) {
                $this->userSettings->setTargetId($defaultKeyUserId);
                $key = $this->userSettings->get('chaoticumseminario_google_gemini_key');                
            }
        }
        $this->key = $key;

        return $this->key;
    }
}
