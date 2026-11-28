<?php

namespace app\admin\controller;

use app\admin\model\AdminCertUrlModel;
use app\admin\model\AdminCertModel;
use app\admin\model\AdminCertWebsiteModel;
use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use app\admin\action\ConfigAction;
use app\admin\action\IkuaiAction;
use app\admin\action\MailAction;
use app\admin\action\PveAction;
use app\admin\action\SiteAction;
use BaseController\CommonController;
use Input;
use Ret;
use Exception;
use tobycroft\Bt\Site;
use tobycroft\Bt\Base;

class CertUrl extends CommonController
{
    /**
     * 证书下载地址允许的 scheme。
     *
     * 只放行 HTTP(S)：file://、ftp://、php://、phar://、data:// 等协议包装器
     * 都能借 file_get_contents 读取本地文件或触发反序列化，一律拒绝。
     */
    private const CERT_URL_SCHEMES = ['http', 'https'];

    /**
     * 证书下载地址允许的主机白名单。
     *
     * 新增证书源域名时在此追加。刻意做成源码常量而非 system_param 配置：
     * 该值一旦可后台修改，SSRF 防护就退化成「攻击者自己填白名单」。
     *
     * 注意：ssl.tuuz.cc 解析到内网 10.0.0.182，是刻意部署的内网证书站，
     * 因此这里不能附加「禁止私网 IP」的规则，否则现有证书全部无法更新。
     */
    private const CERT_URL_HOSTS = ['ssl.tuuz.cc'];

    /** 单次拉取上限（字节）：证书 PEM 通常 < 10KB，1MB 足够且能防大响应打爆内存 */
    private const CERT_FETCH_MAX = 1048576;

    /** 拉取超时（秒）：证书站是内网直连，10 秒足够；超时避免请求挂死 */
    private const CERT_FETCH_TIMEOUT = 10;

    /** 证书 PEM 起始标记 */
    private const PEM_CRT_MARKER = '-----BEGIN CERTIFICATE-----';

    /** 私钥 PEM 起始标记：RSA / EC / PKCS#8 的 BEGIN 行不同，统一用前缀匹配 */
    private const PEM_KEY_MARKER = '-----BEGIN';

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

