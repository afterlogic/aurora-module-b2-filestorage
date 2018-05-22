<?php

namespace Aurora\Modules\B2Filestorage;

use Aurora\System\Managers\Filecache;
use ChrisWhite\B2\Exceptions\NotFoundException;
use ChrisWhite\B2\Exceptions\ValidationException;
use ChrisWhite\B2\Http\Client as HttpClient;

class Client extends \ChrisWhite\B2\Client
{

    protected $authToken;
    protected $apiUrl;
    protected $downloadUrl;


    private function invalidateAuthCache()
    {
        $oApiFileCache = new Filecache();
        if ($oApiFileCache->isFileExists('b2_common', 'b2_auth')) {
            $oApiFileCache->clear('b2_common', 'b2_auth');
        }
    }

    /**
     * Authorize the B2 account in order to get an auth token and API/download URLs.
     *
     * @throws \Exception
     */
    protected function authorizeAccount()
    {
        $aAuthData = [];

        $oApiFileCache = new Filecache();
        if ($oApiFileCache->isFileExists('b2_common', 'b2_auth')) {
            $cachedAuthDataRaw = $oApiFileCache->get('b2_common', 'b2_auth');
            if (!empty($cachedAuthDataRaw)) {
                $aAuthData = unserialize($cachedAuthDataRaw);
            }
        } else {
            $response = $this->client->request('GET', 'https://api.backblazeb2.com/b2api/v1/b2_authorize_account', [
                'auth' => [$this->accountId, $this->applicationKey]
            ]);

            $aAuthData['authToken'] = $response['authorizationToken'];
            $aAuthData['apiUrl'] = $response['apiUrl'].'/b2api/v1';
            $aAuthData['downloadUrl'] = $response['downloadUrl'];

            $rTempStream = \fopen('php://memory','r+');
            \fwrite($rTempStream, serialize($aAuthData));
            \rewind($rTempStream);

            $oApiFileCache->putFile('b2_common', 'b2_auth', $rTempStream);
        }


        $this->authToken = $aAuthData['authToken'];
        $this->apiUrl = $aAuthData['apiUrl'];
        $this->downloadUrl = $aAuthData['downloadUrl'];

    }

    public function createBucket(array $options)
    {
        try {
            return parent::createBucket($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::createBucket($options);
            }
        }
    }

    public function updateBucket(array $options)
    {
        try {
            return parent::updateBucket($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::updateBucket($options);
            }
        }
    }

    public function listBuckets()
    {
        try {
            return parent::listBuckets();
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::listBuckets();
            }
        }
    }

    public function deleteBucket(array $options)
    {
        try {
            return parent::deleteBucket($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::deleteBucket($options);
            }
        }
    }

    public function upload(array $options)
    {
        try {
            return parent::upload($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::upload($options);
            }
        }
    }

    public function download(array $options)
    {
        try {
            return parent::download($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::download($options);
            }
        }
    }

    public function listFiles(array $options)
    {
        try {
            return parent::listFiles($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::listFiles($options);
            }
        }
    }

    public function fileExists(array $options)
    {
        try {
            return parent::fileExists($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::fileExists($options);
            }
        }
    }

    public function getFile(array $options)
    {
        try {
            return parent::getFile($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::getFile($options);
            }
        }
    }

    protected function getBucketIdFromName($name)
    {
        try {
            return parent::getBucketIdFromName($name);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::getBucketIdFromName($name);
            }
        }
    }

    protected function getBucketNameFromId($id)
    {
        try {
            return parent::getBucketNameFromId($id);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::getBucketNameFromId($id);
            }
        }
    }

    protected function getFileIdFromBucketAndFileName($bucketName, $fileName)
    {
        try {
            return parent::getFileIdFromBucketAndFileName($bucketName, $fileName);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::getFileIdFromBucketAndFileName($bucketName, $fileName);
            }
        }
    }


    public function deleteFile(array $options)
    {
        try {
            return parent::deleteFile($options);
        } catch (\Exception $e) {
            if (false != strpos($e->getMessage(), 'Invalid authorization token')) {
                //Give a second try
                $this->invalidateAuthCache();
                $this->authorizeAccount();
                return parent::deleteFile($options);
            }
        }
    }


}
