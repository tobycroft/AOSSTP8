<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace OSS;

use think\response\Json;

class AliyunOSS
{

    public $oss;
    private $config;

    public function __construct($config)
    {
        //获取配置项，并赋值给对象$this->config
        $this->config = $config;
        $this->new_oss();
    }

    /**
     * 实例化阿里云OSS
     * @return object 实例化得到的对象
     * @return 此步作为共用对象，可提供给多个模块统一调用
     */
    private function new_oss()
    {
        //实例化OSS
        $oss = new OssClient($this->config['accesskey'], $this->config['accesssecret'], $this->config['endpoint']);
        $this->oss = $oss;
        return $this->oss;
    }

    /**
     * 上传指定的本地文件内容
     *
     * @param OssClient $ossClient OSSClient实例
     * @param string $bucket 存储空间名称
     * @param string $object 上传的文件名称
     * @param string $Path 本地文件路径
     * @return null
     */
    public function uploadFile($bucket, $object, $Path): Json
    {
        //try 要执行的代码,如果代码执行过程中某一条语句发生异常,则程序直接跳转到CATCH块中,由$e收集错误信息和显示
        try {
            //没忘吧，new_oss()是我们上一步所写的自定义函数
            $ossClient = $this->oss;
            //uploadFile的上传方法
            $res = $ossClient->uploadFile($bucket, $object, $Path);
            return json($res);
        } catch (OssException $e) {
            //如果出错这里返回报错信息
            return $e->getMessage();
        }
    }

    /**
     * 删除 OSS 上的对象（后台删附件时同步删除远程文件）
     *
     * object 与上传时一致，即 ao_attachment.path：{Path}/YYYYMMDD/md5.ext
     * 失败时抛出 \OSS\Core\OssException，由调用方决定如何处理 DB 记录。
     */
    public function deleteFile($bucket, $object): void
    {
        $this->oss->deleteObject($bucket, $object);
    }

    /**
     * 下载 OSS 对象到本地文件（软删时把远程文件留底到 recycle 目录）
     */
    public function downloadFile($bucket, $object, string $localPath): void
    {
        $body = $this->oss->getObject($bucket, $object);
        if (file_put_contents($localPath, $body) === false) {
            throw new \RuntimeException('写入回收站文件失败: ' . $localPath);
        }
    }

    /**
     * 上传本地文件到 OSS 对象（恢复时把 recycle 留底文件传回原对象）
     */
    public function uploadLocalFile($bucket, $object, string $localPath): void
    {
        $this->oss->uploadFile($bucket, $object, $localPath);
    }

}