        $items = [];
        foreach ($list as $item) {
            $items[] = [
                'id' => $item['id'],
                'cert' => $item['cert'],
                'url_crt' => $item['url_crt'],
                'url_key' => $item['url_key'],
                'remark' => $item['remark'],
                'auto' => $item['auto'],
                'auto_text' => $item['auto'] == 1 ? '是' : '否',
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/cert_url');

        return $this->renderPage('cert_url/index', [
            'list' => $items,
            'pagination' => $pagination,
        ], '证书URL', 'cert', 'cert_url');
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

        // 地址允许留空（稍后再补），但一旦填写就必须是白名单内的 HTTP(S) 地址。
        // 这里是 SSRF 的唯一入口：封住写入端，下游所有读取点（含 v1 action）都不会再拿到恶意地址。
        $this->assertCertUrlOrFail($url_crt, 'CRT 地址');
        $this->assertCertUrlOrFail($url_key, 'KEY 地址');

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
            $this->assertCertUrlOrFail($data['url_crt'], 'CRT 地址');
        }
        if (request()->has('url_key', 'post')) {
            $data['url_key'] = Input::Post('url_key', false);
            $this->assertCertUrlOrFail($data['url_key'], 'KEY 地址');
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

    /**
     * 校验证书地址是否允许服务端拉取
     *
     * @param string $url   待校验地址
     * @param string $label 字段名，用于拼装可直接展示给管理员的错误说明
     * @return string|null  通过返回 null，否则返回错误说明
     */
    private function certUrlError(string $url, string $label): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return $label . ' 不能为空';
        }
        // 空白、控制字符与反斜杠都可能被用于干扰 host 解析，一律拒绝
        if (preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return $label . ' 含非法字符（空白、控制字符或反斜杠）';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme'])) {
            return $label . ' 必须是完整的 HTTP(S) 地址';
        }

        // 先判 scheme：file/ftp/php/phar 等包装器在这里就被拦下，报错也更贴切
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::CERT_URL_SCHEMES, true)) {
            return $label . ' 仅支持 http/https，当前为 ' . $scheme . '://';
        }

        if (empty($parts['host'])) {
            return $label . ' 缺少主机名，必须是完整的 HTTP(S) 地址';
        }

        // 带尾点的 FQDN 与大小写差异归一到同一形态再比对
        $host = strtolower(rtrim($parts['host'], '.'));
        if (!in_array($host, self::CERT_URL_HOSTS, true)) {
            return $label . ' 的主机 ' . $host . ' 不在允许列表（' . implode('、', self::CERT_URL_HOSTS) . '）内';
        }

        return null;
    }

    /**
     * 空值放行、非空必须合规，否则直接 400 并终止请求
     *
     * 用于单条操作（新增 / 编辑），保持「地址可以先留空、稍后再补」的原有用法。
     */
    private function assertCertUrlOrFail(string $url, string $label): void
    {
        if (trim($url) === '') {
            return;
        }
        $error = $this->certUrlError($url, $label);
        if ($error !== null) {
            Ret::Fail(400, null, $error);
        }
    }

    /**
     * 拉取 PEM 文本并校验内容
     *
     * 即便地址白名单被绕过，非 PEM 内容也不会写入 publickey/privatekey，
     * 从而阻断「任意文件读取 → getKey 回显」的完整链路。
     * 刻意不跟随重定向：302 跳内网是最省事的 SSRF 绕过手法。
     *
     * @param string $url    已通过 certUrlError 校验的地址
     * @param string $marker PEM 起始标记
     * @return array{0:string|null,1:string|null} [内容, 错误说明]
     */
    private function fetchPem(string $url, string $marker): array
    {
        $ctx = stream_context_create([
            'http'  => [
                'timeout'         => self::CERT_FETCH_TIMEOUT,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'https' => [
                'timeout'         => self::CERT_FETCH_TIMEOUT,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx, 0, self::CERT_FETCH_MAX);
        if ($body === false || $body === '') {
            return [null, '拉取失败或内容为空'];
        }
        if (!str_contains($body, $marker)) {
            return [null, '内容不是有效的 PEM 格式'];
        }

        return [$body, null];
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

            // 读取前再验一次：库里的值可能来自更早的历史数据或被直接改库，不能只信写入端
            $urlError = $this->certUrlError($url_crt, 'CRT 地址') ?? $this->certUrlError($url_key, 'KEY 地址');
            if ($urlError !== null) {
                Ret::Fail(400, null, $urlError);
            }

            [$publickey, $crtError] = $this->fetchPem($url_crt, self::PEM_CRT_MARKER);
            [$privatekey, $keyError] = $this->fetchPem($url_key, self::PEM_KEY_MARKER);

            if ($crtError !== null || $keyError !== null) {
                Ret::Fail(500, null, '证书获取失败：' . ($crtError ?? $keyError));
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

    public function updateAllSSL()
    {
        $urlModel = new AdminCertUrlModel();
        $items = $urlModel->where('id', '>', 0)->select();

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        foreach ($items as $item) {
            $url_crt = $item['url_crt'];
            $url_key = $item['url_key'];

            if (empty($url_crt) || empty($url_key)) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'cert' => $item['cert'],
                    'success' => false,
                    'error' => 'CRT URL 或 KEY URL 为空',
                ];
                continue;
            }

            // 单条地址非法只计入 fail，不中断整轮批量更新。
            // 这里刻意不用 Ret::Fail —— 它会 exit(0)，会让后面的证书全都更新不到。
            $urlError = $this->certUrlError($url_crt, 'CRT 地址') ?? $this->certUrlError($url_key, 'KEY 地址');
            if ($urlError !== null) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'cert' => $item['cert'],
                    'success' => false,
                    'error' => $urlError,
                ];
                continue;
            }

            [$publickey, $crtError] = $this->fetchPem($url_crt, self::PEM_CRT_MARKER);
            [$privatekey, $keyError] = $this->fetchPem($url_key, self::PEM_KEY_MARKER);

            if ($crtError !== null || $keyError !== null) {
                $rets['fail']++;
                $rets['detail'][] = [
                    'cert' => $item['cert'],
                    'success' => false,
                    'error' => '证书获取失败：' . ($crtError ?? $keyError),
                ];
                continue;
            }

            $item->save([
                'publickey' => $publickey,
                'privatekey' => $privatekey,
            ]);

            $rets['success']++;
            $rets['detail'][] = [
                'cert' => $item['cert'],
                'success' => true,
            ];
        }

        Ret::Success(0, $rets, '一键更新完成');
    }

    /**
     * 下发证书到所有绑定了该证书的爱快路由器
     */
    public function deployIkuai()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $name = $item['cert'];

        // 先尝试从证书 URL 刷新，失败则沿用库中已有证书
        try {
            SiteAction::updatessl($name);
        } catch (Exception $e) {
        }

        try {
            $rets = IkuaiAction::autossl($name);
        } catch (Exception $e) {
            Ret::Fail(500, null, '爱快下发失败: ' . $e->getMessage());
        }

        if ($rets['success'] === 0 && $rets['fail'] === 0) {
            Ret::Fail(404, $rets, '没有绑定该证书的爱快路由器');
        }

        Ret::Success(0, $rets, '爱快下发完成');
    }

    /**
     * 下发证书到所有绑定了该证书的 Proxmox VE 节点
     */
    public function deployPve()
    {
        $id = Input::PostInt('id');
        $model = new AdminCertUrlModel();
        $item = $model->findOrEmpty($id);

        if ($item->isEmpty()) {
            Ret::Fail(404, null, '记录不存在');
        }

        $name = $item['cert'];

        // 先尝试从证书 URL 刷新，失败则沿用库中已有证书
        try {
            SiteAction::updatessl($name);
        } catch (Exception $e) {
        }

        try {
            $rets = PveAction::autossl($name);
        } catch (Exception $e) {
            Ret::Fail(500, null, 'Proxmox VE 下发失败: ' . $e->getMessage());
        }

        if ($rets['success'] === 0 && $rets['fail'] === 0) {
            Ret::Fail(404, $rets, '没有绑定该证书的 Proxmox VE 节点');
        }

        Ret::Success(0, $rets, 'Proxmox VE 下发完成');
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

        if ($cert) {
            try {
                SiteAction::updateSiteListWhichHadSSL($cert['bt_api'], $cert['bt_key']);
            } catch (Exception $e) {
            }
        }

        $ssl = SiteAction::updatessl($name);

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        $sites = AdminCertWebsiteModel::where('type', 'web')
            ->whereIn('cert_name', SiteAction::certNameCandidates($name))
            ->where('status', 1)
            ->select();

        foreach ($sites as $site) {
            $bt_site = new Site($site['api'], $site['key'], './');
            $ret = $bt_site->setSSL(1, $site['website'], $ssl['key'], $ssl['crt']);
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $name,
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
                    'appname' => $name,
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
            ->whereIn('cert_name', SiteAction::certNameCandidates($name))
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
                    'appname' => $name,
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
                    'appname' => $name,
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

        // 爱快路由器：按绑定的证书名称下发，单台失败不影响其他面板结果
        try {
            $ikuaiRets = IkuaiAction::autossl($name);
            $rets['success'] += $ikuaiRets['success'];
            $rets['fail'] += $ikuaiRets['fail'];
            $rets['detail'] = array_merge($rets['detail'], $ikuaiRets['detail']);
        } catch (Exception $e) {
            $rets['fail']++;
            $rets['detail'][] = [
                'type' => 'ikuai',
                'api' => '',
                'cert_name' => $name,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        // Proxmox VE 节点：按绑定的证书名称下发，单台失败不影响其他面板结果
        try {
            $pveRets = PveAction::autossl($name);
            $rets['success'] += $pveRets['success'];
            $rets['fail'] += $pveRets['fail'];
            $rets['detail'] = array_merge($rets['detail'], $pveRets['detail']);
        } catch (Exception $e) {
            $rets['fail']++;
            $rets['detail'][] = [
                'type' => 'pve',
                'api' => '',
                'cert_name' => $name,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        Ret::Success(0, $rets, '自动下发完成');
    }

    public function autoMailSSL()
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

        if ($cert) {
            try {
                MailAction::updateMailListWhichHadSSL($cert['bt_api'], $cert['bt_key']);
            } catch (Exception $e) {
            }
        }

        $ssl = null;
        try {
            $ssl = MailAction::updatessl($name);
        } catch (Exception $e) {
            Ret::Fail(500, null, $e->getMessage());
        }

        $sites = AdminCertWebsiteModel::where('type', 'mail')
            ->whereIn('cert_name', SiteAction::certNameCandidates($name))
            ->where('status', 1)
            ->select();

        $rets = [
            'success' => 0,
            'fail' => 0,
            'detail' => [],
        ];

        foreach ($sites as $site) {
            $catchError = null;
            $bt_base = null;
            try {
                $bt_base = new Base($site['api'], $site['key'], './');
                $ret = $bt_base->httpPostCookie(MailAction::setCert, [
                    'domain' => $site['website'],
                    'csr' => $ssl['csr'],
                    'key' => $ssl['key'],
                    'act' => 'add',
                ], 15);
            } catch (Exception $e) {
                $ret = false;
                $catchError = $e->getMessage();
            }
            if ($ret) {
                AdminCertLogModel::create([
                    'appname' => $name,
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
                    'appname' => $name,
                    'type' => $site['type'],
                    'success' => 0,
                    'website' => $site['website'],
                    'recv' => json_encode($ret, 320),
                ]);
                $rets['fail']++;
                $error = $catchError ?? $bt_base->getError() ?: '邮件SSL部署失败';
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

        Ret::Success(0, $rets, '邮箱更新完成');
    }
}