<?php

namespace app\admin\controller;

use app\admin\model\AdminCertUrlModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;
use Input;
use Ret;

class CertUrl extends CommonController
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

        $model = new AdminCertUrlModel();
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $html = Layout::begin('证书URL', 'cert', 'cert_url');
        $html .= <<<HTML
    <div class="toolbar">
        <h2>证书URL</h2>
        <button class="btn btn-primary" onclick="openCreate()">新增URL</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>证书名称</th>
                <th>CRT URL</th>
                <th>KEY URL</th>
                <th>备注</th>
                <th>自动</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
HTML;
        foreach ($list as $item) {
            $autoText = $item['auto'] == 1 ? '是' : '否';
            $html .= <<<ROW
            <tr>
                <td>{$item['id']}</td>
                <td>{$item['cert']}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$item['url_crt']}</td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{$item['url_key']}</td>
                <td>{$item['remark']}</td>
                <td>{$autoText}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['cert']}', '{$item['url_crt']}', '{$item['url_key']}', '{$item['remark']}', {$item['auto']})">编辑</button>
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROW;
        }
        $html .= <<<HTML
        </tbody>
    </table>
HTML;
        $html .= Layout::pagination($currentPage, $totalPages, '/admin/cert_url');
        $html .= <<<HTML
<div class="modal" id="certUrlModal">
    <div class="modal-box">
        <h3 id="modalTitle">新增URL</h3>
        <input type="hidden" id="editId" value="">
        <div class="form-group">
            <label>证书名称</label>
            <input type="text" id="formCert" placeholder="请输入证书名称">
        </div>
        <div class="form-group">
            <label>CRT URL</label>
            <input type="text" id="formUrlCrt" placeholder="https://example.com/cert.crt">
        </div>
        <div class="form-group">
            <label>KEY URL</label>
            <input type="text" id="formUrlKey" placeholder="https://example.com/cert.key">
        </div>
        <div class="form-group">
            <label>备注</label>
            <input type="text" id="formRemark" placeholder="请输入备注">
        </div>
        <div class="form-group">
            <label>自动下发</label>
            <select id="formAuto">
                <option value="1">是</option>
                <option value="0">否</option>
            </select>
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeModal()">取消</button>
            <button class="btn btn-primary" onclick="submitForm()">确定</button>
        </div>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('certUrlModal').classList.remove('show');
}
function openCreate() {
    document.getElementById('modalTitle').textContent = '新增URL';
    document.getElementById('editId').value = '';
    document.getElementById('formCert').value = '';
    document.getElementById('formUrlCrt').value = '';
    document.getElementById('formUrlKey').value = '';
    document.getElementById('formRemark').value = '';
    document.getElementById('formAuto').value = '1';
    document.getElementById('certUrlModal').classList.add('show');
}
function openEdit(id, cert, urlCrt, urlKey, remark, auto) {
    document.getElementById('modalTitle').textContent = '编辑URL';
    document.getElementById('editId').value = id;
    document.getElementById('formCert').value = cert;
    document.getElementById('formUrlCrt').value = urlCrt;
    document.getElementById('formUrlKey').value = urlKey;
    document.getElementById('formRemark').value = remark;
    document.getElementById('formAuto').value = auto;
    document.getElementById('certUrlModal').classList.add('show');
}
function submitForm() {
    var id = document.getElementById('editId').value;
    var cert = document.getElementById('formCert').value;
    var url_crt = document.getElementById('formUrlCrt').value;
    var url_key = document.getElementById('formUrlKey').value;
    var remark = document.getElementById('formRemark').value;
    var auto = document.getElementById('formAuto').value;
    if (!cert) { alert('证书名称不能为空'); return; }

    var xhr = new XMLHttpRequest();
    var method = id ? 'POST' : 'PUT';
    xhr.open(method, '/admin/cert_url', true);
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
    var params = 'cert=' + encodeURIComponent(cert) + '&url_crt=' + encodeURIComponent(url_crt) + '&url_key=' + encodeURIComponent(url_key) + '&remark=' + encodeURIComponent(remark) + '&auto=' + auto;
    if (id) params += '&id=' + id;
    xhr.send(params);
}
function doDelete(id) {
    if (!confirm('确定要删除该URL吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('DELETE', '/admin/cert_url', true);
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
        $cert = request()->put('cert');
        $url_crt = request()->put('url_crt', '');
        $url_key = request()->put('url_key', '');
        $remark = request()->put('remark', '');
        $auto = intval(request()->put('auto', '0')) ?: 0;

        if (empty($cert)) {
            Ret::Fail(400, null, '证书名称不能为空');
        }

        $model = AdminCertUrlModel::create([
            'cert' => $cert,
            'url_crt' => $url_crt,
            'url_key' => $url_key,
            'remark' => $remark,
            'auto' => $auto,
        ]);

        Ret::Success(0, ['id' => $model['id']], '创建成功');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $data = [];
        if (request()->has('cert', 'post')) {
            $data['cert'] = Input::Post('cert');
        }
        if (request()->has('url_crt', 'post')) {
            $data['url_crt'] = Input::Post('url_crt', false);
        }
        if (request()->has('url_key', 'post')) {
            $data['url_key'] = Input::Post('url_key', false);
        }
        if (request()->has('remark', 'post')) {
            $data['remark'] = Input::Post('remark', false);
        }
        if (request()->has('auto', 'post')) {
            $data['auto'] = Input::PostInt('auto');
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

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }
}