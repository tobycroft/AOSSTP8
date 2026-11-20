<?php

namespace app\admin\controller;

use app\admin\model\AdminMailTemplateModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

/**
 * 邮件模板管理：统一维护验证码/营销邮件的主题与正文，供 v3 邮件接口引用。
 */
class MailTemplate extends CommonController
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
        $search = input('get.search', '', 'trim');
        $type = input('get.type', '', 'trim');

        $model = new AdminMailTemplateModel();
        $query = $model->where('id', '>', 0);
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereOr('code', 'like', "%{$search}%")
                  ->whereOr('name', 'like', "%{$search}%");
            });
        }
        if (in_array($type, ['captcha', 'marketing'], true)) {
            $query->where('type', $type);
        }
        $list = $query->order('id', 'desc')->paginate($limit, false, ['page' => $page])->toArray();

        $extraParams = [];
        if (!empty($search)) $extraParams['search'] = $search;
        if ($type !== '') $extraParams['type'] = $type;
        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/mail_template', $extraParams, $limit);

        $pageItems = [];
        foreach ($list['data'] as $row) {
            $row['type_label'] = AdminMailTemplateModel::typeLabel((string)$row['type']);
            $row['json'] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
            $pageItems[] = $row;
        }

        return $this->renderPage('mail_template/index', [
            'list'       => $pageItems,
            'search'     => $search,
            'type'       => $type,
            'pagination' => $pagination,
        ], '邮件模板', 'mail', 'mail_template');
    }

    /** 新建模板 */
    private function create()
    {
        $data = $this->collect('PUT');
        if (AdminMailTemplateModel::codeExists((string)$data['code'])) {
            Ret::Fail(400, null, '模板标识已存在');
        }
        AdminMailTemplateModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    /** 编辑模板 */
    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $model = new AdminMailTemplateModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '模板不存在');
        }

        $data = $this->collect('POST');
        if (AdminMailTemplateModel::codeExists((string)$data['code'], $id)) {
            Ret::Fail(400, null, '模板标识已存在');
        }
        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    /**
     * 按请求方法收集并校验表单数据
     */
    private function collect(string $method): array
    {
        $get = strtolower($method) === 'put' ? 'Put' : 'Post';
        $code = trim(Input::$get('code'));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $code)) {
            Ret::Fail(400, null, '模板标识须以字母开头，仅含字母/数字/下划线/短横线，最长 64 位');
        }
        $name = trim(Input::$get('name'));
        if ($name === '') {
            Ret::Fail(400, null, '模板名称不能为空');
        }
        $type = strtolower(Input::$get('type', false));
        if (!in_array($type, ['captcha', 'marketing'], true)) {
            Ret::Fail(400, null, '模板类型不合法');
        }
        $subject = trim(Input::$get('subject'));
        if ($subject === '' || mb_strlen($subject) > 200) {
            Ret::Fail(400, null, '模板主题不能为空且不得超过 200 字符');
        }
        $body = Input::$get('body');
        if ($body === '' || strlen($body) > 1024 * 1024) {
            Ret::Fail(400, null, '模板正文不能为空且不得超过 1 MB');
        }

        $text = $subject . $body;
        if ($type === 'captcha' && strpos($text, '{code}') === false) {
            Ret::Fail(400, null, '验证码模板须在主题或正文中包含 {code} 占位符');
        }
        if ($type === 'marketing' && preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $text) !== 1) {
            Ret::Fail(400, null, '营销模板须在主题或正文中至少包含一个 {变量名} 占位符');
        }
        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $text, $m)) {
            foreach ($m[1] as $var) {
                if ($type === 'marketing' && $var === 'code') {
                    Ret::Fail(400, null, '营销模板请勿使用 {code}，验证码模板才使用该占位符');
                }
            }
        }

        return [
            'code'    => $code,
            'name'    => $name,
            'type'    => $type,
            'subject' => $subject,
            'body'    => $body,
            'is_html' => (int)input('is_html', 1, 'intval') === 1 ? 1 : 0,
            'status'  => (int)input('status', 1, 'intval') === 1 ? 1 : 0,
        ];
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }
        $model = new AdminMailTemplateModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '模板不存在');
        }
        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}
