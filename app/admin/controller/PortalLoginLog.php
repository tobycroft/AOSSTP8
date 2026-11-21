<?php

namespace app\admin\controller;

use app\admin\model\AdminPortalLoginLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Ret;

/**
 * 门户用户登录记录（含登录失败记录）
 *
 * 数据来源 ao_login_log：门户登录成功与失败都会落一条记录，
 * 失败记录带原因与尝试密码，便于排查撞库、爆破等异常。
 */
class PortalLoginLog extends CommonController
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
            case 'DELETE':
                return $this->delete();
        }
        Ret::Fail(405, null, '不支持的请求方法');
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $search = input('get.search', '', 'trim');
        // 结果筛选：all=全部 success=成功 fail=失败
        $result = input('get.result', 'all', 'trim');
        if (!in_array($result, ['all', 'success', 'fail'], true)) {
            $result = 'all';
        }

        $model = new AdminPortalLoginLogModel();
        $query = $model->where('id', '>', 0);
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereOr('username', 'like', "%{$search}%")
                    ->whereOr('ip', 'like', "%{$search}%");
            });
        }
        if ($result === 'success') {
            $query->where('success', '=', 1);
        } elseif ($result === 'fail') {
            $query->where('success', '=', 0);
        }
        $query->order('id', 'desc');
        $list = $query->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $extraParams = [];
        if (!empty($search)) {
            $extraParams['search'] = $search;
        }
        if ($result !== 'all') {
            $extraParams['result'] = $result;
        }

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'uid' => $item['uid'],
                'username' => $item['username'] ?? '',
                'ip' => $item['ip'] ?? '',
                'success' => $item['success'],
                'reason' => $item['reason'] ?? '',
                'password' => $item['password'] ?? '',
                'date' => $item['date'] ?? '',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/portal_login_log', $extraParams, $limit);

        return $this->renderPage('portal_login_log/index', [
            'list' => $items,
            'search' => $search,
            'result' => $result,
            'stats' => $this->stats(),
            'pagination' => $pagination,
        ], '登录记录', 'user', 'portal_login_log');
    }

    /**
     * 顶部统计：门户登录尝试、成功、失败、今日（不受筛选影响）
     */
    private function stats(): array
    {
        $today = date('Y-m-d 00:00:00');
        return [
            'total' => intval(AdminPortalLoginLogModel::where('id', '>', 0)->count()),
            'success' => intval(AdminPortalLoginLogModel::where('success', '=', 1)->count()),
            'fail' => intval(AdminPortalLoginLogModel::where('success', '=', 0)->count()),
            'today' => intval(AdminPortalLoginLogModel::where('date', '>=', $today)->count()),
            'today_fail' => intval(AdminPortalLoginLogModel::where('success', '=', 0)->where('date', '>=', $today)->count()),
        ];
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminPortalLoginLogModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
