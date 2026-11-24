<?php

namespace app\admin\service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use think\facade\Db;
use Throwable;

/**
 * 实际文件管理：public/upload 磁盘上的「无主文件」 <-> ao_real_file 独立索引
 *
 * 与 ao_attachment 表结构无关，但扫描时会读取 ao_attachment.path 做比对：
 * 磁盘上每个文件若能在 attachment.path 中找到，即为有主文件，不入库；
 * 找不到的才是无主文件，才写入本表供后台管理。
 *
 * 删除分两级：
 *   softDelete() 把文件/目录移动到 public/upload/recycle/YYYYMMDD/{id}_名称，
 *                DB 行保留并标记 is_deleted=1，path_hash 改为回收站位置，释放原路径。
 *   hardDelete() 真正 unlink 磁盘文件并删除 DB 行，目录会级联清理后代记录。
 *
 * 安全约束：所有路径必须是 upload 根下的安全相对路径，realpath 复核；
 * 拒绝 upload 根目录本身；跳过符号链接；scan 不进入 recycle 目录。
 */
class RealFileService
{
    /** 单次扫描条目上限，避免超大目录拖死请求 */
    public const MAX_SCAN = 20000;

    /** 批量操作单次上限 */
    public const MAX_BATCH = 100;

    /** 回收站根目录（相对 upload/），与附件回收站一致 */
    public const RECYCLE_DIR = 'recycle';

