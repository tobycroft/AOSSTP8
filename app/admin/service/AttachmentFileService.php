<?php

namespace app\admin\service;

use app\v1\file\action\OssSelectionAction;
use app\v1\oss\model\OssModel;
use app\v3\storage\model\V3StorageModel;
use OSS\AliyunOSS;
use think\facade\Db;
use Throwable;

/**
 * 附件回收站：软删 / 恢复 / 彻底删除
 *
 * 软删不再 unlink 文件，而是把文件实体移动到 public/upload/recycle/YYYYMMDD/ 下，
 * 文件名加 id 前缀避免同一天重名；ao_attachment 行保留并打 is_deleted=1。
 * OSS 对象先下载到 recycle 目录留底，再删除远程对象；恢复时重新上传回去。
 * 只有「彻底删除」才会真正 unlink 回收站里的文件并删除 DB 行。
 */
class AttachmentFileService
{
    /** 单次批量上限 */
    public const MAX_BATCH = 100;

    /** 回收站根目录（相对 upload/） */
    public const RECYCLE_DIR = 'recycle';

    /**
     * 软删：标记记录 + 把文件移入回收站
     *
     * @param array $ids 附件 id
     * @param string|null $scope 'v3' 时只允许 V3 附件
     * @return array{deleted:int, warnings:string[], errors:string[]}
     */
    public static function delete(array $ids, ?string $scope = null): array
    {
        $result = self::newResult();
        $rows = self::loadRows($ids, $scope, $result);
        if ($rows === null) {
            return $result;
        }

        $now = date('Y-m-d H:i:s');
        $dateFolder = date('Ymd');
        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }

        foreach ($rows as $id => $row) {
            if ((int)($row['is_deleted'] ?? 0) === 1) {
                $result['errors'][] = "附件 #{$id} 已在回收站中";
                continue;
            }

            $path = self::normalizePath((string)($row['path'] ?? ''));
            if ($path === '') {
                // 没有 path 就无法搬文件，只打标记，明确告警
                Db::name('ao_attachment')->where('id', $id)->update([
                    'is_deleted' => 1,
                    'deleted_at' => $now,
                    'recycle_path' => '',
                ]);
                $result['deleted']++;
                $result['warnings'][] = "附件 #{$id} 未记录 path，仅标记删除，未移动文件";
                continue;
            }
            if (!self::isSafeRelativePath($path)) {
                $result['errors'][] = "附件 #{$id} 的 path 不合法，已跳过";
                continue;
            }

            $storage = self::resolveStorage($row);
            $recyclePath = self::RECYCLE_DIR . '/' . $dateFolder . '/' . $id . '_' . basename($path);
            $src = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $dst = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $recyclePath);

            if (!self::ensureParentDir($dst, $root, $error)) {
                $result['errors'][] = "附件 #{$id} 创建回收站目录失败：{$error}";
                continue;
            }

            if (is_file($src)) {
                if (!self::moveFile($src, $dst, $error)) {
                    $result['errors'][] = "附件 #{$id} 移入回收站失败：{$error}";
                    continue;
                }
            } elseif ($storage['remote']) {
                // OSS 附件上传后本地临时文件已被清理，从远程下载留底
                if (!self::downloadRemote($storage['proc'], $path, $dst, $error)) {
                    $result['errors'][] = "附件 #{$id} 下载 OSS 文件失败：{$error}";
                    continue;
                }
            } else {
                $result['warnings'][] = "附件 #{$id} 的源文件已不存在，仅标记删除";
            }

            // 本地已留底，再删远程对象；删不掉也保留 DB 标记并在提示里说明
            if ($storage['remote']) {
                $remoteError = '';
                if (!self::deleteRemote($storage['proc'], $path, $remoteError)) {
                    $result['warnings'][] = "附件 #{$id} 回收站已留底，但 OSS 对象删除失败：{$remoteError}";
                }
            }

            Db::name('ao_attachment')->where('id', $id)->update([
                'is_deleted' => 1,
                'deleted_at' => $now,
                'recycle_path' => $recyclePath,
            ]);
            $result['deleted']++;

