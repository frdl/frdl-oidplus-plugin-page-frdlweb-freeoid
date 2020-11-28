<<?php

/*
 * OIDplus 2.0
 * Copyright 2019 Daniel Marschall, ViaThinkSoft
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



class OIDplusPagePublicFreeOIDFrdlweb extends OIDplusPagePublicObjects {
	public function whoisObjectAttributes($id, &$out){
		
	}
	public function whoisRaAttributes($email, &$out){
		 
	}
	public static function getFreeRootOid($with_ns) {
		return ($with_ns ? 'oid:' : '').OIDplus::config()->getValue('freeweid_root_oid');
	}

	public function action($actionID, $params) {
		if (empty(self::getFreeRootOid(false))) throw new OIDplusException("Free WEID service not available. Please ask your administrator.");

		if ($actionID == 'request_freeweid') {
			$email = $params['email'];

			$res = OIDplus::db()->query("select * from ###ra where email = ?", array($email));
			if ($res->num_rows() > 0) {
				throw new OIDplusException('This email address already exists.'); // TODO: actually, the person might have something else (like a DOI) and want to have a FreeOID
			}

			if (!OIDplus::mailUtils()->validMailAddress($email)) {
				throw new OIDplusException('Invalid email address');
			}

			if (OIDplus::baseConfig()->getValue('RECAPTCHA_ENABLED', false)) {
				$secret=OIDplus::baseConfig()->getValue('RECAPTCHA_PRIVATE', '');
				$response=$params["captcha"];
				$verify=file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret={$secret}&response={$response}");
				$captcha_success=json_decode($verify);
				if ($captcha_success->success==false) {
					throw new OIDplusException('Captcha wrong');
				}
			}

			$root_oid = self::getFreeRootOid(false);
			OIDplus::logger()->log("[INFO]OID(oid:$root_oid)+RA($email)!", "Requested a free OID for email '$email' to be placed into root '$root_oid'");

			$timestamp = time();
			$activate_url = OIDplus::getSystemUrl() . '?goto='.urlencode('oidplus:com.frdlweb.freeoid.activate_freeweid$'.$email.'$'.$timestamp.'$'.OIDplus::authUtils()::makeAuthKey('com.frdlweb.freeoid.activate_freeweid;'.$email.';'.$timestamp));

			$message = file_get_contents(__DIR__ . '/request_msg.tpl');
			$message = str_replace('{{SYSTEM_URL}}', OIDplus::getSystemUrl(), $message);
			$message = str_replace('{{SYSTEM_TITLE}}', OIDplus::config()->getValue('system_title'), $message);
			$message = str_replace('{{ADMIN_EMAIL}}', OIDplus::config()->getValue('admin_email'), $message);
			$message = str_replace('{{ACTIVATE_URL}}', $activate_url, $message);
			OIDplus::mailUtils()->sendMail($email, OIDplus::config()->getValue('system_title').' - Free OID request', $message, OIDplus::config()->getValue('global_cc'));

			return array("status" => 0);

		} else if ($actionID == 'activate_freeweid') {

			$password1 = $params['password1'];
			$password2 = $params['password2'];
			$email = $params['email'];

			$ra_name = $params['ra_name'];
			$url = $params['url'];
			$title = $params['title'];

			$auth = $params['auth'];
			$timestamp = $params['timestamp'];

			if (!OIDplus::authUtils()::validateAuthKey('com.frdlweb.freeoid.activate_freeweid;'.$email.';'.$timestamp, $auth)) {
				throw new OIDplusException('Invalid auth key');
			}

			if ((OIDplus::config()->getValue('max_ra_invite_time') > 0) && (time()-$timestamp > OIDplus::config()->getValue('max_ra_invite_time'))) {
				throw new OIDplusException('Invitation expired!');
			}

			if ($password1 !== $password2) {
				throw new OIDplusException('Passwords are not equal');
			}

			if (strlen($password1) < OIDplus::config()->getValue('ra_min_password_length')) {
				throw new OIDplusException('Password is too short. Minimum password length: '.OIDplus::config()->getValue('ra_min_password_length'));
			}

			if (empty($ra_name)) {
				throw new OIDplusException('Please enter your personal name or the name of your group.');
			}

			// 1. step: Add the RA to the database

			$ra = new OIDplusRA($email);
			$ra->register_ra($password1);
			$ra->setRaName($ra_name);

			// 2. step: Add the new OID to the database

			$root_oid = self::getFreeRootOid(false);
			$new_oid = OIDplusOID::parse('oid:'.$root_oid)->appendArcs($this->freeoid_max_id()+1)->nodeId(false);

			OIDplus::logger()->log("[INFO]OID(oid:$root_oid)+OIDRA(oid:$root_oid)!", "Child OID '$new_oid' added automatically by '$email' (RA Name: '$ra_name')");
			OIDplus::logger()->log("[INFO]OID(oid:$new_oid)+[OK]RA($email)!",            "Free OID '$new_oid' activated (RA Name: '$ra_name')");

			if ((!empty($url)) && (substr($url, 0, 4) != 'http')) $url = 'http://'.$url;

			$description = ''; // '<p>'.htmlentities($ra_name).'</p>';
			if (!empty($url)) {
				$description .= '<p>More information at <a href="'.htmlentities($url).'">'.htmlentities($url).'</a></p>';
			}

			if (empty($title)) $title = $ra_name;

			try {
				if ('oid:'.$new_oid > OIDplus::baseConfig()->getValue('LIMITS_MAX_ID_LENGTH')) {
					throw new OIDplusException("The resulting object identifier '$new_oid' is too long (max allowed length ".(OIDplus::baseConfig()->getValue('LIMITS_MAX_ID_LENGTH')-strlen('oid:')).")");
				}

				OIDplus::db()->query("insert into ###objects (id, ra_email, parent, title, description, confidential, created) values (?, ?, ?, ?, ?, ?, ".OIDplus::db()->sqlDate().")", array('oid:'.$new_oid, $email, self::getFreeRootOid(true), $title, $description, true));
			} catch (Exception $e) {
				$ra->delete();
				throw $e;
			}

			// Send delegation report email to admin

			$message  = "OID delegation report\n";
			$message .= "\n";
			$message .= "OID: ".$new_oid."\n";;
			$message .= "\n";
			$message .= "RA Name: $ra_name\n";
			$message .= "RA eMail: $email\n";
			$message .= "URL for more information: $url\n";
			$message .= "OID Name: $title\n";
			$message .= "\n";
			$message .= "More details: ".OIDplus::getSystemUrl()."?goto=oid:$new_oid\n";

			OIDplus::mailUtils()->sendMail($email, OIDplus::config()->getValue('system_title')." - OID $new_oid registered", $message, OIDplus::config()->getValue('global_cc'));

			// Send delegation information to user

			$message = file_get_contents(__DIR__ . '/allocated_msg.tpl');
			$message = str_replace('{{SYSTEM_URL}}', OIDplus::getSystemUrl(), $message);
			$message = str_replace('{{SYSTEM_TITLE}}', OIDplus::config()->getValue('system_title'), $message);
			$message = str_replace('{{ADMIN_EMAIL}}', OIDplus::config()->getValue('admin_email'), $message);
			$message = str_replace('{{NEW_OID}}', $new_oid, $message);
			OIDplus::mailUtils()->sendMail($email, OIDplus::config()->getValue('system_title').' - Free OID allocated', $message, OIDplus::config()->getValue('global_cc'));

			return array("status" => 0);
		} else {
			throw new OIDplusException("Unknown action ID");
		}
	}

	public function init($html=true) {
		OIDplus::config()->prepareConfigKey('freeweid_root_oid', 'Root-OID of free WEID service (a service where visitors can create their own OID using email verification)', '', OIDplusConfig::PROTECTION_EDITABLE, function($value) {
			if (($value != '') && !oid_valid_dotnotation($value,false,false,1)) {
				throw new OIDplusException("Please enter a valid OID in dot notation or nothing");
			}
		});
		
		
			
	}
	
	
   public function modifyContent($id, &$title, &$icon, &$text){
	    $content = '';
	   $CRUD = '';
	 
	$id = explode('$',$id,2)[0];
	if (false!==strpos($id, 'weid:') ) {
	
	 try{
	   $obj = OIDplusObject::parse($id);
	 }catch(\Exception $e){
		$obj = false; 
	 }  
		 
		//die(print_r($obj,true).'<br />'.$id.'<br />'.$title.'<br />'.$text);
	   $weidObj = false;
	   
	  
	   
	 if(is_object($obj) && null !== $obj && 'weid' === $obj::ns() && (!$obj->isConfidential() || OIDplus::authUtils()::isAdminLoggedIn() )){  
	  if($id === $obj->nodeId(true) || $id === $obj->nodeId(false)){	   
	   $children =$obj->getChildren();
	  }else{
		  $weidObj = OIDPlusWeid::parse($id) ;
		  $children =(is_object($weidObj) && null !== $weidObj && (!$weidObj->isConfidential() && !OIDplus::authUtils()::isAdminLoggedIn() ))   ?  $weidObj->getChildren() :  [];
	  }

	   $CRUD =(is_object($weidObj) && null !== $weidObj && is_callable([$weidObj, 'renderChildren'])) ? $weidObj->renderChildren($children, '<h4>Children:</h4>') : '';
	  
	 }
	}  
	   $content =(false===strpos($content, '%%CRUD%%') ) ? $content.$CRUD :  str_replace('%%CRUD%%', \PHP_EOL.$CRUD.\PHP_EOL.'%%CRUD%%', $content);	   
	  
	   $handled = false;
	//  parent::gui($id, $content2, $handled);
	   $text.=$content;
	   
	   $title = (is_object($weidObj) && null !== $weidObj && is_callable([$weidObj, 'getTitle'])) ? $weidObj->getTitle() 
		   :  ((is_object($obj) && null !== $obj && is_callable([$obj, 'getTitle'])) ? $obj->getTitle() : $title);//'{ERROR_TITLE_'.__METHOD__.__LINE__) ;
   }
	
	//public function modifyContent($id, &$title, &$icon, &$text) {
	//	$obj = OIDplusObject::parse($id);
		
		//print_r($obj);
	//}
	
	public function implementsFeature($id) {
		if (strtolower($id) == '1.3.6.1.4.1.37476.2.5.2.3.2') return true; // modifyContent
		if (strtolower($id) == '1.3.6.1.4.1.37476.2.5.2.3.4') return true; // publicPages, whoisObjectAttributes($id, &$out),  whoisRaAttributes($email, &$out)
	//	if (strtolower($id) == '1.3.6.1.4.1.37476.2.5.2.3.3') return true; // beforeObject*, afterObject*
		return false;
	}
	

	public function gui($id, &$out, &$handled) {
		if (empty(self::getFreeRootOid(false))) return;

		if (explode('$',$id)[0] == 'com.frdlweb.freeweid') {
			$handled = true;

			$out['title'] = 'Register a free OID/WEID';
			$out['icon'] = file_exists(__DIR__.'/icon_big.png') ? OIDplus::webpath(__DIR__).'icon_big.png' : '';

			$highest_id = $this->freeoid_max_id();

			$out['text'] .= '<p>Currently <a '.OIDplus::gui()->link(self::getFreeRootOid(true)).'>'.$highest_id.' free OIDs have been</a> registered. Please enter your email below to receive a free OID.</p>';

			try {
				$out['text'] .= '
				  <form id="freeOIDForm" onsubmit="return freeWEIDFormOnSubmit();">
				    E-Mail: <input type="text" id="email" value=""/><br><br>'.
				 (OIDplus::baseConfig()->getValue('RECAPTCHA_ENABLED', false) ?
				 '<script> grecaptcha.render(document.getElementById("g-recaptcha"), { "sitekey" : "'.OIDplus::baseConfig()->getValue('RECAPTCHA_PUBLIC', '').'" }); </script>'.
				 '<div id="g-recaptcha" class="g-recaptcha" data-sitekey="'.OIDplus::baseConfig()->getValue('RECAPTCHA_PUBLIC', '').'"></div>' : '').
				' <br>
				    <input type="submit" value="Request free OID">
				  </form>';

				$obj = OIDplusOID::parse(self::getFreeRootOid(true));

				$tos = file_get_contents(__DIR__ . '/tos.html');
				$tos = str_replace('{{ADMIN_EMAIL}}', OIDplus::config()->getValue('admin_email'), $tos);
				if ($obj) {
					$tos = str_replace('{{ROOT_OID}}', $obj->getDotNotation(), $tos);
					$tos = str_replace('{{ROOT_OID_ASN1}}', $obj->getAsn1Notation(), $tos);
					$tos = str_replace('{{ROOT_OID_IRI}}', $obj->getIriNotation(), $tos);
				}
				$out['text'] .= $tos;
			} catch (Exception $e) {
				$out['text'] = "Error: ".$e->getMessage();
			}
		} else if (explode('$',$id)[0] == 'oidplus:com.frdlweb.freeoid.activate_freeweid') {
			$handled = true;

			$email = explode('$',$id)[1];
			$timestamp = explode('$',$id)[2];
			$auth = explode('$',$id)[3];

			$out['title'] = 'Activate Free OID/WEID';
			$out['icon'] = file_exists(__DIR__.'/icon_big.png') ? OIDplus::webpath(__DIR__).'icon_big.png' : '';

			$res = OIDplus::db()->query("select * from ###ra where email = ?", array($email));
			if ($res->num_rows() > 0) {
				$out['icon'] = 'img/error_big.png';
				$out['text'] = 'This RA is already registered.'; // TODO: actually, the person might have something else (like a DOI) and want to have a FreeOID
			} else {
				if (!OIDplus::authUtils()::validateAuthKey('com.frdlweb.freeoid.activate_freeweid;'.$email.';'.$timestamp, $auth)) {
					$out['icon'] = 'img/error_big.png';
					$out['text'] = 'Invalid authorization. Is the URL OK?';
				} else {
					$out['text'] = '<p>eMail-Address: <b>'.$email.'</b></p>

				  <form id="activateFreeOIDForm" onsubmit="return activateFreeWEIDFormOnSubmit();">
				    <input type="hidden" id="email" value="'.htmlentities($email).'"/>
				    <input type="hidden" id="timestamp" value="'.htmlentities($timestamp).'"/>
				    <input type="hidden" id="auth" value="'.htmlentities($auth).'"/>

				    Your personal name or the name of your group:<br><input type="text" id="ra_name" value=""/><br><br><!-- TODO: disable autocomplete -->
				    Title of your OID (usually equal to your name, optional):<br><input type="text" id="title" value=""/><br><br>
				    URL for more information about your project(s) (optional):<br><input type="text" id="url" value=""/><br><br>

				    <div><label class="padding_label">Password:</label><input type="password" id="password1" value=""/></div>
				    <div><label class="padding_label">Repeat:</label><input type="password" id="password2" value=""/></div>
				    <br><input type="submit" value="Register">
				  </form>';
				}
			}
		}
	}

	public function publicSitemap(&$out) {
		if (empty(self::getFreeRootOid(false))) return;
		$out[] = OIDplus::getSystemUrl().'?goto='.urlencode('com.frdlweb.freeweid');
	}

	public function tree(&$json, $ra_email=null, $nonjs=false, $req_goto='') {
		if (empty(self::getFreeRootOid(false))) return false;

		if (file_exists(__DIR__.'/treeicon.png')) {
			$tree_icon = OIDplus::webpath(__DIR__).'treeicon.png';
		} else {
			$tree_icon = null; // default icon (folder)
		}

		$json[] = array(
			'id' => 'com.frdlweb.freeweid',
			'icon' => $tree_icon,
			'text' => 'Register a free WEID'
		);

		return true;
	}



	protected static function freeoid_max_id() {
		$res = OIDplus::db()->query("select id from ###objects where id like ? order by ".OIDplus::db()->natOrder('id'), array(self::getFreeRootOid(true).'.%'));
		$highest_id = 0;
		while ($row = $res->fetch_array()) {
			$arc = substr_count(self::getFreeRootOid(false), '.')+1;
			$highest_id = explode('.',$row['id'])[$arc];
		}
		return $highest_id;
	}

	public function tree_search($request) {
		$ary = array();
		if ($obj = OIDplusObject::parse($request)) {
			if ($obj->userHasReadRights()) {
				do {
					$ary[] = $obj->nodeId();
				} while ($obj = $obj->getParent());
				$ary = array_reverse($ary);
			}
		}
		return $ary;
	}
	
}
