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

use Aurora\Modules\Files\Classes\FileItem;
use Aurora\System\Enums\LogLevel;
use Aurora\System\Managers\Filecache;
use ChrisWhite\B2\Exceptions\NotFoundException;
use ChrisWhite\B2\File;

/**
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $sStorageType = 'personal';//Must be personal

    /**
     *
     * @var \CApiFilesManager
     */
    public $oApiFilesManager = null;

    /**
     *
     * @var \CApiModuleDecorator
     */
    protected $oMinModuleDecorator = null;

    /**
     * @var Client
     */
    protected $b2Client = null;

    protected $b2BucketId = '';

    /***** private functions *****/

    /**
     * Always must be used instead of direct property access
     *
     * @return Client
     */
    public function getB2Client()
    {
        if (empty($this->b2Client)) {
            $this->b2BucketId = $this->getConfig('B2BucketId');
            $this->b2Client = new Client($this->getConfig('B2AccountId'), $this->getConfig('B2AppKey'));
        }
        return $this->b2Client;
    }

    /**
     * Initializes Files Module.
     *
     * @ignore
     */
    public function init()
    {
        ini_set( 'default_charset', 'UTF-8' ); //support for cyrillic characters in file names
        $this->oApiFilesManager = new \Aurora\Modules\PersonalFiles\Manager($this);


        $this->subscribeEvent('Files::GetFile', array($this, 'onGetFile'));
        $this->subscribeEvent('Files::CreateFile', array($this, 'onCreateFile'));
        $this->subscribeEvent('Files::GetLinkType', array($this, 'onGetLinkType'));
        $this->subscribeEvent('Files::CheckUrl', array($this, 'onCheckUrl'));

        $this->subscribeEvent('Files::GetStorages::after', array($this, 'onAfterGetStorages'));
        $this->subscribeEvent('Files::GetFileInfo::after', array($this, 'onAfterGetFileInfo'), 10);
        $this->subscribeEvent('Files::GetFiles::after', array($this, 'onAfterGetFiles'));
        $this->subscribeEvent('Files::GetFilesForUpload::after', [$this, 'onAfterGetFilesForUpload']);

        $this->subscribeEvent('Files::CreateFolder::after', array($this, 'onAfterCreateFolder'));
        $this->subscribeEvent('Files::Copy::after', array($this, 'onAfterCopy'));
        $this->subscribeEvent('Files::Move::after', array($this, 'onAfterMove'));
        $this->subscribeEvent('Files::Rename::after', array($this, 'onAfterRename'));
        $this->subscribeEvent('Files::Delete::before', array($this, 'onBeforeDelete'));
        $this->subscribeEvent('Files::Delete::after', array($this, 'onAfterDelete'));
        $this->subscribeEvent('Files::GetQuota::after', array($this, 'onAfterGetQuota'));
        $this->subscribeEvent('Files::GetPublicFiles::after', array($this, 'onAfterGetPublicFiles'));
        $this->subscribeEvent('Files::CreateLink::after', array($this, 'onAfterCreateLink'));
        $this->subscribeEvent('Files::CreatePublicLink::after', array($this, 'onAfterCreatePublicLink'));
        $this->subscribeEvent('Files::GetFileContent::after', array($this, 'onAfterGetFileContent'));
        $this->subscribeEvent('Files::IsFileExists::after', array($this, 'onAfterIsFileExists'));
        $this->subscribeEvent('Files::DeletePublicLink::after', array($this, 'onAfterDeletePublicLink'));

        $this->subscribeEvent('Files::PopulateFileItem', array($this, 'onPopulateFileItem'));

        $this->subscribeEvent('Core::AfterDeleteUser', array($this, 'onAfterDeleteUser'));
    }

    /**
     * Returns Min module decorator.
     *
     * @return \CApiModuleDecorator
     */
    private function getMinModuleDecorator()
    {
        return \Aurora\System\Api::GetModuleDecorator('Min');
    }



    /**
     * Checks if storage type is personal or corporate.
     *
     * @param string $Type Storage type.
     * @return bool
     */
    protected function checkStorageType($Type)
    {
        return $Type === static::$sStorageType;
    }

    /**
     * Returns file contents.
     *
     * @ignore
     * @param int $UserId User identifier.
     * @param string $Type Type of storage.
     * @param string $Path Path to folder files are obtained from.
     * @param string $Id Name of file.
     * @param bool $IsThumb Inticates if thumb is required.
     * @param string|resource|bool $Result Is passed by reference.
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onGetFile($aArgs, &$Result)
    {
        if ($this->checkStorageType($aArgs['Type']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
            $iOffset = isset($aArgs['Offset']) ? $aArgs['Offset'] : 0;
            $iChunkSizet = isset($aArgs['ChunkSize']) ? $aArgs['ChunkSize'] : 0;


            ###DEBUG_START
            //Do nothing if thumb is cached
            if ($aArgs['IsThumb']) {
                $sHash = (string) \Aurora\System\Application::GetPathItemByIndex(1, '');
                if (empty($sHash))
                {
                    $sHash = \rand(1000, 9999);
                }
                $sMd5Hash = \md5($sHash);

                $oApiFileCache = new Filecache();

                if ($oApiFileCache->isFileExists($aArgs['UserId'], 'Raw/Thumb/'.$sMd5Hash, '_' . $aArgs['Id'], 'System')) {
                    $Result = \fopen('php://memory','r+');
                    return true;
                }
            }

            ###DEBUG_END

            //Read metadata from local FS
            $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id'], $iOffset, $iChunkSizet);


            try {
                if (is_resource($metaFile))
                {
                    //Parse metadata
                    $metadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);


                    if (!empty($metadata['id'])) {

                        //Download original file content to temp file
                        $response = $this->getB2Client()->download([
                            'FileId' => $metadata['id']
                        ]);

                        $Result = \fopen('php://memory','r+');
                        \fwrite($Result, $response);
                        \rewind($Result);
                    }
                }
            } catch (\Exception $e) {
                \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                throw new \Exception('Cloud storage error');
            }

            return true;
        }
    }

    /**
     * Create file.
     *
     * @ignore
     * @param int $UserId User identifier.
     * @param string $Type Type of storage.
     * @param string $Path Path to folder files are obtained from.
     * @param string $Name Name of file.
     * @param string|resource $Data Data to be stored in the file.
     * @param string|resource|bool $Result Is passed by reference.
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onCreateFile($aArgs, &$Result)
    {
        if ($this->checkStorageType($aArgs['Type']))
        {

            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById(isset($aArgs['UserId']) ? $aArgs['UserId'] : null);

            try {
                $b2FileName = self::b2Filename($sUserPublicId, $aArgs['Path'], $aArgs['Name']);

                /* @var $b2File File */
                $b2File = $this->getB2Client()->upload([
                    'FileName' => $b2FileName,
                    'BucketId' => $this->b2BucketId,
                    'Body' => $aArgs['Data']
                ]);


                $metadata = json_encode([
                    'id' => $b2File->getId(),
                    'name' => $b2FileName,
                    'size' => $b2File->getSize()
                ]);
            } catch (\Exception $e) {
                \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                throw new \Exception('Cloud storage error');
            }


            $Result = $this->oApiFilesManager->createFile(
                $sUserPublicId,
                isset($aArgs['Type']) ? $aArgs['Type'] : null,
                isset($aArgs['Path']) ? $aArgs['Path'] : null,
                isset($aArgs['Name']) ? $aArgs['Name'] : null,
                $metadata,
                isset($aArgs['Overwrite']) ? $aArgs['Overwrite'] : null,
                isset($aArgs['RangeType']) ? $aArgs['RangeType'] : null,
                isset($aArgs['Offset']) ? $aArgs['Offset'] : null,
                isset($aArgs['ExtendedProps']) ? $aArgs['ExtendedProps'] : null
            );

            return true;
        }
    }

    public static function b2Filename($sUserPublicId, $path, $name)
    {
        $b2FileName =  $sUserPublicId  . '/' . md5($path . DIRECTORY_SEPARATOR . $name);

        return $b2FileName;
    }


    /**
     * @ignore
     * @param string $Link
     * @param string $Result
     */
    public function onGetLinkType($Link, &$Result)
    {
        $Result = '';
    }

    /**
     * @ignore
     * @param string $sUrl
     * @param mixed $mResult
     */
    public function onCheckUrl($aArgs, &$mResult)
    {
        $iUserId = \Aurora\System\Api::getAuthenticatedUserId();

        if ($iUserId)
        {
            if (!empty($aArgs['Url']))
            {
//				$sUrl = \Aurora\System\Utils::GetRemoteFileRealUrl($aArgs['Url']);
                $sUrl = $aArgs['Url'];
                if ($sUrl)
                {
                    $aRemoteFileInfo = \Aurora\System\Utils::GetRemoteFileInfo($sUrl);
                    $sFileName = basename($sUrl);
                    $sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);

                    if (empty($sFileExtension))
                    {
                        $sFileExtension = \Aurora\System\Utils::GetFileExtensionFromMimeContentType($aRemoteFileInfo['content-type']);
                        $sFileName .= '.'.$sFileExtension;
                    }

                    if ($sFileExtension === 'htm' || $sFileExtension === 'html')
                    {
                        $sTitle = $this->getHtmlTitle($sUrl);
                    }

                    $mResult['Name'] = isset($sTitle) && strlen($sTitle)> 0 ? $sTitle : urldecode($sFileName);
                    $mResult['Size'] = $aRemoteFileInfo['size'];
                }
            }
        }
    }

    /**
     * @ignore
     * @param \Aurora\Modules\Files\Classes\FileItem $oItem
     * @return bool
     */
    public function onPopulateFileItem($aArgs, &$oItem)
    {
        if ($oItem->IsLink)
        {
            $sFileName = basename($oItem->LinkUrl);
            $sFileExtension = \Aurora\System\Utils::GetFileExtension($sFileName);
            if ($sFileExtension === 'htm' || $sFileExtension === 'html')
            {
//				$oItem->Name = $this->getHtmlTitle($oItem->LinkUrl);
                return true;
            }
        }
    }

    /**
     * @ignore
     * @param int $iUserId
     */
    public function onAfterDeleteUser($aArgs)
    {
        $this->oApiFilesManager->ClearFiles($aArgs['UUID']);
    }
    /***** private functions *****/

    /**
     * @api {post} ?/Api/ GetStorages
     * @apiDescription Returns storages available for logged in user.
     * @apiName GetStorages
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=GetStorages} Method Method name
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'GetStorages'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {mixed} Result.Result List of storages in case of success, otherwise **false**.
     * @apiSuccess {string} Result.Result.Type Storage type - personal, corporate.
     * @apiSuccess {string} Result.Result.DisplayName Storage display name.
     * @apiSuccess {bool} Result.Result.IsExternal Indicates if storage external or not.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'GetStorages',
     *	Result: [{ Type: "personal", DisplayName: "Personal", IsExternal: false },
     *		{ Type: "corporate", DisplayName: "Corporate", IsExternal: false },
     *		{ Type: "google", IsExternal: true, DisplayName: "GoogleDrive" }]
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'GetStorages',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    public function onAfterGetStorages($aArgs, &$mResult)
    {
        array_unshift($mResult, [
            'Type' => static::$sStorageType,
            'DisplayName' => $this->i18N('LABEL_STORAGE'),
            'IsExternal' => false
        ]);
    }

    public function onAfterGetFiles($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if ($this->checkStorageType($aArgs['Type']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);

            $aFiles = $this->oApiFilesManager->getFiles($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Pattern']);

            foreach($aFiles as &$fileInfo) {
                /* @var $fileInfo FileItem */
                if (!$fileInfo->IsFolder) {
                    //Read metadata from local FS
                    $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $fileInfo->Name);

                    if (is_resource($metaFile)) {
                        //Parse metadata
                        $metadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);

                        if (!empty($metadata['size'])) {

                            //Override file size
                            $fileInfo->Size = $metadata['size'];
                        }
                    }
                }
            }

            $mResult = array(
                'Items' => $aFiles,
                'Quota' => \Aurora\System\Api::GetModuleDecorator('Files')->GetQuota($aArgs['UserId'])
            );
            return true;
        }
    }

    /**
     * Return real file size
     *
     * @param $aArgs
     * @param $mResult
     */
    public function onAfterGetFilesForUpload($aArgs, &$mResult)
    {
        $aHashes = isset($aArgs['Hashes']) ? $aArgs['Hashes'] : [];

        foreach ($aHashes as $key => $sHash) {
            $aValues = \Aurora\System\Api::DecodeKeyValues($sHash);
            $iUserId = isset($aValues['UserId']) ? (int)$aValues['UserId'] : 0;
            $sType = isset($aValues['Type']) ? $aValues['Type'] : '';
            $sPath = isset($aValues['Path']) ? $aValues['Path'] : '';
            $sFileName = isset($aValues['Name']) ? $aValues['Name'] : '';

            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($iUserId);
            $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $sType, $sPath, $sFileName);
            if (is_resource($metaFile)) {
                $aMetadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);
                $mResult[$key]['Size'] = $aMetadata['size'];
            }
        }
    }

    /**
     * Return content of a file.
     *
     * @param int $UserId
     * @param string $Type
     * @param string $Path
     * @param string $Name
     */
    public function onAfterGetFileContent($aArgs, &$mResult)
    {
        $UserId = $aArgs['UserId'];
        $Type = $aArgs['Type'];
        $Path = $aArgs['Path'];
        $Name = $aArgs['Name'];

        $mFile = $this->oApiFilesManager->getFile($UserId, $Type, $Path, $Name);
        if (is_resource($mFile))
        {
            $mResult = stream_get_contents($mFile);
        }
    }

    public function onAfterGetFileInfo($aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
            $mResult = $this->oApiFilesManager->getFileInfo($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Id']);
            return true;
        }
    }

    /**
     * @api {post} ?/Api/ GetPublicFiles
     * @apiDescription Returns list of public files.
     * @apiName GetPublicFiles
     * @apiGroup Files
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=GetPublicFiles} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Hash** *string* Hash to identify the list of files to return. Containes information about user identifier, type of storage, path to public folder, name of public folder.<br>
     * &emsp; **Path** *string* Path to folder contained files to return.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'GetPublicFiles',
     *	Parameters: '{ Hash: "hash_value", Path: "" }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {mixed} Result.Result Object in case of success, otherwise **false**.
     * @apiSuccess {array} Result.Result.Items Array of files objects.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'GetPublicFiles',
     *	Result: { Items: [{ Id: "image.png", Type: "personal", Path: "/shared_folder",
     * FullPath: "/shared_folder/image.png", Name: "image.png", Size: 43549, IsFolder: false,
     * IsLink: false, LinkType: "", LinkUrl: "", LastModified: 1475500277, ContentType: "image/png",
     * Thumb: true, ThumbnailLink: "", OembedHtml: "", Shared: false, Owner: "62a6d548-892e-11e6-be21-0cc47a041d39",
     * Content: "", IsExternal: false }] }
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'GetPublicFiles',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    /**
     * Returns list of public files.
     *
     * @param string $Hash Hash to identify the list of files to return. Containes information about user identifier, type of storage, path to public folder, name of public folder.
     * @param string $Path Path to folder contained files to return.
     * @return array {
     *		*array* **Items** Array of files objects.
     *		*array* **Quota** Array of items with fields Used, Limit.
     * }
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onAfterGetPublicFiles($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::Anonymous);

        $iUserId = null;

        $oMinDecorator =  $this->getMinModuleDecorator();
        if ($oMinDecorator)
        {
            $mMin = $oMinDecorator->GetMinByHash($aArgs['Hash']);
            if (!empty($mMin['__hash__']))
            {
                $iUserId = $mMin['UserId'];
                if ($iUserId)
                {
                    $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($iUserId);
                    $aItems = array();
                    $sMinPath = implode('/', array($mMin['Path'], $mMin['Name']));
                    $Path = $aArgs['Path'];
                    $mPos = strpos($Path, $sMinPath);
                    if ($mPos === 0 || $Path === '')
                    {
                        if ($mPos !== 0)
                        {
                            $Path =  $sMinPath . $Path;
                        }
                        $Path = str_replace('.', '', $Path);
                        try
                        {
                            $aItems = $this->oApiFilesManager->getFiles($sUserPublicId, $mMin['Type'], $Path, '', $aArgs['Hash']);
                        }
                        catch (\Exception $oEx)
                        {
                            $aItems = array();
                        }
                    }
                    $mResult['Items'] = $aItems;

//					$oResult['Quota'] = $this->GetQuota($iUserId);
                }
            }
        }
    }

    /**
     * @api {post} ?/Api/ CreateFolder
     * @apiDescription Creates folder.
     * @apiName CreateFolder
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=CreateFolder} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage - personal, corporate.<br>
     * &emsp; **Path** *string* Path to new folder.<br>
     * &emsp; **FolderName** *string* New folder name.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateFolder',
     *	Parameters: '{ Type: "personal", Path: "", FolderName: "new_folder" }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicates if folder was created successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateFolder',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateFolder',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    public function onAfterCreateFolder(&$aArgs, &$mResult)
    {
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
        if ($this->checkStorageType($aArgs['Type']))
        {
            $mResult = $this->oApiFilesManager->createFolder($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['FolderName']);
            return true;
        }
    }

    /**
     * @api {post} ?/Api/ CreateLink
     * @apiDescription Creates link.
     * @apiName CreateLink
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=CreateLink} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage - personal, corporate.<br>
     * &emsp; **Path** *string* Path to new link.<br>
     * &emsp; **Link** *string* Link value.<br>
     * &emsp; **Name** *string* Link name.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateLink',
     *	Parameters: '{ Type: "personal", Path: "", Link: "link_value", Name: "name_value" }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {mixed} Result.Result Link object in case of success, otherwise **false**.
     * @apiSuccess {string} Result.Result.Type Type of storage.
     * @apiSuccess {string} Result.Result.Path Path to link.
     * @apiSuccess {string} Result.Result.Link Link URL.
     * @apiSuccess {string} Result.Result.Name Link name.
     * @apiSuccess {int} [Result.ErrorCode] Error code.
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateLink',
     *	Result: { Type: "personal", Path: "", Link: "https://www.youtube.com/watch?v=1WPn4NdQnlg&t=1124s",
     *		Name: "Endless Numbers counting 90 to 100 - Learn 123 Numbers for Kids" }
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreateLink',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    /**
     * Creates link.
     *
     * @param int $UserId User identifier.
     * @param string $Type Type of storage - personal, corporate.
     * @param string $Path Path to new link.
     * @param string $Link Link value.
     * @param string $Name Link name.
     * @return bool
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onAfterCreateLink($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $Type = $aArgs['Type'];
        $UserId = $aArgs['UserId'];
        $Path = $aArgs['Path'];
        $Name = $aArgs['Name'];
        $Link = $aArgs['Link'];

        if (substr($Link, 0, 11) === 'javascript:')
        {
            $Link = substr($Link, 11);
        }

        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($UserId);
        if ($this->checkStorageType($Type))
        {
            $Name = \trim(\MailSo\Base\Utils::ClearFileName($Name));
            $mResult = $this->oApiFilesManager->createLink($sUserPublicId, $Type, $Path, $Link, $Name);
        }
    }

    /**
     * @api {post} ?/Api/ Delete
     * @apiDescription Deletes files and folder specified with list.
     * @apiName Delete
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=Delete} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage - personal, corporate.<br>
     * &emsp; **Items** *array* Array of items to delete.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'Delete',
     *	Parameters: '{ Type: "personal", Items: [{ "Path": "", "Name": "2.png" },
     *		{ "Path": "", "Name": "logo.png" }] }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicates if files and (or) folders were deleted successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'Delete',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'Delete',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    public function onAfterDelete(&$aArgs, &$mResult)
    {
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
        if ($this->checkStorageType($aArgs['Type']))
        {
            $mResult = false;

            foreach ($aArgs['Items'] as $oItem)
            {
                if (!empty($oItem['Name']))
                {
                    $mResult = $this->oApiFilesManager->delete($sUserPublicId, $aArgs['Type'], $oItem['Path'], $oItem['Name']);
                    if (!$mResult)
                    {
                        break;
                    }
                }
            }
            return true;
        }
    }

    /**
     * Removes file at B2 side before metafile is deleted from local FS
     *
     * @param $aArgs
     * @param $mResult
     * @return bool
     */
    public function onBeforeDelete(&$aArgs, &$mResult)
    {
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
        $sUUID = \Aurora\System\Api::getUserUUIDById($aArgs['UserId']);

        if ($this->checkStorageType($aArgs['Type']))
        {
            $mResult = false;

            foreach ($aArgs['Items'] as $oItem)
            {
                if (!empty($oItem['Name']))
                {
                    /* @var $fileInfo FileItem */
                    $fileInfo = $this->oApiFilesManager->getFileInfo($sUserPublicId, $aArgs['Type'], $oItem['Path'], $oItem['Name']);

                    if ($fileInfo->IsFolder) {
                        $directory = $this->oApiFilesManager->oStorage->getDirectory($sUserPublicId, $aArgs['Type'], $fileInfo->FullPath);
                        $files = $directory->getFileListRecursive();
                        try {
                            foreach($files as $file) {
                                /* @var $file \SplFileInfo */
                                $metadata = json_decode(file_get_contents($file->getRealPath()), JSON_OBJECT_AS_ARRAY);

                                if (!empty($metadata['id']) && !empty($metadata['name'])) {

                                    $this->getB2Client()->deleteFile([
                                        'BucketId' => $this->b2BucketId,
                                        'FileId' => $metadata['id'],
                                        'FileName' => $metadata['name']
                                    ]);
                                }
                            }
                        } catch (NotFoundException $nfe) {
                            \Aurora\System\Api::Log($nfe->getMessage(), LogLevel::Error, 'b2');
                        } catch (\Exception $e) {
                            \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                            throw new \Exception('Cloud storage error');
                        }

                    } else {
                        //Read metadata from local FS
                        $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $aArgs['Type'], $oItem['Path'], $oItem['Name']);

                        try {
                            if (is_resource($metaFile)) {
                                //Parse metadata
                                $metadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);

                                if (!empty($metadata['id']) && !empty($metadata['name'])) {
                                    $this->getB2Client()->deleteFile([
                                        'BucketId' => $this->b2BucketId,
                                        'FileId' => $metadata['id'],
                                        'FileName' => $metadata['name']
                                    ]);
                                }
                            }
                        } catch (NotFoundException $nfe) {
                            \Aurora\System\Api::Log($nfe->getMessage(), LogLevel::Error, 'b2');
                        } catch (\Exception $e) {
                            \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                            throw new \Exception('Cloud storage error');
                        }
                    }
                }
            }
        }
    }

    /**
     * @api {post} ?/Api/ Rename
     * @apiDescription Renames folder, file or link.
     * @apiName Rename
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=Rename} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage - personal, corporate.<br>
     * &emsp; **Path** *string* Path to item to rename.<br>
     * &emsp; **Name** *string* Current name of the item.<br>
     * &emsp; **NewName** *string* New name of the item.<br>
     * &emsp; **IsLink** *bool* Indicates if the item is link or not.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'Rename',
     *	Parameters: '{ Type: "personal", Path: "", Name: "old_name.png", NewName: "new_name.png",
     *		IsLink: false }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicates if file or folder was renamed successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'Rename',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'Rename',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    public function onAfterRename(&$aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['Type']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
            $sNewName = \trim(\MailSo\Base\Utils::ClearFileName($aArgs['NewName']));

            $sNewName = $this->oApiFilesManager->getNonExistentFileName($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $sNewName);
            $mResult = $this->oApiFilesManager->rename($sUserPublicId, $aArgs['Type'], $aArgs['Path'], $aArgs['Name'], $sNewName, $aArgs['IsLink']);

            return true;
        }
    }

    /**
     * @api {post} ?/Api/ Copy
     * @apiDescription Copies files and/or folders from one folder to another.
     * @apiName Copy
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=Copy} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **FromType** *string* Storage type of folder items will be copied from.<br>
     * &emsp; **ToType** *string* Storage type of folder items will be copied to.<br>
     * &emsp; **FromPath** *string* Folder items will be copied from.<br>
     * &emsp; **ToPath** *string* Folder items will be copied to.<br>
     * &emsp; **Files** *array* List of items to copy<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'Copy',
     *	Parameters: '{ FromType: "personal", ToType: "corporate", FromPath: "", ToPath: "",
     * Files: [{ Name: "logo.png", IsFolder: false }, { Name: "details.png", IsFolder: false }] }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicates if files and (or) folders were copied successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'Copy',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'Copy',
     *		Result: false,
     *		ErrorCode: 102
     *	}]
     * }
     */
    public function onAfterCopy(&$aArgs, &$mResult)
    {
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);

        if ($this->checkStorageType($aArgs['FromType']))
        {
            foreach ($aArgs['Files'] as $aItem)
            {
                $bFolderIntoItself = $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
                if (!$bFolderIntoItself)
                {

                    if ($aItem['IsFolder']) {
                        //Copy meta files on local FS
                        $mResult = $this->oApiFilesManager->copy(
                            $sUserPublicId,
                            $aItem['FromType'],
                            $aArgs['ToType'],
                            $aItem['FromPath'],
                            $aArgs['ToPath'],
                            $aItem['Name'],
                            $this->oApiFilesManager->getNonExistentFileName(
                                $sUserPublicId,
                                $aArgs['ToType'],
                                $aArgs['ToPath'],
                                $aItem['Name']
                            )
                        );

                        try {
                            //Create duplicates at B2
                            $directory = $this->oApiFilesManager->oStorage->getDirectory($sUserPublicId, $aItem['FromType'], $aArgs['ToPath'] . DIRECTORY_SEPARATOR . $aItem['Name']);
                            $copiedFiles = $directory->getFileListRecursive();

                            foreach ($copiedFiles as $copiedFile) {
                                /* @var $copiedFile \SplFileInfo */
                                $metadata = json_decode(file_get_contents($copiedFile->getRealPath()), JSON_OBJECT_AS_ARRAY);

                                $tempFileName = tempnam('/tmp', uniqid('copy_') . md5($sUserPublicId . $copiedFile->getFilename()));
                                if (!empty($metadata['id'])) {

                                    //Download original file content to temp file
                                    if ($this->getB2Client()->download([
                                        'FileId' => $metadata['id'],
                                        'SaveAs' => $tempFileName
                                    ])) {

                                        $fileNewName = $this->oApiFilesManager->getNonExistentFileName(
                                            $sUserPublicId,
                                            $aArgs['ToType'],
                                            $aArgs['ToPath'],
                                            $copiedFile->getFilename()
                                        );

                                        $b2FileName = self::b2Filename($sUserPublicId, $aArgs['ToPath'] . DIRECTORY_SEPARATOR . $aItem['Name'], $fileNewName);

                                        /* @var $b2File File */
                                        $b2File = $this->getB2Client()->upload([
                                            'FileName' => $b2FileName,
                                            'BucketId' => $this->b2BucketId,
                                            'Body' => fopen($tempFileName, 'r')
                                        ]);
                                        unset($tempFileName);


                                        $metadata = json_encode([
                                            'id' => $b2File->getId(),
                                            'name' => $b2FileName,
                                            'size' => $b2File->getSize()
                                        ]);

                                        file_put_contents($copiedFile->getRealPath(), $metadata);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                            throw new \Exception('Cloud storage error');
                        }

                    } else {
                        //Read metadata from local FS
                        $metaFile = $this->oApiFilesManager->getFile($sUserPublicId, $aArgs['FromType'], $aItem['FromPath'], $aItem['Name']);

                        try {
                            if (is_resource($metaFile))
                            {
                                //Parse metadata
                                $metadata = json_decode(stream_get_contents($metaFile), JSON_OBJECT_AS_ARRAY);

                                //Prepare temporary file on local FS
                                $tempFileName = tempnam('/tmp', 'copy_' . md5($sUserPublicId . $aItem['FromPath'] . $aItem['Name']));

                                if (!empty($metadata['id'])) {

                                    //Download original file content to temp file
                                    if ($this->getB2Client()->download([
                                        'FileId' => $metadata['id'],
                                        'SaveAs' => $tempFileName
                                    ])) {

                                        $fileNewName = $this->oApiFilesManager->getNonExistentFileName(
                                            $sUserPublicId,
                                            $aArgs['ToType'],
                                            $aArgs['ToPath'],
                                            $aItem['Name']
                                        );

                                        $b2FileName = self::b2Filename($sUserPublicId, $aArgs['ToPath'], $fileNewName);

                                        /* @var $b2File File */
                                        $b2File = $this->getB2Client()->upload([
                                            'FileName' => $b2FileName,
                                            'BucketId' => $this->b2BucketId,
                                            'Body' => fopen($tempFileName, 'r')
                                        ]);
                                        unset($tempFileName);


                                        $metadata = json_encode([
                                            'id' => $b2File->getId(),
                                            'name' => $b2FileName,
                                            'size' => $b2File->getSize()
                                        ]);

                                        $mResult = $this->oApiFilesManager->createFile(
                                            $sUserPublicId,
                                            $aItem['FromType'],
                                            $aArgs['ToPath'],
                                            $fileNewName,
                                            $metadata
                                        );
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            \Aurora\System\Api::Log($e->getMessage(), LogLevel::Error, 'b2');
                            throw new \Exception('Cloud storage error');
                        }

                    }
                }
            }
            return true;
        }
    }

    /**
     * @api {post} ?/Api/ Move
     * @apiDescription Moves files and/or folders from one folder to another.
     * @apiName Move
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=Move} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **FromType** *string* Storage type of folder items will be moved from.<br>
     * &emsp; **ToType** *string* Storage type of folder items will be moved to.<br>
     * &emsp; **FromPath** *string* Folder items will be moved from.<br>
     * &emsp; **ToPath** *string* Folder items will be moved to.<br>
     * &emsp; **Files** *array* List of items to move<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'Move',
     *	Parameters: '{ FromType: "personal", ToType: "corporate", FromPath: "", ToPath: "",
     *		Files: [{ "Name": "logo.png", "IsFolder": false },
     *		{ "Name": "details.png", "IsFolder": false }] }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicates if files and (or) folders were moved successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'Move',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'Move',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    public function onAfterMove(&$aArgs, &$mResult)
    {
        if ($this->checkStorageType($aArgs['FromType']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
            foreach ($aArgs['Files'] as $aItem)
            {
                $bFolderIntoItself = $aItem['IsFolder'] && $aArgs['ToPath'] === $aItem['FromPath'].'/'.$aItem['Name'];
                if (!$bFolderIntoItself)
                {
                    $mResult = $this->oApiFilesManager->move(
                        $sUserPublicId,
                        $aItem['FromType'],
                        $aArgs['ToType'],
                        $aItem['FromPath'],
                        $aArgs['ToPath'],
                        $aItem['Name'],
                        $this->oApiFilesManager->getNonExistentFileName(
                            $sUserPublicId,
                            $aArgs['ToType'],
                            $aArgs['ToPath'],
                            $aItem['Name']
                        )
                    );
                }
            }

            return true;
        }
    }

    public function onAfterGetQuota($aArgs, &$mResult)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        if (isset($aArgs['Type']) && $this->checkStorageType($aArgs['Type']))
        {
            $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($aArgs['UserId']);
            $mResult = array(
                'Used' => $this->oApiFilesManager->getUserSpaceUsed($sUserPublicId, [\Aurora\System\Enums\FileStorageType::Personal]),
                'Limit' => $this->getConfig('UserSpaceLimitMb', 0) * 1024 * 1024
            );
        }
    }

    /**
     * @api {post} ?/Api/ CreatePublicLink
     * @apiDescription Creates public link for file or folder.
     * @apiName CreatePublicLink
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=CreatePublicLink} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage contains the item.<br>
     * &emsp; **Path** *string* Path to the item.<br>
     * &emsp; **Name** *string* Name of the item.<br>
     * &emsp; **Size** *int* Size of the file.<br>
     * &emsp; **IsFolder** *bool* Indicates if the item is folder or not.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'CreatePublicLink',
     *	Parameters: '{ Type: "personal", Path: "", Name: "image.png", Size: 100, "IsFolder": false }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {mixed} Result.Result Public link to the item in case of success, otherwise **false**.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreatePublicLink',
     *	Result: 'AppUrl/?/files-pub/shared_item_hash/list'
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'CreatePublicLink',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    /**
     * Creates public link for file or folder.
     *
     * @param int $UserId User identifier.
     * @param string $Type Type of storage contains the item.
     * @param string $Path Path to the item.
     * @param string $Name Name of the item.
     * @param int $Size Size of the file.
     * @param bool $IsFolder Indicates if the item is folder or not.
     * @return string|false Public link to the item.
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onAfterCreatePublicLink($aArgs, &$mResult)
    {
        $UserId = $aArgs['UserId'];
        $Type = $aArgs['Type'];
        $Path = $aArgs['Path'];
        $Name = $aArgs['Name'];
        $Size = $aArgs['Size'];
        $IsFolder = $aArgs['IsFolder'];

        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($UserId);
        $bFolder = (bool)$IsFolder;
        $mResult = $this->oApiFilesManager->createPublicLink($sUserPublicId, $Type, $Path, $Name, $Size, $bFolder);
    }

    /**
     * @api {post} ?/Api/ DeletePublicLink
     * @apiDescription Deletes public link from file or folder.
     * @apiName DeletePublicLink
     * @apiGroup Files
     *
     * @apiHeader {string} Authorization "Bearer " + Authentication token which was received as the result of Core.Login method.
     * @apiHeaderExample {json} Header-Example:
     *	{
     *		"Authorization": "Bearer 32b2ecd4a4016fedc4abee880425b6b8"
     *	}
     *
     * @apiParam {string=Files} Module Module name
     * @apiParam {string=DeletePublicLink} Method Method name
     * @apiParam {string} Parameters JSON.stringified object <br>
     * {<br>
     * &emsp; **Type** *string* Type of storage contains the item.<br>
     * &emsp; **Path** *string* Path to the item.<br>
     * &emsp; **Name** *string* Name of the item.<br>
     * }
     *
     * @apiParamExample {json} Request-Example:
     * {
     *	Module: 'Files',
     *	Method: 'DeletePublicLink',
     *	Parameters: '{ Type: "personal", Path: "", Name: "image.png" }'
     * }
     *
     * @apiSuccess {object[]} Result Array of response objects.
     * @apiSuccess {string} Result.Module Module name
     * @apiSuccess {string} Result.Method Method name
     * @apiSuccess {bool} Result.Result Indicated if public link was deleted successfully.
     * @apiSuccess {int} [Result.ErrorCode] Error code
     *
     * @apiSuccessExample {json} Success response example:
     * {
     *	Module: 'Files',
     *	Method: 'DeletePublicLink',
     *	Result: true
     * }
     *
     * @apiSuccessExample {json} Error response example:
     * {
     *	Module: 'Files',
     *	Method: 'DeletePublicLink',
     *	Result: false,
     *	ErrorCode: 102
     * }
     */

    /**
     * Deletes public link from file or folder.
     *
     * @param int $UserId User identifier.
     * @param string $Type Type of storage contains the item.
     * @param string $Path Path to the item.
     * @param string $Name Name of the item.
     * @return bool
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onAfterDeletePublicLink($aArgs, &$mResult)
    {
        $UserId = $aArgs['UserId'];
        $Type = $aArgs['Type'];
        $Path = $aArgs['Path'];
        $Name = $aArgs['Name'];

        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $sUserPublicId = \Aurora\System\Api::getUserPublicIdById($UserId);

        $mResult = $this->oApiFilesManager->deletePublicLink($sUserPublicId, $Type, $Path, $Name);
    }

    /**
     * @param $aArgs
     * @param $mResult
     */
    public function onAfterIsFileExists($aArgs, &$mResult)
    {
        if (isset($aArgs['Type']) && $this->checkStorageType($aArgs['Type']))
        {
            $UserId = $aArgs['UserId'];
            $Type = $aArgs['Type'];
            $Path = $aArgs['Path'];
            $Name = $aArgs['Name'];

            $sUUID = \Aurora\System\Api::getUserPublicIdById($UserId);
            $mResult = $this->oApiFilesManager->isFileExists($sUUID, $Type, $Path, $Name);
        }
    }

    /***** public functions might be called with web API *****/
}
