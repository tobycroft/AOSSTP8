<?php

namespace app\admin\service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use think\facade\Db;
use Throwable;

/**
 * 实际文件管理：public/upload 磁盘现状 <-> ao_real_file 独立索引
 *
 * 与 ao_attachment 完全无关，只服务于后台独立菜单「实际文件管理」：
 *   1. scan()   手动全量遍历，创建/回填记录；磁盘已消失的条目标记失效，绝不自动删库。
 *   2. delete() 同步删除：先删物理文件/目录，成功后才删对应数据库记录。
 *
 * 安全约束：所有路径必须是 upload 根下的安全相对路径，realpath 复核；
 * 拒绝 upload 根目录本身；跳过符号链接，避免越界或循环删除。
 */
class RealFileService
{
    /** 单次扫描条目上限，避免超大目录拖死请求 */
    public const MAX_SCAN = 20000;

    /** 批量删除单次上限 */
    public const MAX_BATCH = 100;

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
     * 手动全量扫描 public/upload，创建或回填 ao_real_file
     *
     * @return array{scanned:int,dirs:int,files:int,created:int,updated:int,missing:int,skipped:int,truncated:bool,errors:string[]}
     */
    public static function scan(): array
    {
        $result = [
            'scanned' => 0, 'dirs' => 0, 'files' => 0,
            'created' => 0, 'updated' => 0, 'missing' => 0,
            'skipped' => 0, 'truncated' => false, 'errors' => [],
        ];

        $root = realpath(public_path() . 'upload');
        if ($root === false || !is_dir($root)) {
            $result['errors'][] = 'upload 目录不存在或不可读';
            return $result;
        }

        // 先完整收集磁盘条目，再做数据库对账；path 作为键天然去重
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
                if (count($entries) >= self::MAX_SCAN) {
                    // 达到上限：后续条目不再扫描，且本轮不做失效标记，避免误判
                    $result['truncated'] = true;
                    break;
                }

                $isDir = $item->isDir();
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
            ->field('id,path_hash,path,parent,name,type,size,ext,mime,mtime,status')
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
                    $changed = false;
                    foreach (['path', 'parent', 'name', 'type', 'size', 'ext', 'mime', 'mtime'] as $field) {
                        if ((string)$old[$field] !== (string)$entry[$field]) {
                            $changed = true;
                            break;
                        }
                    }
                    if ((int)$old['status'] !== 1) {
                        $changed = true;
                    }
                    if ($changed) {
                        $entry['status'] = 1;
                        $entry['sync_time'] = $now;
                        Db::name('ao_real_file')->where('id', (int)$old['id'])->update($entry);
                        $result['updated']++;
                    }
                } else {
                    $entry['status'] = 1;
                    $entry['sync_time'] = $now;
                    $entry['create_time'] = $now;
                    Db::name('ao_real_file')->insert($entry);
                    $result['created']++;
                }
            }

            if (!$result['truncated']) {
                $missingIds = [];
                foreach ($existing as $row) {
                    if ((int)$row['status'] === 1 && !isset($seen[(string)$row['path_hash']])) {
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
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            $result['errors'][] = '数据库写入失败：' . $e->getMessage();
        }

        return $result;
    }

    /**
     * 同步删除：物理文件/目录成功后再删数据库记录
     *
     * @param array $ids 记录 id 列表
     * @return array{deleted:int,warnings:string[],errors:string[]}
     */
    public static function delete(array $ids): array
    {
        $result = ['deleted' => 0, 'warnings' => [], 'errors' => []];

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        if (empty($ids)) {
            $result['errors'][] = '未选择任何文件';
            return $result;
        }
        if (count($ids) > self::MAX_BATCH) {
            $result['errors'][] = '单次最多删除 ' . self::MAX_BATCH . ' 条，请分批操作';
            return $result;
        }

        $root = realpath(public_path() . 'upload');
        if ($root === false || !is_dir($root)) {
            $result['errors'][] = 'upload 目录不存在';
            return $result;
        }

        $rows = Db::name('ao_real_file')->field('id,path,type,status')->whereIn('id', $ids)->select()->toArray();
        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[(int)$row['id']] = $row;
        }

        foreach ($ids as $id) {
            if (!isset($rowMap[$id])) {
                $result['errors'][] = "记录 #{$id} 不存在";
                continue;
            }
            $row = $rowMap[$id];
            $rel = str_replace('\\', '/', trim((string)($row['path'] ?? '')));
            if (!self::isSafeRelativePath($rel)) {
                $result['errors'][] = "记录 #{$id} 的 path 不合法，已跳过（未删除记录，也未删除文件）";
                continue;
            }

            $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

            // 符号链接只删链接本身，绝不跟随目标
            if (is_link($full)) {
                if (!@unlink($full)) {
                    $result['errors'][] = "记录 #{$id} 的符号链接删除失败";
                    continue;
                }
                Db::name('ao_real_file')->where('id', $id)->delete();
                $result['deleted']++;
                continue;
            }

            $real = realpath($full);
            $isDir = (int)($row['type'] ?? 1) === 2;

            if ($real === false || !self::isInsideUpload($real, $root)) {
                // 失效记录（文件已被外部删掉）：只清记录，不碰磁盘
                if ((int)($row['status'] ?? 1) === 0) {
                    Db::name('ao_real_file')->where('id', $id)->delete();
                    $result['deleted']++;
                    $result['warnings'][] = "记录 #{$id} 对应的文件已不存在，仅删除了记录";
                } else {
                    $result['errors'][] = "记录 #{$id} 的文件不存在或越出 upload 目录，已跳过";
                }
                continue;
            }
            if ($real === $root) {
                $result['errors'][] = '不允许删除 upload 根目录';
                continue;
            }

            if ($isDir) {
                if (!is_dir($real)) {
                    $result['errors'][] = "记录 #{$id} 不是目录，已跳过";
                    continue;
                }
                if (!self::deleteDirectory($real, $root, $result)) {
                    $result['errors'][] = "目录 #{$id} 未完全删除，保留记录以便重试";
                    continue;
                }
                // 目录本身 + 所有后代记录一起清理（LIKE 中转义 % 和 _）
                $prefix = rtrim($rel, '/') . '/';
                $escaped = addcslashes($prefix, '\\%_');
                $deleted = Db::name('ao_real_file')
                    ->whereRaw('path = ? OR path LIKE ?', [$rel, $escaped . '%'])
                    ->delete();
                $result['deleted'] += $deleted;
            } else {
                if (!is_file($real)) {
                    $result['errors'][] = "记录 #{$id} 不是普通文件，已跳过";
                    continue;
                }
                if (!@unlink($real)) {
                    $result['errors'][] = "记录 #{$id} 的文件删除失败（请检查权限）";
                    continue;
                }
                Db::name('ao_real_file')->where('id', $id)->delete();
                $result['deleted']++;
            }
        }

        return $result;
    }

    /**
     * 递归删除目录内容：先文件、后子目录（深到浅）
     */
    private static function deleteDirectory(string $dir, string $root, array &$result): bool
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
                if ($item->isLink()) {
                    $files[] = $path;
                } elseif ($item->isDir()) {
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
