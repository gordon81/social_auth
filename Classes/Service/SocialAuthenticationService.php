<?php
namespace MV\SocialAuth\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;

use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
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


class SocialAuthenticationService extends AbstractAuthenticationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * The prefix Id
     */
    public $prefixId = 'SocialAuthenticationService';
    /**
     * The script rel path
     */
    public $scriptRelPath = 'Classes/Service/SocialAuthenticationService.php';
    /**
     * The extension key
     */
    public $extKey = 'social_auth';

    /**
     * provider
     */
    protected $provider;

    /**
     * request
     */
    protected $request;

    /**
     * @var array
     */
    protected $extConfig = [];

    /**
     * @var array
     */
    protected $arrayProvider = [
        'facebook' => 1,
        'google' => 2,
        'twitter' => 3,
        'linkedin' => 4,
        'instagram' => 5,
    ];

    /**
     * Object manager
     *
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * authUtility
     *
     * @var \MV\SocialAuth\Utility\AuthUtility
     */
    protected $authUtility = null;

    /**
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * true - this service was able to authenticate the user
     */
    const STATUS_AUTHENTICATION_SUCCESS_CONTINUE = true;
    /**
     * 100
     */
    const STATUS_AUTHENTICATION_FAILURE_CONTINUE = 100;
    /**
     * 200 - authenticated and no more checking needed - useful for IP checking without password
     */
    const STATUS_AUTHENTICATION_SUCCESS_BREAK = 200;
    /**
     * FALSE - this service was the right one to authenticate the user but it failed
     */
    const STATUS_AUTHENTICATION_FAILURE_BREAK = false;

    /**
     * @return bool
     */
    public function init()
    {
        $this->objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
        $this->signalSlotDispatcher = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
        $this->extConfig = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('social_auth');
        $this->request = GeneralUtility::_GP('tx_socialauth_pi1');
        $this->provider = htmlspecialchars($this->request['provider']);
        $this->logger->error('Loging login init ' . date("Y-m-d H:i:s"),['provider'=>$this->provider]);
        $this->initTSFE();

        return parent::init();
    }

    /**
     * Initializes authentication for this service.
     *
     * @param string $subType: Subtype for authentication (either "getUserFE" or "getUserBE")
     * @param array $loginData: Login data submitted by user and preprocessed by AbstractUserAuthentication
     * @param array $authenticationInformation: Additional TYPO3 information for authentication services (unused here)
     * @param AbstractUserAuthentication $parentObject Calling object
     * @return void
     */
    public function initAuth($subType, $loginData, $authenticationInformation, $parentObject)
    {
        $this->logger->error('Loging login initAuth ' . date("Y-m-d H:i:s"));
        try {
            $this->authUtility = $this->objectManager->get(\MV\SocialAuth\Utility\AuthUtility::class);
        } catch (\Exception $e) {
            $this->logger->error('AuthUtility Exception',['error'=> $e]);
        }
        parent::initAuth($subType, $loginData, $authenticationInformation, $parentObject);
    }

    /**
     * Initializes TSFE
     */
    protected function initTSFE()
    {
        if (TYPO3_MODE === 'FE' && !is_object($GLOBALS['TSFE']->sys_page)) {
            $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(\TYPO3\CMS\Frontend\Page\PageRepository::class);
        }
    }

    /**
     * Find usergroup records
     *
     * @return array User informations
     * @throws \TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFileWritePermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException
     * @throws \TYPO3\CMS\Core\Resource\Exception\InsufficientUserPermissionsException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    public function getUser()
    {
        $user = false;
        $fileObject = null;

        if ( $this->authUtility !== null
            && ( $this->provider
                || $this->authUtility->getStorage()->get('provider') !== null
            )

        ) {

            //get user
            if($this->provider  ){
                $this->authUtility->getStorage()->set('provider',$this->provider);
            }else{
                $this->provider = $this->authUtility->getStorage()->get('provider');
            }
            if($this->isServiceAvailable()){

                [$hybridUser,$token] = $this->authUtility->authenticate($this->provider);
                if ($hybridUser) {
                    $hashedPassword = md5(uniqid());
                    try {
                        $hashInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE');
                        $hashedPassword = $hashInstance->getHashedPassword(uniqid());
                    } catch(InvalidPasswordHashException $e) {
                        $this->logger->error('PasswordHashError',['error'=> $e]);
                    }
                    //create username
                    $email = !empty($hybridUser->email) ? $hybridUser->email : $hybridUser->emailVerified;
                    $username = !empty($email) ? $email : $this->cleanData($hybridUser->displayName, true);
                    $fields = [
                        'pid' => (int) $this->extConfig['users']['storagePid'],
                        'lastlogin' => time(),
                        'crdate' => time(),
                        'tstamp' => time(),
                        'username' => $username,
                        'name' => $this->cleanData($hybridUser->displayName),
                        'first_name' => $this->cleanData($hybridUser->firstName),
                        'last_name' => $this->cleanData($hybridUser->lastName),
                        'password' => $hashedPassword,
                        'email' => $this->cleanData($hybridUser->email),
                        'telephone' => $this->cleanData($hybridUser->phone),
                        'address' => $this->cleanData($hybridUser->address),
                        'city' => $this->cleanData($hybridUser->city),
                        'zip' => $this->cleanData($hybridUser->zip),
                        'country' => $this->cleanData($hybridUser->country),
                        'tx_socialauth_identifier' => $this->cleanData($hybridUser->identifier),
                        'tx_socialauth_source' => $this->arrayProvider[$this->provider]
                    ];
                    //remove null values but keep 0
                    $fields = array_filter($fields, 'strlen');
                    //grab image
                    if (!empty($hybridUser->photoURL)) {
                        $uniqueName = strtolower($this->provider . '_' . $hybridUser->identifier) . '.jpg';
                        $fileContent = GeneralUtility::getUrl($hybridUser->photoURL);
                        if ($fileContent) {
                            $storagePid = $this->extConfig['users']['fileStoragePid'] ? (int) $this->extConfig['users']['fileStoragePid'] : 1; #this default UID is the “fileadmin/“ storage, autocreated by default
                            $storagePath = $this->extConfig['users']['filePath'] ? $this->extConfig['users']['filePath'] : 'user_upload';
                            /* @var $storage \TYPO3\CMS\Core\Resource\ResourceStorage */
                            $storageRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\StorageRepository::class);
                            $storage = $storageRepository->findByUid($storagePid);
                            if ($storage->hasFolder($storagePath)) {
                                /* @var $fileObject \TYPO3\CMS\Core\Resource\AbstractFile */
                                $fileObject = $storage->createFile($uniqueName, $storage->getFolder($storagePath));
                                $storage->setFileContents($fileObject, $fileContent);
                                $fields['image'] = $fileObject->getUid();
                            }
                        }
                    }
                    //signal slot to add other fields
                    $this->signalSlotDispatcher->dispatch(__CLASS__, 'beforeCreateOrUpdateUser', [$hybridUser, &$fields, $this]);
                    //if the user exists in the TYPO3 database
                    $exist = $this->userExist($hybridUser->identifier);
                    $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getConnectionForTable('fe_users');
                    if ($exist) {
                        //Update only necessary fields
                        if (isset($this->extConfig['users']['fieldsExcluded']) && !empty($this->extConfig['users']['fieldsExcluded'])) {
                            $fieldsExcluded = GeneralUtility::trimExplode(',', $this->extConfig['users']['fieldsExcluded']);
                            $fields = array_diff_key($fields, array_flip($fieldsExcluded));
                        }
                        $new = false;
                        $connection->update('fe_users', $fields, ['uid' => (int)$exist['uid']]);
                        $userUid = $exist['uid'];
                    } else {
                        //get default user group
                        $fields['usergroup'] = (int) $this->extConfig['users']['defaultGroup'];
                        $new = true;
                        $connection->insert('fe_users', $fields);
                        $userUid = $connection->lastInsertId('fe_users');
                    }
                    $uniqueUsername = $this->getUnique($username, $userUid);
                    if ($uniqueUsername != $username) {
                        $connection->update('fe_users', ['username' => $uniqueUsername], ['uid' => (int)$userUid]);
                    }
                    $user = $this->getUserInfos($userUid);
                    //create fileReference if needed
                    if (true === $new || (false === $new && $user['image'] == 0) && null !== $fileObject) {
                        $this->createFileReferenceFromFalFileObject($fileObject, $userUid);
                    }
                    $user['new'] = $new;
                    $user['fromHybrid'] = true;
                    if (isset($user['username'])) {
                        $this->login['uname'] = $user['username'];
                    }
                    $this->signalSlotDispatcher->dispatch(__CLASS__, 'getUser', [$hybridUser, &$user, $this]);
                }
            }
        }

        return $user;
    }

    /**
     * Authenticate user
     * @param $user array record
     * @return int One of these values: 100 = Pass, 0 = Failed, 200 = Success
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotException
     * @throws \TYPO3\CMS\Extbase\SignalSlot\Exception\InvalidSlotReturnException
     */
    public function authUser(array $user)
    {
        if (!$user['fromHybrid']) {
            return self::STATUS_AUTHENTICATION_FAILURE_CONTINUE;
        }
        $result = self::STATUS_AUTHENTICATION_FAILURE_CONTINUE;
        if ($user && $this->authUtility !== null && $this->authUtility->isConnectedWithProvider($this->provider)) {
            $result = self::STATUS_AUTHENTICATION_SUCCESS_BREAK;
        }
        //signal slot authUser
        $this->signalSlotDispatcher->dispatch(__CLASS__, 'authUser', [$user, &$result, $this]);

        return $result;
    }

    /**
     * Returns TRUE if single sign on for the given provider is enabled in ext_conf and is available
     *
     * @return boolean
     */
    protected function isServiceAvailable()
    {
        return (boolean) $this->extConfig['providers'][strtolower($this->provider)]['enabled'];
    }

    /**
     * Returns current provider
     *
     * @return string
     */
    public function getCurrentProvider()
    {
        return $this->provider;
    }

    /**
     * @param $identifier
     * @return mixed
     */
    protected function userExist($identifier)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $res = $queryBuilder->select('uid')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter(
                        (int)$this->extConfig['users']['storagePid'],
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->eq(
                    'tx_socialauth_source',
                    $queryBuilder->createNamedParameter(
                        (int)$this->arrayProvider[$this->provider],
                        Connection::PARAM_INT
                    )
                ),
                $queryBuilder->expr()->like(
                    'tx_socialauth_identifier',
                    $queryBuilder->createNamedParameter($identifier, Connection::PARAM_STR)
                )
            )
            ->orderBy('tstamp', 'DESC')
            ->execute()
            ->fetch();
        return $res;
    }

    /**
     * Get user infos
     * @param $uid integer
     * @return array
     */
    protected function getUserInfos($uid)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('fe_users');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $res = $queryBuilder->select('*')
            ->from('fe_users')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter((int)$uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter(
                        (int)$this->extConfig['users']['storagePid'],
                        Connection::PARAM_INT
                    )
                )
            )
            ->execute()
            ->fetch();
        return $res;
    }

    /**
     * Create file reference for fe_user
     *
     * @param \TYPO3\CMS\Core\Resource\FileInterface $file
     * @param int $userUid
     * @return void
     */
    protected function createFileReferenceFromFalFileObject($file, $userUid)
    {
        $fields = [
            'pid' => (int) $this->extConfig['users']['storagePid'],
            'crdate' => time(),
            'tstamp' => time(),
            'table_local' => 'sys_file',
            'uid_local' => $file->getUid(),
            'tablenames' => 'fe_users',
            'uid_foreign' => $userUid,
            'fieldname' => 'image',
        ];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');
        $connection->insert(
            'sys_file_reference',
            $fields,
            [
                Connection::PARAM_INT,
                Connection::PARAM_INT,
                Connection::PARAM_INT,
                Connection::PARAM_STR,
                Connection::PARAM_INT,
                Connection::PARAM_STR,
                Connection::PARAM_INT,
                Connection::PARAM_STR,
            ]
        );
    }

    /**
     * Clean Data
     *
     * @param string $str
     * @param bool $forUsername
     * @return string
     */
    protected function cleanData($str, $forUsername = false)
    {
        $str = strip_tags($str);
        //Remove extra spaces
        $str = preg_replace('/\s{2,}/', ' ', $str);
        //delete space end & begin
        $str = trim($str);
        if (false === mb_check_encoding($str, 'UTF-8')) {
            $str = utf8_encode($str);
        }

        if (true === $forUsername) {
            $str = str_replace(' ', '', $str);
            $str = mb_strtolower($str, 'utf-8');
        }

        return $str;
    }

    /**
     * @param $username
     * @param $id
     * @return string
     */
    protected function getUnique($username, $id)
    {
        /** @var \TYPO3\CMS\Core\DataHandling\DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $username = $dataHandler->getUnique('fe_users', 'username', $username, $id, $this->extConfig['users']['storagePid']);

        return $username;
    }
}