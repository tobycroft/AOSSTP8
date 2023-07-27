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

}
