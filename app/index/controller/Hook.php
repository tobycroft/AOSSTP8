<?php

namespace app\index\controller;

use app\index\model\HookModel;
use app\index\utils\ConsoleLayout;
use app\index\utils\UserAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class Hook extends CommonController
{
    public function initialize()
    {
        parent::initialize();
        UserAuth::requireLogin();
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
        $uid = intval(UserAuth::getLoginUser()['id']);
        $page = input('get.page', 1, 'intval');
        $limit = 15;
        $tag = input('get.tag', '', 'trim');

        $model = new HookModel();
        $list = $model->api_list_by_uid($uid, $tag, $page, $limit);

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'tag' => $item['tag'],
                'branch' => $item['branch'] ?: 'master',
                'remark' => $item['remark'] ?? '',
                'remark_short' => mb_strlen($item['remark'] ?? '') > 20 ? mb_substr($item['remark'], 0, 20) . '...' : ($item['remark'] ?: '-'),
                'mode' => $item['mode'],
                'method' => $item['method'],
                'domain' => $item['domain'] ?? '',
                'key' => $item['key'] ?? '',
                'param' => $item['param'] ?? '',
                'full_url' => $item['full_url'] ?? '',
                'target' => $item['mode'] == 'direct' ? ($item['full_url'] ?: '-') : ($item['method'] . '://' . $item['domain']),
                'target_short' => mb_strlen($item['mode'] == 'direct' ? ($item['full_url'] ?? '') : ($item['method'] . '://' . ($item['domain'] ?? ''))) > 40 ? mb_substr($item['mode'] == 'direct' ? $item['full_url'] : ($item['method'] . '://' . $item['domain']), 0, 40) . '...' : ($item['mode'] == 'direct' ? ($item['full_url'] ?: '-') : ($item['method'] . '://' . ($item['domain'] ?: '-'))),
                'status' => $item['status'],
                'date' => $item['date'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/console/hook', $tag !== '' ? ['tag' => $tag] : [], $limit);

        return ConsoleLayout::render('hook/index', [
            'list' => $items,
            'tag' => $tag,
            'pagination' => $pagination,
        ], 'Hook 管理', 'hook', 'hook');
    }

    /**
     * 创建 Hook（归属当前登录用户）
     */
    private function create()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);

        $data = $this->readPayload();
        if (!empty($data['errors'])) {
            Ret::Fail(400, null, $data['errors'][0]);
        }

        $data['row']['uid'] = $uid;
        HookModel::create($data['row']);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new HookModel();
        $item = $model->api_find_uid($id, $uid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook 不存在');
        }

        $data = $this->readPayload();
        if (!empty($data['errors'])) {
            Ret::Fail(400, null, $data['errors'][0]);
        }

        $item->save($data['row']);
        Ret::Success(0, [], '更新成功');
    }

    /**
     * 读取并校验 Hook 表单字段
     * @return array{row: array, errors: string[]}
     */
    private function readPayload(): array
    {
        $isPut = request()->isPut();
        $read = function (string $name, $default = null) use ($isPut) {
            if ($isPut) {
                return request()->put($name, $default);
            }
            return request()->post($name, $default);
        };

        $errors = [];
        $tag = strval($read('tag', ''));
        if (empty($tag)) {
            $errors[] = 'Tag 不能为空';
        }

        $mode = strval($read('mode', 'aapanel'));
        if (!in_array($mode, ['direct', 'aapanel'])) {
            $errors[] = '模式无效';
        }

        $row = [
            'tag' => $tag,
            'branch' => strval($read('branch', 'master')) ?: 'master',
            'remark' => strval($read('remark', '')),
            'mode' => $mode,
            'method' => 'http',
            'domain' => '',
            'key' => '',
            'param' => '',
            'full_url' => '',
            'status' => intval($read('status', 1)),
        ];

        if ($mode == 'direct') {
            $full_url = strval($read('full_url', ''));
            if (empty($full_url)) {
                $errors[] = '完整 URL 不能为空';
            }
            $row['full_url'] = $full_url;
        } else {
            $method = strval($read('method', 'http'));
            if (!in_array($method, ['http', 'https', 'ssh'])) {
                $errors[] = '请求方法无效';
            }
            $domain = strval($read('domain', ''));
            if (empty($domain)) {
                $errors[] = '面板域名不能为空';
            }
            $key = strval($read('key', ''));
            if (empty($key)) {
                $errors[] = '面板密钥不能为空';
            }
            $row['method'] = $method;
            $row['domain'] = $domain;
            $row['key'] = $key;
            $row['param'] = strval($read('param', ''));
        }

        return ['row' => $row, 'errors' => $errors];
    }

    private function delete()
    {
        $uid = intval(UserAuth::getLoginUser()['id']);
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new HookModel();
        $item = $model->api_find_uid($id, $uid);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, 'Hook 不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
