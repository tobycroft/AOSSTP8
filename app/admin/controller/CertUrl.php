<?php

namespace app\admin\controller;

use app\admin\model\AdminCertUrlModel;
use app\admin\model\AdminCertModel;
use app\admin\model\AdminCertWebsiteModel;
use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\v1\cert\action\SiteAction;
use app\v1\cert\action\ConfigAction;
use BaseController\CommonController;
use Input;
use Ret;
use think\Exception;
use tobycroft\Bt\Site;
use tobycroft\Bt\Base;

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
                <td>{$item['remark']}</td>
                <td>{$autoText}</td>
                <td>
                    <button class="btn btn-sm btn-edit" onclick="openEdit({$item['id']}, '{$item['cert']}', '{$item['url_crt']}', '{$item['url_key']}', '{$item['remark']}', {$item['auto']})">编辑</button>
                    <button class="btn btn-sm btn-primary" onclick="doUpdateSSL({$item['id']}, '{$item['cert']}')">更新</button>
                    <button class="btn btn-sm btn-edit" onclick="showKeys({$item['id']})">查看</button>
ROW;
            if ($item['auto'] == 1) {
                $html .= <<<BTN
                    <button class="btn btn-sm btn-success" onclick="doAutoSSL({$item['id']}, '{$item['cert']}')">自动下发</button>
BTN;
            }
            $html .= <<<ROWEND
                    <button class="btn btn-sm btn-del" onclick="doDelete({$item['id']})">删除</button>
                </td>
            </tr>
ROWEND;
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

<div class="modal" id="keyModal">
    <div class="modal-box modal-box-wide">
        <h3>证书密钥</h3>
        <div style="margin-bottom:8px;font-size:13px;color:#555;">公钥</div>
        <pre id="keyPublic" style="background:#f5f5f5;padding:12px;border-radius:4px;font-size:12px;line-height:1.6;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all;margin-bottom:16px;"></pre>
        <div style="margin-bottom:8px;font-size:13px;color:#555;">私钥</div>
        <pre id="keyPrivate" style="background:#f5f5f5;padding:12px;border-radius:4px;font-size:12px;line-height:1.6;max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-all;margin-bottom:16px;"></pre>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeKeyModal()">关闭</button>
        </div>
    </div>
</div>

