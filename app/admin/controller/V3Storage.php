<?php

namespace app\admin\controller;

use app\admin\model\AdminV3ProjectModel;
use app\admin\model\AdminV3StorageModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v3\common\UploadFolder;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 存储管理 v3：ao_v3_storage 后台维护（独立 menu，与 ao_oss 无关）
 *
 * 一项目一存储：每个存储项通过 project_id 绑定一个 ao_v3_project 项目；
 * 上传文件夹 upload_folder 也在存储项里维护（为空回退 name）。
 * 字段刻意与 ao_oss 对齐，使 v3 直传能把本表行直接当作 V1 upload_file 的 $proc。
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

        $model = new AdminV3StorageModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $projectNames = [];
        foreach (AdminV3ProjectModel::field('id, name')->select() as $p) {
            $projectNames[(int)$p['id']] = (string)$p['name'];
        }

        $items = [];
        foreach ($list['data'] as $item) {
            $pid = (int)($item['project_id'] ?? 0);
            $items[] = [
                'id' => $item['id'],
                'project_id' => $pid,
                'project_name' => $projectNames[$pid] ?? ('已删除(' . $pid . ')'),
                'name' => $item['name'],
                'type' => $item['type'],
                'main_type' => $item['main_type'],
                'token' => $item['token'],
                'token_short' => mb_strlen((string)$item['token']) > 16
                    ? mb_substr((string)$item['token'], 0, 16) . '...'
                    : (string)$item['token'],
                'oss_type' => $item['oss_type'],
                'oss_tag' => $item['oss_tag'],
                'url' => $item['url'],
                'ext' => $item['ext'],
                'size' => $item['size'],
                'upload_folder' => (string)($item['upload_folder'] ?? ''),
                'status' => $item['status'],
            ];
        }

        // 项目下拉：只列出尚未绑定存储的 V3 项目（一项目一存储）
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
     * 校验项目存在、未被其它存储占用
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
            Ret::Fail(400, null, '该项目已绑定存储（一项目一存储），请先修改或删除已有存储');
        }
    }

    private function readUploadFolder(string $method): string
    {
        $raw = $method === 'put'
            ? (string)Input::Put('upload_folder', false)
            : (string)Input::Post('upload_folder', false);
        $folder = UploadFolder::normalize($raw);
        if (!UploadFolder::isValid($folder)) {
            Ret::Fail(400, null, '上传文件夹不合法：仅允许字母/数字/下划线/连字符，多级用 / 分隔，不能用 . 或 .. 或绝对路径');
        }
        return $folder;
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '存储名称不能为空');
        }
        $projectId = Input::PutInt('project_id', 0);
        $this->assertProject($projectId);
        $uploadFolder = $this->readUploadFolder('put');

        // token 为直传定位用的存储标识；留空则自动生成
        $token = trim((string)Input::Put('token', false));
        if ($token === '') {
            $token = md5(uniqid('v3s_', true) . $projectId . microtime());
        }
        // 该标识会原样写入 ao_attachment_token.oss_token 与 ao_attachment.token（均 varchar(64)），
        // 超长会在签发/落库阶段报错或被截断，故在此限制长度
        if (mb_strlen($token) > 64) {
            Ret::Fail(400, null, '存储标识不能超过 64 个字符');
        }
        if (AdminV3StorageModel::where('token', $token)->field('id')->find()) {
            Ret::Fail(400, null, '存储标识已存在');
        }

        AdminV3StorageModel::create([
            'project_id' => $projectId,
            'name' => $name,
            'type' => Input::Put('type', 'local'),
            'main_type' => Input::Put('main_type', 'local'),
            'code' => Input::Put('code', ''),
            'token' => $token,
            'oss_type' => Input::Put('oss_type', 'none'),
            'oss_tag' => Input::Put('oss_tag', 'default'),
            'url' => Input::Put('url', ''),
            'ext' => Input::Put('ext', 'jpg,png,gif,bmp,jpeg'),
            'size' => Input::PutInt('size', 16384),
            'upload_folder' => $uploadFolder,
            'status' => Input::PutInt('status', 1),
        ]);
        Ret::Success(0, ['token' => $token], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $item = AdminV3StorageModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '存储不存在');
        }

        $data = [];
        if (request()->has('project_id', 'post')) {
            $projectId = Input::PostInt('project_id', 0);
            $this->assertProject($projectId, $id);
            $data['project_id'] = $projectId;
        }
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('type', 'post')) $data['type'] = Input::Post('type');
        if (request()->has('main_type', 'post')) $data['main_type'] = Input::Post('main_type');
        if (request()->has('code', 'post')) $data['code'] = Input::Post('code');
        if (request()->has('oss_type', 'post')) $data['oss_type'] = Input::Post('oss_type');
        if (request()->has('oss_tag', 'post')) $data['oss_tag'] = Input::Post('oss_tag');
        if (request()->has('url', 'post')) $data['url'] = Input::Post('url');
        if (request()->has('ext', 'post')) $data['ext'] = Input::Post('ext');
        if (request()->has('size', 'post')) $data['size'] = Input::PostInt('size');
        if (request()->has('upload_folder', 'post')) {
            $data['upload_folder'] = $this->readUploadFolder('post');
        }
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

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
            Ret::Fail(404, null, '存储不存在');
        }
        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
