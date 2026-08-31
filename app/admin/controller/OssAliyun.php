<?php

namespace app\admin\controller;

use app\admin\model\AdminOssAliyunModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class OssAliyun extends CommonController
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

        $model = new AdminOssAliyunModel();
        $list = $model->api_list($page, $limit);

        $html = Layout::begin('阿里云OSS', 'project', 'oss_aliyun');
        $html .= <<<HTML
    <div class="toolbar">
        <h2>阿里云 OSS 配置</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增配置</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>标签</th>
                <th>AccessKey</th>
                <th>Endpoint</th>
                <th>Bucket</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list['data'] as $item) {
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['tag']}</td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['accesskey']}">{$item['accesskey']}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$item['endpoint']}">{$item['endpoint']}</td>
                <td>{$item['bucket']}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['tag']}', '{$item['accesskey']}', '{$item['accesssecret']}', '{$item['endpoint']}', '{$item['bucket']}')">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/oss_aliyun');
        $html .= <<<HTML
<div class="modal" id="ossAliyunModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增配置</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>标签</label>
            <input type="text" id="formTag" placeholder="例如: default">
        </div>
        <div class="form-group">
            <label>AccessKey</label>
            <input type="text" id="formAccesskey" placeholder="阿里云 AccessKey">
        </div>
        <div class="form-group">
            <label>AccessSecret</label>
            <input type="text" id="formAccesssecret" placeholder="阿里云 AccessSecret">
        </div>
        <div class="form-group">
            <label>Endpoint</label>
            <input type="text" id="formEndpoint" placeholder="例如: oss-ap-southeast-1-internal.aliyuncs.com">
        </div>
        <div class="form-group">
            <label>Bucket</label>
            <input type="text" id="formBucket" placeholder="Bucket 名称">
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" id="modalSubmit" onclick="submitForm()">确定</button>
        </div>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('ossAliyunModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增配置';
    document.getElementById('editId').value = '';
    document.getElementById('formTag').value = 'default';
    document.getElementById('formAccesskey').value = '';
    document.getElementById('formAccesssecret').value = '';
    document.getElementById('formEndpoint').value = '';
    document.getElementById('formBucket').value = '';
    document.getElementById('ossAliyunModal').classList.add('show');
}
function openEdit(id, tag, accesskey, accesssecret, endpoint, bucket) {
    document.getElementById('modalTitle').textContent = '编辑配置';
    document.getElementById('editId').value = id;
    document.getElementById('formTag').value = tag;
    document.getElementById('formAccesskey').value = accesskey;
    document.getElementById('formAccesssecret').value = accesssecret;
    document.getElementById('formEndpoint').value = endpoint;
    document.getElementById('formBucket').value = bucket;
    document.getElementById('ossAliyunModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var tag = document.getElementById('formTag').value;
    var accesskey = document.getElementById('formAccesskey').value;
    var accesssecret = document.getElementById('formAccesssecret').value;
    var endpoint = document.getElementById('formEndpoint').value;
    var bucket = document.getElementById('formBucket').value;
    if (!accesskey || !accesssecret || !endpoint || !bucket) { alert('请填写完整信息'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/oss_aliyun', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    var params = 'tag=' + encodeURIComponent(tag) + '&accesskey=' + encodeURIComponent(accesskey) + '&accesssecret=' + encodeURIComponent(accesssecret) + '&endpoint=' + encodeURIComponent(endpoint) + '&bucket=' + encodeURIComponent(bucket);
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该配置吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/oss_aliyun', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    xhr.send('id=' + id);
}
</script>
HTML;
        $html .= Layout::end();
        return response($html)->contentType('text/html');
    }

    private function create()
    {
        $accesskey = Input::Put('accesskey');
        if (empty($accesskey)) {
            Ret::Fail(400, null, 'AccessKey 不能为空');
        }

        $data = [
            'tag' => Input::Put('tag', 'default'),
            'accesskey' => $accesskey,
            'accesssecret' => Input::Put('accesssecret', ''),
            'endpoint' => Input::Put('endpoint', ''),
            'bucket' => Input::Put('bucket', ''),
        ];

        AdminOssAliyunModel::create($data);
        Ret::Success(0, [], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $data = [];
        if (request()->has('tag', 'post')) $data['tag'] = Input::Post('tag');
        if (request()->has('accesskey', 'post')) $data['accesskey'] = Input::Post('accesskey');
        if (request()->has('accesssecret', 'post')) $data['accesssecret'] = Input::Post('accesssecret');
        if (request()->has('endpoint', 'post')) $data['endpoint'] = Input::Post('endpoint');
        if (request()->has('bucket', 'post')) $data['bucket'] = Input::Post('bucket');

        $item->save($data);
        Ret::Success(0, [], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminOssAliyunModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '配置不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}