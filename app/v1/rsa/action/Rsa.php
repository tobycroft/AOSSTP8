<?php

namespace app\v1\rsa\action;

class Rsa
{
    private static $PRIVATE_KEY = '';
    private static $PUBLIC_KEY = 'rsa_public_key.pem 内容';

    /**
     * 创建签名
     * @param string $data 数据
     * @return null|string
     */
    public function createSign($data = '')
    {
        if (!is_string($data)) {
            return null;
        }
        return openssl_sign(openssl_digest($data, 'sha256'), $sign, self::getPrivateKey(), OPENSSL_ALGO_SHA256) ? base64_encode($sign) : null;
    }

    /**
     * 获取私钥
     * @return bool|resource
     */
    private static function getPrivateKey()
    {
        $privKey = self::$PRIVATE_KEY;
        return openssl_pkey_get_private($privKey);
    }

    public function sign($data = '')
    {
        if (!is_string($data)) {
            return null;
        }
        return openssl_sign($data, $sign, self::getPrivateKey(), OPENSSL_ALGO_SHA256) ? base64_encode($sign) : null;
    }

    public function digest($data = '')
    {
        if (!is_string($data)) {
            return null;
        }
        return openssl_digest($data, 'sha256');
    }

    /**
     * 验证签名
     * @param string $data 数据
     * @param string $sign 签名
     * @return bool
     */
    public function verifySign($data = '', $sign = '')
    {
        if (!is_string($sign) || !is_string($sign)) {
            return false;
        }
        return (bool)openssl_verify(
            $data,
            base64_decode($sign),
            self::getPublicKey(),
            OPENSSL_ALGO_SHA256
        );
    }

    /**
     * 获取公钥
     * @return bool|resource
     */
    private static function getPublicKey()
    {
        $publicKey = self::$PUBLIC_KEY;
        return openssl_pkey_get_public($publicKey);
    }
}