<style>
.modal-box-wide { width: 720px; max-width: 95%; }
.btn-primary { background: #1677ff; color: #fff; }
.btn-primary:hover { background: #4096ff; }
.btn-success { background: #52c41a; color: #fff; }
.btn-success:hover { background: #73d13d; }
</style>

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
function doUpdateSSL(id, cert) {
    if (!confirm('确定要更新证书「' + cert + '」的SSL吗？将从URL拉取最新证书。')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/cert_url/updateSSL', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                alert('更新成功');
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    xhr.send('id=' + id);
}
function doAutoSSL(id, cert) {
    if (!confirm('确定要对证书「' + cert + '」执行自动下发吗？')) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/cert_url/autoSSL', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                var msg = '自动下发完成\n成功: ' + res.data.success + ' | 失败: ' + res.data.fail;
                var detail = res.data.detail || [];
                for (var i = 0; i < detail.length; i++) {
                    var d = detail[i];
                    msg += '\n  [' + (d.success ? 'OK' : 'XX') + '] ' + d.website;
                    if (d.error) msg += ' - ' + d.error;
                }
                alert(msg);
                location.reload();
            } else {
                alert(res.echo);
            }
        }
    };
    xhr.send('id=' + id);
}
function showKeys(id) {
    document.getElementById('keyPublic').textContent = '加载中...';
    document.getElementById('keyPrivate').textContent = '加载中...';
    document.getElementById('keyModal').classList.add('show');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/cert_url/getKey', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('admin-token', localStorage.getItem('admin_token'));
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            var res = JSON.parse(xhr.responseText);
            if (res.code == 0) {
                document.getElementById('keyPublic').textContent = res.data.publickey || '(空)';
                document.getElementById('keyPrivate').textContent = res.data.privatekey || '(空)';
            } else {
                document.getElementById('keyPublic').textContent = '获取失败: ' + (res.echo || '未知错误');
                document.getElementById('keyPrivate').textContent = '获取失败: ' + (res.echo || '未知错误');
            }
        }
    };
    xhr.send('id=' + id);
}
function closeKeyModal() {
    document.getElementById('keyModal').classList.remove('show');
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

    public function updateSSL()
    {
        $id = intval(request()->post('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        try {
            $url_crt = $item['url_crt'];
            $url_key = $item['url_key'];

            if (empty($url_crt) || empty($url_key)) {
                Ret::Fail(400, null, 'CRT URL 或 KEY URL 为空');
            }

            $publickey = file_get_contents($url_crt);
            $privatekey = file_get_contents($url_key);

            if (empty($publickey) || empty($privatekey)) {
                Ret::Fail(500, null, '证书获取失败');
            }

            $item->save([
                'publickey' => $publickey,
                'privatekey' => $privatekey,
            ]);

            Ret::Success(0, [], '更新成功');
        } catch (Exception $e) {
            Ret::Fail(500, null, '证书获取失败: ' . $e->getMessage());
        }
    }

    public function getKey()
    {
        $id = Input::PostInt('id');

        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        Ret::Success(0, [
            'publickey' => $item['publickey'] ?: '',
            'privatekey' => $item['privatekey'] ?: '',
        ]);
    }

    public function autoSSL()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $urlModel = new AdminCertUrlModel();
        $item = $urlModel->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }
        if ($item['auto'] != 1) {
            Ret::Fail(401, null, '本证书自动下发功能不可用');
        }

        $name = $item['cert'];

        $certModel = new AdminCertModel();
        $cert = $certModel->where('appname', $name)->where('status', 1)->find();
        if (!$cert) {
            Ret::Fail(404, null, '未找到证书项目配置');
        }

        try {
            SiteAction::updateSiteListWhichHadSSL($cert['bt_api'], $cert['bt_key']);
        } catch (Exception $e) {
        }

        $ssl = SiteAction::updatessl($name);

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        $sites = AdminCertWebsiteModel::where('type', 'web')
            ->where('cert_name', $name)
            ->where('status', 1)
            ->select();

        foreach ($sites as $site) {
            $bt_site = new Site($site['api'], $site['key'], './');
            $ret = $bt_site->setSSL(1, $site['website'], $ssl['key'], $ssl['crt']);
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $cert['appname'],
                    'type' => $site['type'],
                    'success' => 1,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['success']++;
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => true,
                ];
            } else {
                AdminCertLogModel::create([
                    'appname' => $cert['appname'],
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $bt_site->getError() ?: '未知错误';
                if ($ret === null) {
                    AdminCertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }

        $panelSites = AdminCertWebsiteModel::where('type', 'panel')
            ->where('cert_name', $name)
            ->where('status', 1)
            ->select();

        foreach ($panelSites as $site) {
            $catchError = null;
            $bt_base = null;
            try {
                $bt_base = new Base($site['api'], $site['key'], './');
                $ret = $bt_base->httpPostCookie(ConfigAction::setPanelSSL, [
                    'privateKey' => $ssl['key'],
                    'certPem' => $ssl['crt'],
                ], 15);
            } catch (Exception $e) {
                $ret = false;
                $catchError = $e->getMessage();
            }
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $cert['appname'],
                    'type' => $site['type'],
                    'success' => 1,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['success']++;
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => true,
                ];
            } else {
                AdminCertLogModel::create([
                    'appname' => $cert['appname'],
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $catchError ?? $bt_base->getError() ?: '面板SSL部署失败';
                if ($ret === null) {
                    AdminCertWebsiteModel::where('id', $site['id'])->update(['status' => 0]);
                }
                $rets['detail'][] = [
                    'type' => $site['type'],
                    'website' => $site['website'],
                    'success' => false,
                    'error' => $error,
                ];
            }
        }

        Ret::Success(0, $rets, '自动下发完成');
    }
}