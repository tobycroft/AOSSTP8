<?php

namespace app\admin\controller;

use app\admin\model\AdminExcelModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Excel extends CommonController
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
        $search = input('get.search', '');

        $model = new AdminExcelModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where('project', 'like', '%' . $search . '%');
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'project' => $item['project'] ?? '-',
                'md5' => $item['md5'] ?? '',
                'md5_short' => $item['md5'] ? substr($item['md5'], 0, 16) . '...' : '-',
                'date' => $item['date'] ?? '',
                'change_date' => $item['change_date'] ?? '',
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/excel', ['search' => $search], $limit);

        return $this->renderPage('excel/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'Excel缓存', 'tools', 'excel');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminExcelModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}