    private const MIME_MAP = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'gz' => 'application/gzip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html',
        'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json',
        'xml' => 'application/xml', 'md' => 'text/markdown',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * 手动全量扫描 public/upload，把无主文件创建/回填到 ao_real_file
     *
     * @return array{scanned:int,dirs:int,files:int,owned:int,created:int,updated:int,removed:int,missing:int,skipped:int,truncated:bool,errors:string[]}
     */
    public static function scan(): array
    {
        $result = [
            'scanned' => 0, 'dirs' => 0, 'files' => 0, 'owned' => 0,
            'created' => 0, 'updated' => 0, 'removed' => 0, 'missing' => 0,
            'skipped' => 0, 'truncated' => false, 'errors' => [],
        ];

        $root = realpath(public_path() . 'upload');
        if ($root === false || !is_dir($root)) {
            $result['errors'][] = 'upload 目录不存在或不可读';
            return $result;
        }

        // attachment 表里的全部 path（含软删行：path 保留原值，文件已移到回收站，
        // 原位置不会再被扫到，因此无需按 is_deleted 过滤）
        [$ownedFiles, $ownedDirs] = self::loadOwnedPaths();

        $entries = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS | RecursiveIteratorIterator::CATCH_GET_CHILD
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    $result['skipped']++;
                    continue;
                }
                $full = $item->getPathname();
                $real = realpath($full);
                if ($real === false || !self::isInsideUpload($real, $root)) {
                    $result['skipped']++;
                    continue;
                }
                $rel = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root))), '/');
                if ($rel === '' || !self::isSafeRelativePath($rel)) {
                    $result['skipped']++;
                    continue;
                }
                // 回收站内的文件是软删结果，不参与无主文件扫描
                if (explode('/', $rel)[0] === self::RECYCLE_DIR) {
                    $result['skipped']++;
                    continue;
                }
                // 与 attachment.path 比对：文件精确匹配，目录按前缀匹配
                $isDir = $item->isDir();
                if ($isDir ? isset($ownedDirs[$rel]) : isset($ownedFiles[$rel])) {
                    $result['owned']++;
                    continue;
                }
                if (count($entries) >= self::MAX_SCAN) {
                    $result['truncated'] = true;
                    break;
                }

                $ext = $isDir ? '' : strtolower((string)$item->getExtension());
                if (strlen($ext) > 32) {
                    $ext = substr($ext, 0, 32);
                }
                $name = (string)$item->getFilename();
                if (mb_strlen($name) > 255) {
                    $name = mb_substr($name, 0, 255);
                }
                $parent = str_replace('\\', '/', (string)dirname($rel));
                if ($parent === '.' || $parent === '/') {
                    $parent = '';
                }

                $entries[$rel] = [
                    'path' => $rel,
                    'parent' => $parent,
                    'name' => $name,
                    'type' => $isDir ? 2 : 1,
                    'size' => $isDir ? 0 : (int)$item->getSize(),
                    'ext' => $ext,
                    'mime' => $isDir ? 'directory' : (self::MIME_MAP[$ext] ?? 'application/octet-stream'),
                    'mtime' => (int)$item->getMTime(),
                ];
                $isDir ? $result['dirs']++ : $result['files']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = '遍历失败：' . $e->getMessage();
            return $result;
        }
        $result['scanned'] = count($entries);

        $now = time();
        $existing = Db::name('ao_real_file')
            ->field('id,path_hash,path,parent,name,type,size,ext,mime,mtime,status,is_deleted')
            ->select()->toArray();
        $existingMap = [];
        foreach ($existing as $row) {
            $existingMap[(string)$row['path_hash']] = $row;
        }

        Db::startTrans();
        try {
            $seen = [];
            foreach ($entries as $rel => $entry) {
                $hash = md5($rel);
                $seen[$hash] = true;
                $entry['path_hash'] = $hash;
                if (isset($existingMap[$hash])) {
                    $old = $existingMap[$hash];
                    $changed = (int)$old['status'] !== 1;
                    foreach (['path', 'parent', 'name', 'type', 'size', 'ext', 'mime', 'mtime'] as $field) {
                        if ((string)$old[$field] !== (string)$entry[$field]) {
                            $changed = true;
                            break;
                        }
                    }
                    if ($changed) {
                        $entry['status'] = 1;
                        $entry['sync_time'] = $now;
                        Db::name('ao_real_file')->where('id', (int)$old['id'])->update($entry);
                        $result['updated']++;
                    }
                } else {
                    $entry['status'] = 1;
                    $entry['is_deleted'] = 0;
                    $entry['deleted_at'] = null;
                    $entry['recycle_path'] = '';
                    $entry['sync_time'] = $now;
                    $entry['create_time'] = $now;
                    Db::name('ao_real_file')->insert($entry);
                    $result['created']++;
                }
            }

            if (!$result['truncated']) {
                $missingIds = [];
                foreach ($existing as $row) {
                    // 软删记录的文件已在 recycle 中，不参与磁盘失效判定
                    if ((int)$row['is_deleted'] === 1) {
                        continue;
                    }
                    $hash = (string)$row['path_hash'];
                    if ((int)$row['status'] === 1 && !isset($seen[$hash])) {
                        $missingIds[] = (int)$row['id'];
                    }
                }
                if (!empty($missingIds)) {
                    foreach (array_chunk($missingIds, 500) as $chunk) {
                        Db::name('ao_real_file')->whereIn('id', $chunk)
                            ->update(['status' => 0, 'sync_time' => $now]);
                    }
                    $result['missing'] = count($missingIds);
                }

                // 原本无主的条目变成了 attachment 里的有主文件：从本表移除，避免误管理
                $removeIds = [];
                foreach ($existing as $row) {
                    if ((int)$row['is_deleted'] === 1) {
                        continue;
                    }
                    $path = str_replace('\\', '/', (string)$row['path']);
                    $isDir = (int)$row['type'] === 2;
                    if ($isDir ? isset($ownedDirs[$path]) : isset($ownedFiles[$path])) {
                        $removeIds[] = (int)$row['id'];
                    }
                }
                if (!empty($removeIds)) {
                    foreach (array_chunk($removeIds, 500) as $chunk) {
                        Db::name('ao_real_file')->whereIn('id', $chunk)->delete();
                    }
                    $result['removed'] = count($removeIds);
                }
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $result['errors'][] = '数据库写入失败：' . $e->getMessage();
        }

        return $result;
    }

    /**
     * 软删除：把文件/目录移入 upload/recycle/YYYYMMDD/，DB 行保留
     *
     * @return array{deleted:int,warnings:string[],errors:string[]}
     */
    public static function softDelete(array $ids): array
    {
        $result = self::newResult();
        $rows = self::loadRows($ids, $result);
        if ($rows === null) {
            return $result;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }
        $dateFolder = date('Ymd');
        $recycleBase = self::RECYCLE_DIR . '/' . $dateFolder;
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $id => $row) {
            if ((int)($row['is_deleted'] ?? 0) === 1) {
                $result['errors'][] = "记录 #{$id} 已在回收站中";
                continue;
            }
            $rel = str_replace('\\', '/', trim((string)($row['path'] ?? '')));
            if (!self::isSafeRelativePath($rel) || explode('/', $rel)[0] === self::RECYCLE_DIR) {
                $result['errors'][] = "记录 #{$id} 的 path 不合法，已跳过";
                continue;
            }
            $src = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $real = realpath($src);
            if ($real === false || !self::isInsideUpload($real, $root) || $real === $root) {
                $result['errors'][] = "记录 #{$id} 的文件不存在或越出 upload 目录";
                continue;
            }
            if (is_link($src)) {
                $result['errors'][] = "记录 #{$id} 是符号链接，不允许移入回收站";
                continue;
            }
            $name = (string)($row['name'] ?? basename($rel));
            $safeName = preg_replace('/[^\w.\-]+/u', '_', $name) ?: 'file';
            $targetRel = $recycleBase . '/' . $id . '_' . $safeName;

            $dst = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRel);
            $dst = self::uniqueTarget($dst);
            $targetRel = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($dst, strlen($root))), '/');

            if (!self::ensureParentDir($dst, $root, $error)) {
                $result['errors'][] = "记录 #{$id} 创建回收站目录失败：{$error}";
                continue;
            }
            if (!@rename($src, $dst)) {
                $result['errors'][] = "记录 #{$id} 移入回收站失败（请检查文件权限或跨盘限制）";
                continue;
            }

            // 目录：其下本表记录一起软删，recycle_path 按相对层级映射
            $affected = [$id];
            if ((int)($row['type'] ?? 1) === 2) {
                foreach (self::descendants($rel) as $child) {
                    $childRel = (string)$child['path'];
                    $suffix = ltrim(substr($childRel, strlen($rel)), '/');
                    $childRecycle = $targetRel . ($suffix !== '' ? '/' . $suffix : '');
                    Db::name('ao_real_file')->where('id', (int)$child['id'])->update([
                        'is_deleted' => 1,
                        'deleted_at' => $now,
                        'recycle_path' => $childRecycle,
                        'path_hash' => md5($childRecycle),
                    ]);
                    $affected[] = (int)$child['id'];
                }
            }
            Db::name('ao_real_file')->where('id', $id)->update([
                'is_deleted' => 1,
                'deleted_at' => $now,
                'recycle_path' => $targetRel,
                'path_hash' => md5($targetRel),
            ]);
            $result['deleted'] += count($affected);
        }

        return $result;
    }

    /**
     * 硬删除：真正删除磁盘文件并删除 DB 行
     *
     * 已软删的记录从 recycle_path 定位；未软删的从 path 定位。
     * 目录会递归删除磁盘目录及本表全部后代记录；若目录内含 attachment 文件则拒绝，避免误删有主文件。
     *
     * @return array{deleted:int,warnings:string[],errors:string[]}
     */
    public static function hardDelete(array $ids): array
    {
        $result = self::newResult();
        $rows = self::loadRows($ids, $result);
        if ($rows === null) {
            return $result;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }

        [, $ownedDirs] = self::loadOwnedPaths();

        foreach ($rows as $id => $row) {
            $isRecycled = (int)($row['is_deleted'] ?? 0) === 1;
            $rel = str_replace('\\', '/', trim((string)($isRecycled ? ($row['recycle_path'] ?? '') : ($row['path'] ?? ''))));
            if (!self::isSafeRelativePath($rel)
                || (explode('/', $rel)[0] === self::RECYCLE_DIR && !$isRecycled)) {
                $result['errors'][] = "记录 #{$id} 的定位路径不合法，已跳过";
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $real = realpath($full);
            if ($real === false || !self::isInsideUpload($real, $root) || $real === $root) {
                // 磁盘已不存在：只清记录
                self::deleteRows($id, (string)($row['path'] ?? ''), (int)($row['type'] ?? 1), $result);
                $result['warnings'][] = "记录 #{$id} 的文件已不存在，仅删除了记录";
                continue;
            }
            if (is_link($full)) {
                if (!@unlink($full)) {
                    $result['errors'][] = "记录 #{$id} 的符号链接删除失败";
                    continue;
                }
                self::deleteRows($id, (string)($row['path'] ?? ''), (int)($row['type'] ?? 1), $result);
                continue;
            }

            $isDir = (int)($row['type'] ?? 1) === 2;
            if ($isDir) {
                if (!is_dir($real)) {
                    $result['errors'][] = "记录 #{$id} 不是目录，已跳过";
                    continue;
                }
                // 未软删的目录若包含 attachment 文件，拒绝删除
                if (!$isRecycled && isset($ownedDirs[str_replace('\\', '/', (string)($row['path'] ?? ''))])) {
                    $result['errors'][] = "记录 #{$id} 的目录内有附件文件，已跳过（请先同步）";
                    continue;
                }
                if (!self::removeTree($real, $result)) {
                    $result['errors'][] = "目录 #{$id} 未完全删除，保留记录以便重试";
                    continue;
                }
                self::deleteRows($id, (string)($row['path'] ?? ''), 2, $result);
            } else {
                if (!is_file($real)) {
                    $result['errors'][] = "记录 #{$id} 不是普通文件，已跳过";
                    continue;
                }
                if (!@unlink($real)) {
                    $result['errors'][] = "记录 #{$id} 的文件删除失败（请检查权限）";
                    continue;
                }
                self::deleteRows($id, (string)($row['path'] ?? ''), 1, $result);
            }
        }

        return $result;
    }

    /** 读取 attachment 的 path 集合及各 path 的父目录前缀集合 */
    private static function loadOwnedPaths(): array
    {
        $files = [];
        $dirs = [];
        $paths = Db::name('ao_attachment')->column('path');
        foreach ($paths as $raw) {
            $path = str_replace('\\', '/', trim((string)$raw));
            if ($path === '' || !self::isSafeRelativePath($path)) {
                continue;
            }
            $files[$path] = true;
            $segments = explode('/', $path);
            array_pop($segments);
            $prefix = '';
            foreach ($segments as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
                $dirs[$prefix] = true;
            }
        }
        return [$files, $dirs];
    }

    private static function newResult(): array
    {
        return ['deleted' => 0, 'warnings' => [], 'errors' => []];
    }

    private static function loadRows(array $ids, array &$result): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if (empty($ids)) {
            $result['errors'][] = '未选择任何文件';
            return null;
        }
        if (count($ids) > self::MAX_BATCH) {
            $result['errors'][] = '单次最多操作 ' . self::MAX_BATCH . ' 条，请分批操作';
            return null;
        }
        $rows = Db::name('ao_real_file')
            ->field('id,path,type,status,is_deleted,recycle_path,name')
            ->whereIn('id', $ids)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = $row;
        }
        foreach ($ids as $id) {
            if (!isset($map[$id])) {
                $result['errors'][] = "记录 #{$id} 不存在";
            }
        }
        return $map;
    }

    /** 目录的所有后代记录（按 path 前缀） */
    private static function descendants(string $dirPath): array
    {
        $prefix = rtrim($dirPath, '/') . '/';
        $escaped = addcslashes($prefix, '\\%_');
        return Db::name('ao_real_file')->whereRaw('path LIKE ?', [$escaped . '%'])->select()->toArray();
    }

    /** 删除 DB 记录：目录连带后代 */
    private static function deleteRows(int $id, string $path, int $type, array &$result): void
    {
        if ($type === 2 && self::isSafeRelativePath($path)) {
            $ids = array_map(static fn(array $row): int => (int)$row['id'], self::descendants($path));
            $ids[] = $id;
            foreach (array_chunk(array_unique($ids), 500) as $chunk) {
                $result['deleted'] += Db::name('ao_real_file')->whereIn('id', $chunk)->delete();
            }
            return;
        }
        $result['deleted'] += Db::name('ao_real_file')->where('id', $id)->delete();
    }

    /** 同名目标自动加 _2/_3 后缀，避免覆盖 */
    private static function uniqueTarget(string $dst): string
    {
        if (!file_exists($dst) && !is_link($dst)) {
            return $dst;
        }
        $i = 2;
        while (file_exists($dst . '_' . $i) || is_link($dst . '_' . $i)) {
            $i++;
        }
        return $dst . '_' . $i;
    }

    private static function ensureParentDir(string $dst, string $root, ?string &$error): bool
    {
        $parent = dirname($dst);
        $realParent = realpath($parent);
        if ($realParent === false) {
            if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                $error = 'mkdir 失败';
                return false;
            }
            $realParent = realpath($parent);
        }
        if ($realParent === false || !self::isInsideUpload($realParent, $root)) {
            $error = '目录越出 upload';
            return false;
        }
        return true;
    }

    /** 递归删除目录内容 */
    private static function removeTree(string $dir, array &$result): bool
    {
        $files = [];
        $dirs = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir,
                    FilesystemIterator::SKIP_DOTS | RecursiveIteratorIterator::CATCH_GET_CHILD
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if ($item->isDir() && !$item->isLink()) {
                    $dirs[] = $path;
                } else {
                    $files[] = $path;
                }
            }
        } catch (Throwable $e) {
            $result['errors'][] = '读取目录失败：' . $e->getMessage();
            return false;
        }

        $ok = true;
        foreach ($files as $file) {
            if (!@unlink($file)) {
                $result['errors'][] = '文件删除失败：' . basename($file);
                $ok = false;
            }
        }
        usort($dirs, static function (string $a, string $b): int {
            return substr_count($b, DIRECTORY_SEPARATOR) <=> substr_count($a, DIRECTORY_SEPARATOR);
        });
        foreach ($dirs as $sub) {
            if (!@rmdir($sub)) {
                $result['errors'][] = '子目录删除失败：' . basename($sub);
                $ok = false;
            }
        }
        if (!$ok || !@rmdir($dir)) {
            if ($ok) {
                $result['errors'][] = '目录删除失败：' . basename($dir);
            }
            return false;
        }
        return true;
    }

    /** 相对路径：不得为空、绝对路径、空段、. / .. 段、反斜杠、冒号或 NUL */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 1000 || $path[0] === '/' || str_contains($path, "\0")
            || str_contains($path, '\\') || str_contains($path, ':')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** realpath 后的路径必须仍在 upload 根内（含根目录本身） */
    private static function isInsideUpload(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
