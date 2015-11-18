<?php
namespace MV\SocialAuth\Controller;

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
 * AuthController
 */
class AuthController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController {

    public function listAction(){
        $extConfig = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['social_auth']);
        $providers = array();
        foreach($extConfig['providers.'] as $key => $params){
            if($params['enabled'] == 1)
                array_push($providers, rtrim($key, '.'));
        }
        $this->view->assign('providers', $providers);
    }

    public function connectAction(){
        if(!$this->request->getArguments('provider'))
            throw new \Exception('Provider is required', 1325691094);
        //redirect if login
        if($GLOBALS['TSFE']->loginUser && is_array($GLOBALS['TSFE']->fe_user->user)){
            $redirectionDelay = 0;
            $this->uriBuilder->setTargetPageUid((int) $GLOBALS['TSFE']->id);
            $redirectionUri = $this->uriBuilder->build();
            $this->redirectToUri($redirectionUri);
        }
        return FALSE;
    }

    public function endpointAction(){
        if (isset($_REQUEST['hauth_start']) || isset($_REQUEST['hauth_done']))
            \Hybrid_Endpoint::process();
    }


}