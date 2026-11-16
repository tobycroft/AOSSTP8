<?php

namespace Aoss;

/**
 * v3 开放接口凭证信封（AES-256-GCM）
 *
 * secret 本身即共享密钥：请求链路上只出现 kid（secret 的哈希前缀）与密文，
 * secret 永不明文传输。
 *
 * 约定：
 *   kid  = substr(sha256(secret), 0, 8)                 仅用于服务端定位记录，不可逆推 secret
 *   key  = hkdf_sha256(secret, 32, 'aoss.v3.envelope')  与记录一一对应，换 secret 即换密钥
 *   auth = 'v1.' . base64url(iv[12] ‖ ciphertext ‖ tag[16])
 *   明文 = secret . '|' . ts
 *
 * iv 每次随机，同一 secret 的密文逐次不同；tag 保证密文不可篡改；
 * 版本号同时作为 AAD，防止跨版本重放。
 *
 * 强度前提：secret 必须为服务端签发的高熵随机串（randomSecret()，64 位十六进制）。
 * 信封的机密性上限等于 secret 的熵——低熵 secret 的密文可被离线枚举还原。
 */
class Envelope
{
    /** 信封版本，参与 AAD 绑定，升级算法时递增 */
    public const VERSION = 'v1';

    /** secret 的标准长度（字符），64 位十六进制 = 32 字节熵 */
    public const SECRET_LEN = 64;

    /** 密钥派生标签，固定值，禁止随请求变化 */
    private const KDF_INFO = 'aoss.v3.envelope';

    /** GCM 标准 IV 长度（字节） */
    private const IV_LEN = 12;

    /** GCM 认证标签长度（字节） */
    private const TAG_LEN = 16;

    /**
     * 由 secret 派生定位标识
     * @param string $secret 凭证（ao_ai.secret）
     * @return string 8 位小写十六进制
     */
    public static function kid(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 8);
    }

    /**
     * 由 secret 派生 32 字节 AES-256 密钥
     * @param string $secret 凭证
     * @return string 原始 32 字节
     */
    public static function deriveKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::KDF_INFO);
    }

    /**
     * 生成凭证信封（调用方使用）
     * @param string $secret 凭证，同时作为加密密钥
     * @param string|int $ts 秒级 Unix 时间戳
     * @return string 形如 v1.xxxxx，失败返回空串
     */
    public static function seal(string $secret, string|int $ts): string
    {
        if ($secret === '') {
            return '';
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $secret . '|' . (string)$ts,
            'aes-256-gcm',
            self::deriveKey($secret),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::VERSION,
            self::TAG_LEN
        );
        if ($cipher === false || strlen($tag) !== self::TAG_LEN) {
            return '';
        }
        return self::VERSION . '.' . self::b64u($iv . $cipher . $tag);
    }

    /**
     * 解开凭证信封（服务端使用）
     * @param string $auth 客户端提交的密文
     * @param string $secret 凭证，同时作为解密密钥
     * @return array{secret:string,ts:string}|null 校验失败一律返回 null
     */
    public static function open(string $auth, string $secret): ?array
    {
        if ($auth === '' || $secret === '') {
            return null;
        }
        $dot = strpos($auth, '.');
        if ($dot === false) {
            return null;
        }
        $version = substr($auth, 0, $dot);
        if (!hash_equals(self::VERSION, $version)) {
            return null;
        }

        $raw = self::unb64u(substr($auth, $dot + 1));
        if ($raw === null || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, -self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN, -self::TAG_LEN);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::deriveKey($secret),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $version
        );
        if ($plain === false || $plain === '') {
            return null;
        }

        $parts = explode('|', $plain);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        return ['secret' => $parts[0], 'ts' => $parts[1]];
    }

    /**
     * 签发凭证：64 位十六进制随机串（32 字节熵）
     *
     * 服务端专用。凭证熵直接决定信封的机密性上限，
     * 禁止人工指定或复用可读单词。
     *
     * @return string
     */
    public static function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 校验凭证强度：仅接受 64 位十六进制
     * @param string $secret
     * @return bool
     */
    public static function isStrongSecret(string $secret): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $secret) === 1;
    }

    /**
     * base64url 编码（无填充，URL 与 Header 安全）
     */
    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * base64url 解码，非法输入返回 null
     */
    public static function unb64u(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9\-_]+$/', $encoded) !== 1) {
            return null;
        }
        $padded = strtr($encoded, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $raw = base64_decode($padded, true);
        return $raw === false ? null : $raw;
    }
}
