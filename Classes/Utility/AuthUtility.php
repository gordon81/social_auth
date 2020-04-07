<?php
namespace MV\SocialAuth\Utility;

use Hybridauth\Hybridauth;
use Hybridauth\Storage\Session;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2015 VANCLOOSTER Mickael <vanclooster.mickael@gmail.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Class AuthUtility
 *
 * @package MV\SocialAuth\Utility
 */
class AuthUtility
{
    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var array
     */
    protected $extConfig = [];

    /**
     * @var Hybridauth $hybridAuth
     */
    protected $hybridAuth;

    /**
     * $logger
     */
    protected $logger;
    /**
     * @var Session
     */
    protected $storage;

    /**
     * initializeObject
     */
    public function initializeObject()
    {

        $this->extConfig = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('social_auth');
        $this->config = array(
            'callback' => GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . '?type=1316773682&logintype=login',
            'providers' => array(
                'Facebook' => array(
                    'enabled' =>  $this->extConfig['providers']['facebook']['enabled'],
                    'keys'    => array(
                        'id' => $this->extConfig['providers']['facebook']['keys']['id'],
                        'secret' => $this->extConfig['providers']['facebook']['keys']['secret']
                    ),
                    'scope'   => $this->extConfig['providers']['facebook']['scope'],
                    'display' => ($this->extConfig['providers']['facebook']['display']) ? $this->extConfig['provider']['facebook']['display'] : 'page'
                ),
                'Google' => array(
                    'enabled' =>  $this->extConfig['providers']['google']['enabled'],
                    'keys'    => array(
                        'id' => $this->extConfig['providers']['google']['keys']['id'],
                        'secret' => $this->extConfig['providers']['google']['keys']['secret']
                    ),
                    'scope'   => $this->extConfig['providers']['google']['scope']
                ),
                'Twitter' => array(
                    'enabled' =>  $this->extConfig['providers']['twitter']['enabled'],
                    'keys'    => array(
                        'key' => $this->extConfig['providers']['twitter']['keys']['key'],
                        'secret' => $this->extConfig['providers']['twitter']['keys']['secret']
                    )
                ),
                'LinkedIn' => array(
                    'enabled' =>  $this->extConfig['providers']['linkedin']['enabled'],
                    'keys'    => array(
                        'id' => $this->extConfig['providers']['linkedin']['keys']['key'],
                        'secret' => $this->extConfig['providers']['linkedin']['keys']['secret']
                    )
                ),
                'Instagram' => array(
                    'enabled' =>  $this->extConfig['providers']['instagram']['enabled'],
                    'keys'    => array(
                        'id' => $this->extConfig['providers']['instagram']['keys']['id'],
                        'secret' => $this->extConfig['providers']['instagram']['keys']['secret']
                    ),
                    'scope'   => $this->extConfig['providers']['instagram']['scope'],
                    'wrapper' => array(
                        'class' => 'Hybrid_Providers_Instagram',
                        'path' => ExtensionManagementUtility::extPath('social_auth').'Resources/Private/Librairies/hybridauth/hybridauth/additional-providers/hybridauth-instagram/Providers/Instagram.php'
                    )
                )
            ),
            'debug_mode' => true,
            'debug_file' => ExtensionManagementUtility::extPath('social_auth').'debug.txt',
        );


        /* @var $logManager \TYPO3\CMS\Core\Log\LogManager */
        $logManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class);
        $this->logger = $logManager->getLogger(__CLASS__);
        $this->hybridAuth = new \Hybridauth\Hybridauth($this->config);
        $this->storage = new Session();
    }

    /**
     * @param string $provider
     *
     *  @return array |FALSE
     */
    public function authenticate($provider)
    {
        $socialUser = null;
        try {
            $service = $this->hybridAuth->authenticate($provider);
            $accessToken = $service->getAccessToken();
            $socialUser = $service->getUserProfile();
        } catch (\Exception $exception) {
            $this->logger->debug('Exception',[$exception->getCode(),$exception->getMessage(),$exception->getTrace()]);
            switch ($exception->getCode()) {
                case 0:
                    $error = 'Unspecified error.';
                    break;
                case 1:
                    $error = 'Hybriauth configuration error.';
                    break;
                case 2:
                    $error = 'Provider not properly configured.';
                    break;
                case 3:
                    $error = 'Unknown or disabled provider.';
                    break;
                case 4:
                    $error = 'Missing provider application credentials.';
                    break;
                case 5:
                    $error = 'User has cancelled the authentication or the provider refused the connection.';
                    break;
                case 6:
                    $error = 'User profile request failed. Most likely the user is not connected to the provider and he should to authenticate again.';
                    break;
                case 7:
                    $error = 'User not connected to the provider.';
                    break;
                default:
                    $error = 'Unknown error';
            }
            $this->logger->log(
                \TYPO3\CMS\Core\Log\LogLevel::ERROR,
                $error
            );

            $this->logout();

            HttpUtility::redirect(GeneralUtility::getIndpEnv('TYPO3_SITE_URL') . '?tx_socialauth_pi1[error]='.$exception->getCode());
        }
        if (null !== $socialUser && null !== $accessToken) {
            return [$socialUser , $accessToken];
        } else {
            return [false,false];
        }
    }

    /**
     * @param string $provider
     *
     *  @return boolean
     */
    public function isConnectedWithProvider($provider)
    {
        return $this->hybridAuth->isConnectedWith($provider);
    }

    /**
     * logout from all providers when typo3 logout takes place
     * return void
     */
    public function logout()
    {
        $this->hybridAuth->disconnectAllAdapters();
    }

    /**
     * @return Session
     */
    public function getStorage()
    {
        return $this->storage;
    }

}
