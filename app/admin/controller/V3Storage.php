<?php

namespace app\admin\controller;

use app\admin\model\AdminOssAliyunModel;
use app\admin\model\AdminV3ProjectModel;
use app\admin\model\AdminV3StorageModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\common\UploadFolder;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 存储管理 v3：ao_v3_storage 后台维护（独立 menu）
 *
 * 这里的每一项就是一个 Bucket：一个 V3 项目对应一个 Bucket，Bucket 自身描述
 *   - 落盘 Path（相对 upload/，留空回退 Bucket 名称）
 *   - 允许上传的后缀、单文件大小上限
 *   - 存本地（local）还是传远程（oss）
 *   - 远程时用哪套 OSS 凭据（oss_tag → ao_oss_aliyun，仅凭据仓库）
 * 不再包含 ao_oss 的 token / code / main_type 等历史概念。
 */
class V3Storage extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        switch ($method) {
            case 'GET':
                return $this->page();
            case 'POST':
                return $this->update();
            case 'PUT':
                return $this->create();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $search = input('get.search', '');

        $query = AdminV3StorageModel::order('id', 'desc');
        if (!empty($search)) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $projectNames = [];
        foreach (AdminV3ProjectModel::field('id, name')->select() as $p) {
            $projectNames[(int)$p['id']] = (string)$p['name'];
        }
        // 凭据 tag → 实际 bucket，便于列表判断远程落点
        $credBuckets = [];
        foreach (AdminOssAliyunModel::field('tag, bucket')->select() as $c) {
            $credBuckets[(string)$c['tag']] = (string)$c['bucket'];
        }

        $items = [];
        foreach ($list['data'] as $item) {
            $pid = (int)($item['project_id'] ?? 0);
            $tag = (string)($item['oss_tag'] ?? '');
            $items[] = [
                'id' => $item['id'],
                'project_id' => $pid,
                'project_name' => $projectNames[$pid] ?? ('已删除(' . $pid . ')'),
                'name' => (string)$item['name'],
                'path' => (string)($item['path'] ?? ''),
                'ext' => (string)$item['ext'],
                'size' => (int)$item['size'],
                'type' => (string)$item['type'],
                'oss_tag' => $tag,
                'oss_bucket' => $tag !== '' ? ($credBuckets[$tag] ?? '') : '',
                'cred_invalid' => $tag !== '' && !isset($credBuckets[$tag]),
                'url' => (string)$item['url'],
                'status' => $item['status'],
            ];
        }

        // 项目下拉：只列出尚未绑定 Bucket 的 V3 项目（一项目一 Bucket）
        $bound = AdminV3StorageModel::where('project_id', '>', 0)->column('project_id');
        $boundIds = array_map('intval', $bound);
        $projects = [];
        foreach (AdminV3ProjectModel::order('id', 'desc')->select() as $p) {
            $projects[] = [
                'id' => (int)$p['id'],
                'name' => (string)$p['name'],
                'bound' => in_array((int)$p['id'], $boundIds, true),
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/v3_storage', ['search' => $search], $limit);

        return $this->renderPage('v3_storage/index', [
            'list' => $items,
            'projects' => $projects,
            'search' => $search,
            'pagination' => $pagination,
        ], '存储管理 v3', 'v3', 'v3_storage');
    }

    /**
     * 远程凭据下拉：返回 ao_oss_aliyun 的可用 tag（凭据仓库，不是项目绑定）
     */
    public function credential_options()
    {
        $rows = AdminOssAliyunModel::field('tag, bucket, endpoint')->order('id', 'desc')->select();
        $options = [];
        foreach ($rows as $row) {
            $tag = (string)$row['tag'];
            if ($tag === '') {
                continue;
            }
            $options[] = [
                'tag' => $tag,
                'bucket' => (string)$row['bucket'],
                'endpoint' => (string)$row['endpoint'],
                'label' => $tag . '（桶 ' . $row['bucket'] . '）',
            ];
        }
        Ret::Success(0, $options);
    }

    /**
     * 校验项目存在、且未被其它 Bucket 占用
     */
    private function assertProject(int $projectId, int $excludeId = 0): void
    {
        if ($projectId <= 0) {
            Ret::Fail(400, null, '请选择所属项目');
        }
        if (AdminV3ProjectModel::findOrEmpty($projectId)->isEmpty()) {
            Ret::Fail(400, null, '所属项目不存在');
        }
        $query = AdminV3StorageModel::where('project_id', $projectId);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->field('id')->find()) {
            Ret::Fail(400, null, '该项目已有 Bucket（一项目一 Bucket），请先修改或删除已有项');
        }
    }

    /**
     * 落盘 Path：相对 upload/ 的安全目录段，留空表示回退 Bucket 名称
     */
    private function readPath(string $method): string
    {
        $raw = $method === 'put'
            ? (string)Input::Put('path', false)
            : (string)Input::Post('path', false);
        $path = UploadFolder::normalize($raw);
        if (!UploadFolder::isValid($path)) {
            Ret::Fail(400, null, 'Path 不合法：仅允许字母/数字/下划线/连字符，多级用 / 分隔，不能用 . 或 .. 或绝对路径');
        }
        if (mb_strlen($path) > 100) {
            Ret::Fail(400, null, 'Path 不能超过 100 个字符');
        }
        return $path;
    }

