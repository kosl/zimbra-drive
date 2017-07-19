<?php
/**
 * Copyright 2017 Zextras Srl
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OCA\ZimbraDrive\Auth;

use OC\Accounts\AccountManager;
use OCA\ZimbraDrive\Settings\AppSettings;
use OC\User\User;

abstract class AbstractZimbraUsersBackend extends \OC_User_Backend
{
    const ZIMBRA_GROUP = "zimbra";
    protected $logger;
    protected $config;
    protected $zimbra_url;
    protected $zimbra_port;
    protected $use_ssl;
    protected $trust_invalid_certs;
    protected $url;
    protected $userManager;
    protected $groupManager;
    protected $allow_zimbra_users_login;
    /** @var AccountManager */
    private $accountManager;

    public function __construct()
    {
        $server = \OC::$server;

        $this->logger = $server->getLogger();
        $this->config = $server->getConfig();
        $this->userManager = $server->getUserManager();
        $this->groupManager = $server->getGroupManager();

        if(class_exists('OC\Accounts\AccountManager')) //Nextcloud 11
        {
            $this->accountManager = $server->query('OC\Accounts\AccountManager');
        }

        $appSettings = new AppSettings($this->config);

        $this->zimbra_url =$appSettings->getServerUrl();
        $this->zimbra_port = $appSettings->getServerPort();
        $this->use_ssl = $appSettings->useSSLDuringZimbraAuthentication();
        $this->trust_invalid_certs = $appSettings->trustInvalidCertificatesDuringZimbraAuthentication();
        $this->allow_zimbra_users_login = $appSettings->allowZimbraUsersLogin();

        $this->url = sprintf(
            "%s://%s:%s/service/extension/ZimbraDrive_NcUserZimbraBackend",
            "http" . ($this->use_ssl ? "s" : ""),
            $this->zimbra_url,
            $this->zimbra_port
        );
    }

    /**
     * Check if the password is correct
     * @param string $uid The username
     * @param string $password The password
     * @return string
     *
     * Check if the password is correct without logging in the user
     * returns the user id or false
     */
    public function checkPassword($uid, $password)
    {
        if(!$this->allow_zimbra_users_login)
        {
            return false;
        }

        $httpRequestResponse = $this->doZimbraAuthenticationRequest($uid, $password);

        if ($httpRequestResponse->getHttpCode() === 200) {
            $response = json_decode($httpRequestResponse->getRawResponse());
            $userId = $response->{'accountId'};
            $userDisplayName = $response->{'displayName'};
            $userEmail = $response->{'email'};

            if(!$this->userManager->userExists($userId))
            {
                $this->createUser($userId, $userDisplayName);
            }
            $this->setDefaultUserAttributes($userId, $userEmail, $userDisplayName);

            return $userId;
        } else {
            return false;
        }
    }

    /**
     * @param $userId string
     * @param $userEmail string
     * @param $userDisplayName string
     */
    private function setDefaultUserAttributes($userId, $userEmail, $userDisplayName){
        $user = $this->userManager->get($userId);
        $this->restoreUserEmailIfChanged($user, $userEmail);
        $this->restoreUserDisplayNameIfChanged($user, $userDisplayName);
        $this->setDefaultGroups($user);
    }

    /**
     * @param $user User
     */
    private function setDefaultGroups($user)
    {
        $this->insertUserInGroup($user, self::ZIMBRA_GROUP);
        $this->insertUserInGroup($user, $this->zimbra_url);
    }

    /**
     * @param $userId string
     * @param $userDisplayName string
     */
    protected abstract function createUser($userId, $userDisplayName);

    /**
     * @param $user User
     * @param $userEmail string
     */
    private function restoreUserEmailIfChanged(User $user, $userEmail)
    {
        if( $user->getEMailAddress() !== $userEmail)
        {
            if(!is_null($this->accountManager)) //Nextcloud 11
            {
                $userData = $this->accountManager->getUser($user);
                $userData[AccountManager::PROPERTY_EMAIL]['value'] = $userEmail;
                $this->accountManager->updateUser($user, $userData);
            } else
            {
                $user->setEMailAddress($userEmail);
            }
        }
    }

    private function restoreUserDisplayNameIfChanged(User $user, $userDisplayName)
    {
        if($user->getDisplayName() !== $userDisplayName)
        {
            if(!is_null($this->accountManager)) //Nextcloud 11
            {
                $userData = $this->accountManager->getUser($user);
                $userData[AccountManager::PROPERTY_DISPLAYNAME]['value'] = $userDisplayName;
                $this->accountManager->updateUser($user, $userData);
            } else
            {
                $user->setDisplayName($userDisplayName);
            }
        }
    }


    /**
     * @param User $user
     * @param string $groupName
     */
    protected function insertUserInGroup(User $user, $groupName)
    {
        if(isset($user))
        {
            if(!$this->groupManager->groupExists($groupName))
            {
                $this->groupManager->createGroup($groupName);
            }

            $targetGroup = $this->groupManager->get($groupName);
            if(!$targetGroup->inGroup($user))
            {
                $targetGroup->addUser($user);
            }
        }
    }



    /**
     * Change the display name of a user
     *
     * @param string $uid The username
     * @param string $display_name The new display name
     *
     * @return bool
     */
    public abstract function setDisplayName($uid, $display_name);

    /**
     * @param $uid string
     * @param $password string
     * @return string
     */
    private function buildPostField($uid, $password)
    {
        $fields = array(
            "username" => $uid,
            "password" => $password
        );

        //url-ify the data for the POST
        $fields_string = "";
        foreach ($fields as $key => $value) {
            $fields_string .= $key . "=" . $value . "&";
        }
        $fields_string = rtrim($fields_string, "&");
        return $fields_string;
    }

    /**
     * @param $uid string
     * @param $password string
     * @return HttpRequestResponse
     */
    private function doZimbraAuthenticationRequest($uid, $password)
    {
        $fields_string = $this->buildPostField($uid, $password);

        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if ($this->trust_invalid_certs) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 2);
        }

        //execute post
        $raw_response = curl_exec($ch);
        $response_info = curl_getinfo($ch);
        curl_close($ch);
        $http_code = $response_info["http_code"];
        $httpRequestResponse = new HttpRequestResponse($raw_response, $http_code);
        return $httpRequestResponse;
    }
}