            if ($storage['configMissing']) {
                $result['warnings'][] = "附件 #{$id} 未找到存储配置，按本地文件处理";
            }
            if ($storage['dpSkipped']) {
                $result['warnings'][] = "附件 #{$id} 为 DP 远程存储，DP 副本未处理";
            }
        }

        return $result;
    }

    /**
     * 恢复：从 recycle/YYYYMMDD/ 移回原路径并清除删除标记
     */
    public static function restore(array $ids, ?string $scope = null): array
    {
        $result = self::newResult();
        $rows = self::loadRows($ids, $scope, $result);
        if ($rows === null) {
            return $result;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }

        foreach ($rows as $id => $row) {
            if ((int)($row['is_deleted'] ?? 0) !== 1) {
                $result['errors'][] = "附件 #{$id} 不在回收站中";
                continue;
            }

            $path = self::normalizePath((string)($row['path'] ?? ''));
            $recyclePath = self::normalizePath((string)($row['recycle_path'] ?? ''));
            if ($path === '' || $recyclePath === '' || !self::isSafeRelativePath($path) || !self::isRecyclePath($recyclePath)) {
                $result['errors'][] = "附件 #{$id} 的回收站路径不合法，已跳过";
                continue;
            }

            $src = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $recyclePath);
            $dst = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($src)) {
                $result['errors'][] = "附件 #{$id} 的回收站文件不存在，无法恢复";
                continue;
            }
            if (is_file($dst)) {
                $result['errors'][] = "附件 #{$id} 的原路径已有文件，请先处理后再恢复";
                continue;
            }

            $storage = self::resolveStorage($row);

            // 远程类型：先把留底文件传回 OSS
            if ($storage['remote']) {
                $remoteError = '';
                if (!self::uploadRemote($storage['proc'], $path, $src, $remoteError)) {
                    $result['errors'][] = "附件 #{$id} 恢复 OSS 文件失败：{$remoteError}";
                    continue;
                }
            }

            // all 类型本地还要有一份；纯 oss 类型恢复后本地不留副本（与上传时一致）
            $keepLocal = !in_array($storage['type'], ['oss'], true);
            if ($keepLocal) {
                if (!self::ensureParentDir($dst, $root, $error)) {
                    $result['errors'][] = "附件 #{$id} 创建原目录失败：{$error}";
                    continue;
                }
                if (!self::moveFile($src, $dst, $error)) {
                    $result['errors'][] = "附件 #{$id} 恢复本地文件失败：{$error}";
                    continue;
                }
            } elseif (!@unlink($src)) {
                $result['warnings'][] = "附件 #{$id} 已恢复 OSS 文件，但回收站留底文件删除失败";
            }

            Db::name('ao_attachment')->where('id', $id)->update([
                'is_deleted' => 0,
                'deleted_at' => null,
                'recycle_path' => null,
            ]);
            $result['deleted']++;
        }

        return $result;
    }

    /**
     * 彻底删除：删除回收站里的留底文件和 DB 行，不可恢复
     */
    public static function purge(array $ids, ?string $scope = null): array
    {
        $result = self::newResult();
        $rows = self::loadRows($ids, $scope, $result);
        if ($rows === null) {
            return $result;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }

        foreach ($rows as $id => $row) {
            if ((int)($row['is_deleted'] ?? 0) !== 1) {
                $result['errors'][] = "附件 #{$id} 不在回收站中";
                continue;
            }

            $recyclePath = self::normalizePath((string)($row['recycle_path'] ?? ''));
            if ($recyclePath !== '' && self::isRecyclePath($recyclePath)) {
                $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $recyclePath);
                $parent = realpath(dirname($full));
                if ($parent === false || !self::isInsideRecycle($parent, $root)) {
                    $result['errors'][] = "附件 #{$id} 的回收站文件越界，已跳过";
                    continue;
                }
                if (is_file($full) && !@unlink($full)) {
                    $result['errors'][] = "附件 #{$id} 的回收站文件删除失败";
                    continue;
                }
            }

            Db::name('ao_attachment')->where('id', $id)->delete();
            $result['deleted']++;
        }

        return $result;
    }

    /** 初始化结果结构 */
    private static function newResult(): array
    {
        return ['deleted' => 0, 'warnings' => [], 'errors' => []];
    }

    /** 读取并校验 id 列表 / 记录 / scope */
    private static function loadRows(array $ids, ?string $scope, array &$result): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if (empty($ids)) {
            $result['errors'][] = '未选择任何附件';
            return null;
        }
        if (count($ids) > self::MAX_BATCH) {
            $result['errors'][] = '单次最多操作 ' . self::MAX_BATCH . ' 条，请分批操作';
            return null;
        }

        $rows = Db::name('ao_attachment')->whereIn('id', $ids)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }

        $out = [];
        foreach ($ids as $id) {
            if (!isset($map[$id])) {
                $result['errors'][] = "附件 #{$id} 不存在";
                continue;
            }
            $token = (string)($map[$id]['token'] ?? '');
            $isV3 = (string)($map[$id]['tag'] ?? '') === 'v3' || str_starts_with($token, 'v3bucket:');
            if ($scope === 'v3' && !$isV3) {
                $result['errors'][] = "附件 #{$id} 不是 V3 附件，已跳过";
                continue;
            }
            $out[$id] = $map[$id];
        }
        return $out;
    }

    /** 解析附件的存储类型与远程凭据 */
    private static function resolveStorage(array $row): array
    {
        $token = (string)($row['token'] ?? '');
        $isV3 = (string)($row['tag'] ?? '') === 'v3' || str_starts_with($token, 'v3bucket:');
        $out = ['type' => 'local', 'remote' => false, 'proc' => [], 'dpSkipped' => false, 'configMissing' => false];

        if ($isV3) {
            $bucket = V3StorageModel::api_find_by_token($token);
            if (!empty($bucket)) {
                $out['type'] = strtolower((string)($bucket['type'] ?? 'local'));
                $out['remote'] = $out['type'] === 'oss';
                $out['proc'] = V3StorageModel::api_proc($bucket);
            } elseif ($token !== '') {
                $out['configMissing'] = true;
            }
            return $out;
        }

        $oss = OssModel::api_find_token($token);
        if (!empty($oss)) {
            $out['type'] = strtolower((string)($oss['type'] ?? 'local'));
            $out['remote'] = in_array($out['type'], ['oss', 'all'], true);
            $out['dpSkipped'] = $out['type'] === 'dp';
            $out['proc'] = OssSelectionAction::App_find_byProc($oss);
        } elseif ($token !== '') {
            $out['configMissing'] = true;
        }
        return $out;
    }

    /** 目录不存在则创建，并确认仍在 upload 根内 */
    private static function ensureParentDir(string $full, string $root, string &$error): bool
    {
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = 'mkdir 失败';
            return false;
        }
        $real = realpath($dir);
        if ($real === false || !self::isInsideUpload($real, $root)) {
            $error = '目录越出 upload 根目录';
            return false;
        }
        return true;
    }

    /** 移动文件；跨设备时退化为 copy + unlink */
    private static function moveFile(string $src, string $dst, string &$error): bool
    {
        if (@rename($src, $dst)) {
            return true;
        }
        if (@copy($src, $dst) && @unlink($src)) {
            return true;
        }
        $error = 'rename/copy 失败（请检查权限）';
        return false;
    }

    /** 从 OSS 下载对象到回收站留底 */
    private static function downloadRemote(array $proc, string $object, string $localPath, string &$error): bool
    {
        $bucket = (string)($proc['bucket'] ?? '');
        if ($bucket === '' || empty($proc['accesskey']) || empty($proc['accesssecret']) || empty($proc['endpoint'])) {
            $error = 'OSS 凭据不完整';
            return false;
        }
        try {
            $oss = new AliyunOSS($proc);
            $oss->downloadFile($bucket, $object, $localPath);
            return is_file($localPath);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /** 恢复时把留底文件上传回 OSS */
    private static function uploadRemote(array $proc, string $object, string $localPath, string &$error): bool
    {
        $bucket = (string)($proc['bucket'] ?? '');
        if ($bucket === '' || empty($proc['accesskey']) || empty($proc['accesssecret']) || empty($proc['endpoint'])) {
            $error = 'OSS 凭据不完整';
            return false;
        }
        try {
            $oss = new AliyunOSS($proc);
            $oss->uploadLocalFile($bucket, $object, $localPath);
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    private static function deleteRemote(array $proc, string $path, string &$error): bool
    {
        $bucket = (string)($proc['bucket'] ?? '');
        if ($bucket === '' || empty($proc['accesskey']) || empty($proc['accesssecret']) || empty($proc['endpoint'])) {
            $error = 'OSS 凭据不完整';
            return false;
        }
        try {
            $oss = new AliyunOSS($proc);
            $oss->deleteFile($bucket, $path);
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /** 相对路径校验：不得为空、绝对路径、空段或 . / .. 段 */
    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** 回收站路径必须是 recycle/ 开头的安全相对路径 */
    private static function isRecyclePath(string $path): bool
    {
        return str_starts_with($path, self::RECYCLE_DIR . '/') && self::isSafeRelativePath($path);
    }

    private static function isInsideUpload(string $dir, string $root): bool
    {
        return $dir === $root || str_starts_with($dir, $root . DIRECTORY_SEPARATOR);
    }

    /** realpath 后的父目录必须仍在 upload/recycle 内 */
    private static function isInsideRecycle(string $dir, string $root): bool
    {
        $recycle = $root . DIRECTORY_SEPARATOR . self::RECYCLE_DIR;
        return $dir === $recycle || str_starts_with($dir, $recycle . DIRECTORY_SEPARATOR);
    }
}