    /**
     * 允许上传的后缀：逗号分隔的安全后缀列表，统一小写
     */
    private function readExt(string $method): string
    {
        $raw = $method === 'put'
            ? (string)Input::Put('ext', false)
            : (string)Input::Post('ext', false);
        $raw = strtolower(str_replace(['，', ' '], [',', ''], trim($raw)));
        if ($raw === '') {
            Ret::Fail(400, null, '请填写允许上传的后缀');
        }
        $exts = [];
        foreach (explode(',', $raw) as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9]{1,16}$/', $one)) {
                Ret::Fail(400, null, '后缀不合法：' . $one . '（仅允许字母数字，多个用英文逗号分隔）');
            }
            $exts[$one] = true;
        }
        if (empty($exts)) {
            Ret::Fail(400, null, '请填写允许上传的后缀');
        }
        return implode(',', array_keys($exts));
    }

    /**
     * 类型：local 存本站 / oss 传远程
     */
    private function readType(string $method): string
    {
        $type = $method === 'put'
            ? (string)Input::Put('type', false)
            : (string)Input::Post('type', false);
        if (!in_array($type, ['local', 'oss'], true)) {
            Ret::Fail(400, null, '存储类型只能是 local（本站）或 oss（远程）');
        }
        return $type;
    }

    /**
     * 远程凭据：必须是 ao_oss_aliyun 里存在的 tag；本地类型忽略
     */
    private function readOssTag(string $type, string $method): string
    {
        if ($type !== 'oss') {
            return '';
        }
        $tag = $method === 'put'
            ? (string)Input::Put('oss_tag', false)
            : (string)Input::Post('oss_tag', false);
        $tag = trim($tag);
        if ($tag === '') {
            Ret::Fail(400, null, '远程存储必须选择 OSS 凭据');
        }
        if (!AdminOssAliyunModel::where('tag', $tag)->field('id')->find()) {
            Ret::Fail(400, null, '选择的 OSS 凭据不存在');
        }
        return $tag;
    }

    private function create()
    {
        $name = trim((string)Input::Put('name', false));
        if ($name === '') {
            Ret::Fail(400, null, 'Bucket 名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            Ret::Fail(400, null, 'Bucket 名称不能超过 100 个字符');
        }
        $projectId = Input::PutInt('project_id', 0);
        $this->assertProject($projectId);

        $type = $this->readType('put');
        $ossTag = $this->readOssTag($type, 'put');
        $url = rtrim(trim((string)Input::Put('url', false)), '/');
        if ($type === 'oss' && $url === '') {
            Ret::Fail(400, null, '远程存储必须填写访问域名（URL 前缀）');
        }

        AdminV3StorageModel::create([
            'project_id' => $projectId,
            'name' => $name,
            'path' => $this->readPath('put'),
            'ext' => $this->readExt('put'),
            'size' => max(1, Input::PutInt('size', 16384)),
            'type' => $type,
            'oss_type' => $type === 'oss' ? 'aliyun' : 'none',
            'oss_tag' => $ossTag,
            'url' => $url,
            'status' => Input::PutInt('status', 1),
        ]);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $item = AdminV3StorageModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Bucket 不存在');
        }

        $data = [];
        if (request()->has('project_id', 'post')) {
            $projectId = Input::PostInt('project_id', 0);
            $this->assertProject($projectId, $id);
            $data['project_id'] = $projectId;
        }
        if (request()->has('name', 'post')) {
            $name = trim((string)Input::Post('name', false));
            if ($name === '') {
                Ret::Fail(400, null, 'Bucket 名称不能为空');
            }
            if (mb_strlen($name) > 100) {
                Ret::Fail(400, null, 'Bucket 名称不能超过 100 个字符');
            }
            $data['name'] = $name;
        }
        if (request()->has('path', 'post')) $data['path'] = $this->readPath('post');
        if (request()->has('ext', 'post')) $data['ext'] = $this->readExt('post');
        if (request()->has('size', 'post')) $data['size'] = max(1, Input::PostInt('size'));
        if (request()->has('type', 'post')) $data['type'] = $this->readType('post');
        if (request()->has('url', 'post')) $data['url'] = rtrim(trim((string)Input::Post('url', false)), '/');
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        // 类型或凭据有变时，二者一起收敛：oss 必须有 tag，local 清空凭据
        $finalType = $data['type'] ?? (string)$item['type'];
        if (array_key_exists('type', $data) || request()->has('oss_tag', 'post')) {
            $data['oss_tag'] = $this->readOssTag($finalType, 'post');
            $data['oss_type'] = $finalType === 'oss' ? 'aliyun' : 'none';
        }
        $finalUrl = $data['url'] ?? (string)$item['url'];
        if ($finalType === 'oss' && $finalUrl === '') {
            Ret::Fail(400, null, '远程存储必须填写访问域名（URL 前缀）');
        }

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $item = AdminV3StorageModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Bucket 不存在');
        }
        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
