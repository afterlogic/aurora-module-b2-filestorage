<?php
/**
 * @copyright Copyright (c) 2017, Afterlogic Corp.
 * @license AGPL-3.0 or AfterLogic Software License
 *
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\B2Filestorage;

use ChrisWhite\B2\Client;

/**
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected static $sStorageType = 'b2';
	protected $oClient = null;
	protected $aRequireModules = [];
	
	protected function issetScope($sScope)
	{
		return in_array($sScope, explode(' ', $this->getConfig('Scopes')));
	}	
	
	/***** private functions *****/
	/**
	 * Initializes DropBox Module.
	 * 
	 * @ignore
	 */
	public function init() 
	{
		$this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
		$this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
		$this->subscribeEvent('Files::GetFiles::after', array($this, 'onAfterGetFiles'));
		$this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
		$this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
		$this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
		$this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
		$this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
		$this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy')); 
		$this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'));
		$this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));
		#$this->subscribeEvent('Dropbox::GetSettings', array($this, 'onGetSettings'));
		#$this->subscribeEvent('Dropbox::UpdateSettings::after', array($this, 'onAfterUpdateSettings'));
		
		$this->subscribeEvent('Files::GetFiles::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::UploadFile::before', array($this, 'CheckUrlFile'));
		$this->subscribeEvent('Files::CreateFolder::before', array($this, 'CheckUrlFile'));
	}
	
	/**
	 * Obtains DropBox client if passed $sType is DropBox account type.
	 * 
	 * @param string $sType Service type.
	 */
	protected function getClient()
	{
		
		#$oDropboxModule = \Aurora\System\Api::GetModule('Dropbox');
		#if ($oDropboxModule instanceof \Aurora\System\Module\AbstractModule)
		#{
		#	if (!$oDropboxModule->getConfig('EnableModule', false) || !$this->issetScope('storage'))
		#	{
		#		return false;
		#	}
		#}
		#else
		#{
		#	return false;
		#}
		
		if ($this->oClient === null)
		{
			#\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

			#$oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
			#$oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);
			#if ($oOAuthAccount)
			#{
			#	$oDropboxApp = new \Kunnu\Dropbox\DropboxApp(
			#		\Aurora\System\Api::GetModule('Dropbox')->getConfig('Id'),
			#		\Aurora\System\Api::GetModule('Dropbox')->getConfig('Secret'),
			#		$oOAuthAccount->AccessToken
			#	);
			#	$this->oClient = new \Kunnu\Dropbox\Dropbox($oDropboxApp);
			#}
            $this->oClient = new Client('f2dc53c2faa7', '00134a020d71bd5eef3e221cf57a63be1d8f0b4596');
		}
		
		return $this->oClient;
	}	
	
	/**
	 * Write to the $aResult variable information about DropBox storage.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetStorages($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		#$bEnableDropboxModule = false;
		#$oDropboxModule = \Aurora\System\Api::GetModule('Dropbox');
		#if ($oDropboxModule instanceof \Aurora\System\Module\AbstractModule)
		#{
		#	$bEnableDropboxModule = $oDropboxModule->getConfig('EnableModule', false);
		#}
		#else
		#{
		#	$bEnableDropboxModule = false;
		#}
		
		
		#$oOAuthIntegratorWebclientModule = \Aurora\Modules\OAuthIntegratorWebclient\Module::Decorator();
		#$oOAuthAccount = $oOAuthIntegratorWebclientModule->GetAccount(self::$sStorageType);

		#if ($oOAuthAccount instanceof \Aurora\Modules\OAuthIntegratorWebclient\Classes\Account &&
		#		$oOAuthAccount->Type === self::$sStorageType &&
		#			$this->issetScope('storage') && $oOAuthAccount->issetScope('storage'))
		#{
		#	$mResult[] = [
		#		'Type' => self::$sStorageType,
		#		'IsExternal' => true,
		#		'DisplayName' => 'Dropbox'
		#	];
		#}

        $mResult[] = [
            'Type' => self::$sStorageType,
            'IsExternal' => true,
            'DisplayName' => 'Backblaze'
        ];
	}
	
	/**
	 * Returns directory name for the specified path.
	 * 
	 * @param string $sPath Path to the file.
	 * @return string
	 */
	protected function getDirName($sPath)
	{
		$sPath = dirname($sPath);
		return str_replace(DIRECTORY_SEPARATOR, '/', $sPath); 
	}
	
	/**
	 * Returns base name for the specified path.
	 * 
	 * @param string $sPath Path to the file.
	 * @return string
	 */
	protected function getBaseName($sPath)
	{
		$aPath = explode('/', $sPath);
		return end($aPath); 
	}
	
	/**
	 * 
	 * @param type $oItem
	 * @return type
	 */
	public function getItemHash($oItem)
	{
		return \Aurora\System\Api::EncodeKeyValues(array(
			'UserId' => \Aurora\System\Api::getAuthenticatedUserId(), 
			'Type' => $oItem->TypeStr,
			'Path' => '',
			'Name' => $oItem->FullPath
		));			
	}	
	
	protected function hasThumb($sName)
	{
		return in_array(
			pathinfo($sName, PATHINFO_EXTENSION), 
			['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'bmp']
		);
	}

	/**
	 * Populates file info.
	 */
	protected function populateFileInfo($aData)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		$mResult = false;
		if ($aData)
		{
			$sPath = ltrim($this->getDirName($aData->getPathDisplay()), '/');
			
			$mResult /*@var $mResult \Aurora\Modules\Files\Classes\FileItem */ = new  \Aurora\Modules\Files\Classes\FileItem();
			$mResult->IsExternal = true;
			$mResult->TypeStr = self::$sStorageType;
			$mResult->IsFolder = false;//@TODO
			$mResult->Id = $aData->getName();
			$mResult->Name = $mResult->Id;
			$mResult->Path = !empty($sPath) ? '/'.$sPath : $sPath;
			$mResult->Size = !$mResult->IsFolder ? $aData->getSize() : 0;
//			$bResult->Owner = $oSocial->Name;
			if (!$mResult->IsFolder)
			{
				$mResult->LastModified =  date("U",strtotime($aData->getServerModified()));
			}
//			$mResult->Shared = isset($aData['shared']) ? $aData['shared'] : false;
			$mResult->FullPath = $mResult->Name !== '' ? $mResult->Path . '/' . $mResult->Name : $mResult->Path ;
			$mResult->ContentType = \Aurora\System\Utils::MimeContentType($mResult->Name);
			
			$mResult->Thumb = $this->hasThumb($mResult->Name);

			if ($mResult->IsFolder)
			{
				$mResult->AddAction([
					'list' => []
				]);
			}
			else
			{
				$mResult->AddAction([
					'view' => [
						'url' => '?download-file/' . $this->getItemHash($mResult) .'/view'
					]
				]);
				$mResult->AddAction([
					'download' => [
						'url' => '?download-file/' . $this->getItemHash($mResult)
					]
				]);
			}
		}
		return $mResult;
	}	
	

	public function onGetFile($aArgs, &$mResult)
	{
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$sFullPath = $aArgs['Path'] . '/'  .  ltrim($aArgs['Name'], '/');
				if (!isset($aArgs['IsThumb']))
				{
					$mDownloadResult = $oClient->download($sFullPath);
					if ($mDownloadResult)
					{
						$mResult = \fopen('php://memory','r+');
						\fwrite($mResult, $mDownloadResult->getContents());
						\rewind($mResult);
					}
				}
				else
				{
					$oThumbnail = $oClient->getThumbnail($sFullPath, 'medium', 'png');
					if ($oThumbnail)
					{
						$mResult = \fopen('php://memory','r+');
						\fwrite($mResult, $oThumbnail->getContents());
						\rewind($mResult);
					}
				}
			}
			
			return true;
		}
	}	
	
	/**
	 * Writes to $aData variable list of DropBox files if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData Is passed by reference.
	 */
	public function onAfterGetFiles($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$mResult['Items'] = array();
			$oClient = $this->getClient();
			if ($oClient)
			{
				$aItems = array();
				$Path = '/'.ltrim($aArgs['Path'], '/');
				if (empty($aArgs['Pattern']))
				{
                    $aItems = $oClient->listFiles([
                        'BucketId' => '5f529d9cf563dce26f0a0a17'
                    ]);//@TODO
				}
				else
				{
					$oListFolderContents = $oClient->search($Path, $aArgs['Pattern']);
					$oItems = $oListFolderContents->getItems();
					$aItems = $oItems->all();
				}

				foreach($aItems as $oChild) 
				{
					if ($oChild instanceof \Kunnu\Dropbox\Models\SearchResult)//@TODO
					{
						$oChild = $oChild->getMetadata();
					}
					$oItem /*@var $oItem \Aurora\Modules\Files\Classes\FileItem */ = $this->populateFileInfo($oChild);
					if ($oItem)
					{
						$mResult['Items'][] = $oItem;
					}
				}				
			}
			return true;
		}
	}	

	/**
	 * Creates folder
	 */
	public function onAfterCreateFolder($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;
				$sPath = $aArgs['Path'];
				
				if ($oClient->createFolder($sPath.'/'.$aArgs['FolderName']) !== null)
				{
					$mResult = true;
				}
			}
			return true;
		}
	}	

	/**
	 * Creates file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onCreateFile($aArgs, &$Result)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				$Path = $aArgs['Path'].'/'.$aArgs['Name'];
				$rData = $aArgs['Data'];
				if (!is_resource($aArgs['Data']))
				{
					$rData = fopen('php://memory','r+');
					fwrite($rData, $aArgs['Data']);
					rewind($rData);					
				}
				$oDropboxFile = \Kunnu\Dropbox\DropboxFile::createByStream($aArgs['Name'], $rData);
				if ($oClient->upload($oDropboxFile,	$Path))
				{
					$mResult = true;
				}
				
				return true;
			}
		}
	}	

	/**
	 * Deletes file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterDelete($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				foreach ($aArgs['Items'] as $aItem)
				{
					$oClient->delete($aItem['Path'].'/'.$aItem['Name']);
					$mResult = true;
				}
			}
			return true;
		}
	}	

	/**
	 * Renames file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterRename($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['Type'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				$sPath = $aArgs['Path'];
				if ($oClient->move($sPath.'/'.$aArgs['Name'], $sPath.'/'.$aArgs['NewName']))
				{
					$mResult = true;
				}
			}
			
			return true;
		}
	}	

	/**
	 * Moves file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterMove($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$oClient->move($aArgs['FromPath'].'/'.$aFile['Name'], $aArgs['ToPath'].'/'.$aFile['Name']);
					}
					$mResult = true;
				}
			}
			return true;
		}
	}	

	/**
	 * Copies file if $aData['Type'] is DropBox account type.
	 * 
	 * @ignore
	 * @param array $aData
	 */
	public function onAfterCopy($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		
		if ($aArgs['FromType'] === self::$sStorageType)
		{
			$oClient = $this->getClient();
			if ($oClient)
			{
				$mResult = false;

				if ($aArgs['ToType'] === $aArgs['FromType'])
				{
					foreach ($aArgs['Files'] as $aFile)
					{
						$oClient->copy($aArgs['FromPath'].'/'.$aFile['Name'], $aArgs['ToPath'].'/'.$aFile['Name']);
					}
					$mResult = true;
				}
			}
			return true;
		}
	}		
	
	protected function _getFileInfo($sPath, $sName)
	{
		$mResult = false;
		$oClient = $this->GetClient();
		if ($oClient)
		{
			$mResult = $oClient->getMetadata($sPath.'/'.$sName);
		}
		
		return $mResult;
	}


	/**
	 * @ignore
	 * @todo not used
	 * @param object $oAccount
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sName
	 * @param boolean $mResult
	 * @param boolean $bBreak
	 */
	public function onAfterGetFileInfo($aArgs, &$mResult)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);
		
		if (self::$sStorageType === $aArgs['Type'])
		{
			$mFileInfo = $this->_getFileInfo($aArgs['Path'], $aArgs['Id']);
			if ($mFileInfo)
			{
				$mResult = $this->PopulateFileInfo($mFileInfo);
			}
			return true;
		}		
	}	
	
	/**
	 * @ignore
	 * @todo not used
	 * @param object $oItem
	 * @return boolean
	 */
	public function onPopulateFileItem($aArgs, &$oItem)
	{
		if ($oItem->IsLink)
		{
			if (false !== strpos($oItem->LinkUrl, 'dl.dropboxusercontent.com') || 
					false !== strpos($oItem->LinkUrl, 'dropbox.com'))
			{
				$aMetadata = $this->getMetadataLink($oItem->LinkUrl);
				if (isset($aMetadata['path']) && $aMetadata['is_dir'])
				{
					$oItem->UnshiftAction(array(
						'list' => array()
					));

					$oItem->Thumb = true;
					$oItem->ThumbnailUrl = \MailSo\Base\Http::SingletonInstance()->GetFullUrl() . 'modules/' . $this->GetName() . '/images/dropbox.png';
				}
				$oItem->LinkType = 'dropbox';
				return true;
			}
		}
	}	
	
	public function getMetadataLink($sLink)
	{
		$oClient = $this->getClient();
        $response = $oClient->postToAPI(
            '/2/sharing/get_shared_link_metadata', 
			array(
				'url' => $sLink
			)
		);

        if ($response->getHttpStatusCode() === 404) return null;
        if ($response->getHttpStatusCode() !== 200) return null;

        $metadata = $response->getDecodedBody();
        if (array_key_exists("is_deleted", $metadata) && $metadata["is_deleted"]) return null;
        return $metadata;
	}
	
	public function CheckUrlFile(&$aArgs, &$mResult)
	{
		if (strpos($aArgs['Path'], '.url') !== false)
		{
			list($sUrl, $sPath) = explode('.url', $aArgs['Path']);
			$sUrl .= '.url';
			$aArgs['Path'] = $sUrl;
			$this->prepareArgs($aArgs);
			if ($sPath)
			{
				$aArgs['Path'] .= $sPath;
			}
		}
	}

	protected function prepareArgs(&$aData)
	{
		$aPathInfo = pathinfo($aData['Path']);
		$sExtension = isset($aPathInfo['extension']) ? $aPathInfo['extension'] : '';
		if ($sExtension === 'url')
		{
			$aArgs = array(
				'UserId' => $aData['UserId'],
				'Type' => $aData['Type'],
				'Path' => $aPathInfo['dirname'],
				'Name' => $aPathInfo['basename'],
				'IsThumb' => false
			);
			$mResult = false;
			\Aurora\System\Api::GetModuleManager()->broadcastEvent(
				'Files',
				'GetFile', 
				$aArgs,
				$mResult
			);	
			if (is_resource($mResult))
			{
				$aUrlFileInfo = \Aurora\System\Utils::parseIniString(stream_get_contents($mResult));
				if ($aUrlFileInfo && isset($aUrlFileInfo['URL']))
				{
					if (false !== strpos($aUrlFileInfo['URL'], 'dl.dropboxusercontent.com') || 
						false !== strpos($aUrlFileInfo['URL'], 'dropbox.com'))
					{
						$aData['Type'] = 'dropbox';
						$aMetadata = $this->getMetadataLink($aUrlFileInfo['URL']);
						if (isset($aMetadata['path']))
						{
							$aData['Path'] = $aMetadata['path'];
						}
					}
				}
			}		
		}
	}	
	/***** private functions *****/
	
	/**
	 * Passes data to connect to service.
	 * 
	 * @ignore
	 * @param string $aArgs Service type to verify if data should be passed.
	 * @param boolean|array $mResult variable passed by reference to take the result.
	 */
	public function onGetSettings($aArgs, &$mResult)
	{
		$oUser = \Aurora\System\Api::getAuthenticatedUser();
		
		if (!empty($oUser))
		{
			$aScope = array(
				'Name' => 'storage',
				'Description' => $this->i18N('SCOPE_FILESTORAGE'),
				'Value' => false
			);
			if ($oUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin)
			{
				$aScope['Value'] = $this->issetScope('storage');
				$mResult['Scopes'][] = $aScope;
			}
			if ($oUser->Role === \Aurora\System\Enums\UserRole::NormalUser)
			{
				if ($aArgs['OAuthAccount'] instanceof \Aurora\Modules\OAuthIntegratorWebclient\Classes\Account)
				{
					$aScope['Value'] = $aArgs['OAuthAccount']->issetScope('storage');
				}
				if ($this->issetScope('storage'))
				{
					$mResult['Scopes'][] = $aScope;
				}
			}
		}	
	}
	
	public function onAfterUpdateSettings($aArgs, &$mResult)
	{
		$sScope = '';
		if (isset($aArgs['Scopes']) && is_array($aArgs['Scopes']))
		{
			foreach($aArgs['Scopes'] as $aScope)
			{
				if ($aScope['Name'] === 'storage')
				{
					if ($aScope['Value'])
					{
						$sScope = 'storage';
						break;
					}
				}
			}
		}
		$this->setConfig('Scopes', $sScope);
		$this->saveModuleConfig();
	}
}
