<?php
/**
 * Copyright (C) 2017 ZeXtras S.r.l.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2 of
 * the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License.
 * If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\ZimbraDrive\Service\Test;


use OCA\ZimbraDrive\Controller\AdminApiController;
use OCP\IConfig;

class EnabledZimbraUsersTest implements Test
{
    /**
     * @var IConfig
     */
    private $config;

    /**
     * @param IConfig $config
     */
    public function __construct(IConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return TestResult
     */
    public function run()
    {
        if($this->isZimbraDriveAuthenticationEnabled())
        {
            $message = "Zimbra's users are enabled.";
            return new TestOk($this->getName(), $message);
        } else
        {
            $message = "Zimbra's users are disabled.";
            return new TestKo($this->getName(), $message);
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return "Enabled Zimbra's users test";
    }

    public function isZimbraDriveAuthenticationEnabled()
    {
        $isZimbraDriveAuthenticationEnabled = false;

        $userBackEnds = $this->config->getSystemValue(AdminApiController::USER_BACKEND_VAR_NAME, array());

        foreach($userBackEnds as $userBackEnd)
        {
            if($userBackEnd['class'] === AdminApiController::ZIMBRA_USER_BACKEND_CLASS_VALUE)
            {
                $isZimbraDriveAuthenticationEnabled = true;
            }
        }

        return $isZimbraDriveAuthenticationEnabled;
    }
}