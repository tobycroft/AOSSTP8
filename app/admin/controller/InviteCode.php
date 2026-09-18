<?php

namespace app\admin\controller;

use app\admin\model\AdminInviteCodeModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class InviteCode extends CommonController
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
            case 'PUT':
                return $this->create();
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $search = input('get.search', '');
        $page = input('get.page', 1, 'intval');
        $limit = 15;

        $model = new AdminInviteCodeModel();
        $where = [];
        if ($search !== '') {
            $where = [['code', 'like', '%' . $search . '%']];
        }
        $total = $model->where($where)->count();
        $totalPages = max(1, (int)ceil($total / $limit));

        $list = $model->where($where)->order('id', 'desc')->page($page, $limit)->select();

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'code' => $item['code'],
                'status' => $item['status'],
                'used_by' => $item['used_by'],
                'used_time' => $item['used_time'] ?? '',
                'remark' => $item['remark'],
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination($page, $totalPages, '/admin/invite_code', $search !== '' ? ['search' => $search] : []);

        return $this->renderPage('invite_code/index', [
            'list' => $items,
            'pagination' => $pagination,
            'search' => $search,
        ], '邀请码管理', 'user', 'invite_code');
    }

    /**
     * 批量生成邀请码
     */
    private function create()
    {
        $count = intval(request()->put('count', '1'));
        $count = max(1, min(100, $count));
        $remark = strval(request()->put('remark', ''));

        // 去除易混淆字符（0/1/I/L/O）
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $model = new AdminInviteCodeModel();

        $codes = [];
        $maxRetry = $count * 10;
        while (count($codes) < $count && $maxRetry-- > 0) {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!$model->api_find_code($code)->isEmpty()) {
                continue;
            }
            AdminInviteCodeModel::create([
                'code' => $code,
                'status' => 1,
                'remark' => $remark,
            ]);
            $codes[] = $code;
        }

        Ret::Success(0, ['codes' => $codes], '生成成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminInviteCodeModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    public function toggleStatus()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminInviteCodeModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        if ($item['status'] == 2) {
            Ret::Fail(406, null, '邀请码已使用，无法修改状态');
        }

        $newStatus = $item['status'] == 1 ? 0 : 1;
        $item->save(['status' => $newStatus]);

        $statusText = $newStatus == 1 ? '启用' : '禁用';
        Ret::Success(0, ['status' => $newStatus], "状态已切换为{$statusText}");
    }

    public function batchDelete()
    {
        $ids = request()->post('ids');
        if (empty($ids)) {
            Ret::Fail(400, null, '缺少参数[ids]');
        }

        $ids = json_decode($ids, true);
        if (!is_array($ids) || empty($ids)) {
            Ret::Fail(400, null, '参数格式错误');
        }

        $count = 0;
        $model = new AdminInviteCodeModel();
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id) {
                $item = $model->findOrEmpty($id);
                if (!$item->isEmpty()) {
                    $item->delete();
                    $count++;
                }
            }
        }

        Ret::Success(0, ['count' => $count], "已删除 {$count} 条记录");
    }
}
