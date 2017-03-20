<?php

namespace Vxzhong\Flysystem\AliyunOss;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OSS\OssClient;
use OSS\Core\OssException;

class AliyunOssAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    protected static $mappingOptions = [
        'mimetype' => OssClient::OSS_CONTENT_TYPE,
        'size'     => OssClient::OSS_LENGTH,
    ];

    protected $accessKey;
    protected $secretKey;
    protected $domain;
    protected $isCname;
    protected $bucket;
    protected $client;
    protected $options = [];

    /**
     * QiniuAdapter constructor.
     *
     * @param string $accessKey
     * @param string $secretKey
     * @param string $bucket
     * @param string $domain
     * @param string $isCname
     * @param string $prefix
     * @param array $options
     */
    public function __construct($accessKey, $secretKey, $bucket, $domain, $isCname, $prefix = null,
                                array $options = [])
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->domain = $domain;
        $this->isCname = $isCname;
        $this->setPathPrefix($prefix);
        $this->options = array_merge($this->options, $options);
    }
    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);
        if (! isset($options[OssClient::OSS_LENGTH])) {
            $options[OssClient::OSS_LENGTH] = Util::contentSize($contents);
        }
        if (! isset($options[OssClient::OSS_CONTENT_TYPE])) {
            $options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents);
        }
        try {
            $this->getClient()->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            return false;
        }
        return $this->normalizeResponse($options, $path);
    }
    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = '';
        while (!feof($resource)) {
            $contents .= fread($resource, 1024);
        }
        $response = $this->write($path, $contents, $config);
        if ($response === false) {
            return $response;
        }
        return compact('path');
    }
    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        $this->delete($path);
        return $this->write($path, $contents, $config);
    }
    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $this->delete($path);
        return $this->writeStream($path, $resource, $config);
    }
    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (! $this->copy($path, $newpath)){
            return false;
        }
        return $this->delete($path);
    }
    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $object = $this->applyPathPrefix($path);
        $newObject = $this->applyPathPrefix($newpath);
        try{
            $this->getClient()->copyObject($this->bucket, $object, $this->bucket, $newObject);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }
    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $object = $this->applyPathPrefix($path);
        try{
            $this->getClient()->deleteObject($this->bucket, $object);
        }catch (OssException $e) {
            return false;
        }
        return ! $this->has($path);
    }
    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $list = $this->listContents($dirname, true);
        $objects = [];
        foreach ($list as $val) {
            if ($val['type'] === 'file') {
                $objects[] = $this->applyPathPrefix($val['path']);
            } else {
                $objects[] = $this->applyPathPrefix($val['path']).'/';
            }
        }
        try {
            $this->getClient()->deleteObjects($this->bucket, $objects);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }
    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptionsFromConfig($config);
        try {
            $this->getClient()->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            return false;
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }
    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $exists = $this->getClient()->doesObjectExist($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return $exists;
    }
    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $contents = $this->getClient()->getObject($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }
        return compact('contents', 'path');
    }
    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $object = $this->applyPathPrefix($path);

        if (ini_get('allow_url_fopen')) {
            $stream = fopen('http://'.$this->domain.'/'.$object, 'r');
            return compact('stream', 'path');
        }
        return false;
    }
    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = $this->applyPathPrefix($directory);

        $bucket = $this->bucket;
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;
        $options = [
            'delimiter' => $delimiter,
            'prefix'    => $directory,
            'max-keys'  => $maxkeys,
            'marker'    => $nextMarker,
        ];

        $listObjectInfo = $this->getClient()->listObjects($bucket, $options);
        $objectList = $listObjectInfo->getObjectList(); // 文件列表
        $prefixList = $listObjectInfo->getPrefixList(); // 目录列表
        $result = [];
        foreach ($objectList as $objectInfo) {
            if ($objectInfo->getSize() === 0 && $directory === $objectInfo->getKey()) {
                $result[] = [
                    'type'      => 'dir',
                    'path'      => $this->removePathPrefix(rtrim($objectInfo->getKey(), '/')),
                    'timestamp' => strtotime($objectInfo->getLastModified()),
                ];
                continue;
            }
            $result[] = [
                'type'      => 'file',
                'path'      => $this->removePathPrefix($objectInfo->getKey()),
                'timestamp' => strtotime($objectInfo->getLastModified()),
                'size'      => $objectInfo->getSize(),
            ];
        }
        foreach ($prefixList as $prefixInfo) {
            if ($recursive) {
                $next = $this->listContents($this->removePathPrefix($prefixInfo->getPrefix()), $recursive);
                $result = array_merge($result, $next);
            } else {
                $result[] = [
                    'type'      => 'dir',
                    'path'      => $this->removePathPrefix(rtrim($prefixInfo->getPrefix(), '/')),
                    'timestamp' => 0,
                ];
            }
        }
        return $result;
    }
    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $objectMeta = $this->getClient()->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return $this->normalizeResponse($objectMeta);
    }
    /**
     * Get the size of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }
    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }
    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }
    /**
     * @return \OSS\OssClient
     */
    public function getClient()
    {
        return $this->client ?: $this->client = new OssClient(
            $this->accessKey, $this->secretKey, $this->domain, $this->isCname
        );
    }
    /**
     * Retrieve options from a Config instance. done
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = $this->options;
        foreach (static::$mappingOptions as $option => $ossOption) {
            if (! $config->has($option)) {
                continue;
            }
            $options[$ossOption] = $config->get($option);
        }
        return $options;
    }
    /**
     * Normalize a result from OSS.
     *
     * @param array  $object
     * @param string $path
     *
     * @return array file metadata
     */
    protected function normalizeResponse(array $object, $path = null)
    {
        return [
            'type' => 'file',
            'path' => $path,
            'mimetype' => $object[OssClient::OSS_CONTENT_TYPE],
            'size' => $object[OssClient::OSS_LENGTH],
        ];
    }
}