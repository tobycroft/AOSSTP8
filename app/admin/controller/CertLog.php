<?php

namespace app\admin\controller;

use app\admin\model\AdminCertLogModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\Layout;
use BaseController\CommonController;

class CertLog extends CommonController
{
    /**
     * 类型归类标签
     */
    const TYPE_LABELS = [
        'web' => 'Web',
        'mail' => 'Mail',
        'panel' => 'Panel',
        'ikuai' => '爱快',
    ];

    public function initialize()
    {
        parent::initialize();
        AdminAuth::requireLogin();
    }

    public function index()
    {
        $method = request()->method();
        if ($method !== 'GET') {
            \Ret::Fail(405, null, '不支持的请求方法');
        }
        return $this->page();
    }

    private function page()
    {
        $page = input('get.page', 1, 'intval');
        $type = trim(strval(input('get.type', '')));
        $limit = 20;

        $model = new AdminCertLogModel();
        if ($type !== '') {
            $model = $model->where('type', $type);
        }
        $list = $model->order('id', 'desc')->paginate($limit, false, ['page' => $page]);

        $currentPage = (int)$list->currentPage();
        $total = $list->total();
        $totalPages = max(1, (int)ceil($total / $limit));

        $items = [];
        foreach ($list as $item) {
            $recv = (string)$item['recv'];
            $items[] = [
                'id' => $item['id'],
                'appname' => $item['appname'],
                'type' => $item['type'],
                'type_text' => self::TYPE_LABELS[$item['type']] ?? (string)$item['type'],
                'website' => $item['website'],
                'success' => $item['success'],
                'reason' => $this->buildReason((string)$item['type'], $recv, (int)$item['success']),
                // 原始返回内容只在「详细」里展示（模板会自动做一次 HTML 转义）
                'recv_pretty' => $this->formatRecv($recv),
                'create_time' => $item['date'] ?: $item['change_date'],
            ];
        }

        $pagination = Layout::pagination($currentPage, $totalPages, '/admin/cert_log', $type !== '' ? ['type' => $type] : []);

        return $this->renderPage('cert_log/index', [
            'list' => $items,
            'pagination' => $pagination,
            'types' => $this->typeTabs(),
            'currentType' => $type,
        ], '操作日志', 'cert', 'cert_log');
    }

    /**
     * 按类型归类统计，用于页面顶部的筛选标签
     */
    private function typeTabs(): array
    {
        $types = [];
        foreach (self::TYPE_LABELS as $value => $label) {
            $types[] = [
                'value' => $value,
                'label' => $label,
                'count' => AdminCertLogModel::where('type', $value)->count(),
            ];
        }

        return $types;
    }

    /**
     * 归类日志结果，输出可读的成功/失败原因
     */
    private function buildReason(string $type, string $recv, int $success): string
    {
        $data = json_decode($recv, true);
        $event = is_array($data) ? (string)($data['event'] ?? '') : '';

        if ($type === 'ikuai') {
            switch ($event) {
                case 'save_cert':
                    return $success === 1
                        ? '证书下发成功'
                        : '证书下发失败：' . ($data['error'] ?? '未知错误');
                case 'login_fail':
                    return '登录失败：' . ($data['error'] ?? '未知错误');
                case 'auto_relogin':
                    return '鉴权失效，已自动重新登录（第 ' . intval($data['attempt'] ?? 1) . ' 次）';
            }

            return $success === 1 ? '成功' : '失败：' . ($data['error'] ?? '未知错误');
        }

        if ($success === 1) {
            return '成功';
        }

        if (is_array($data)) {
            $error = $data['error'] ?? $data['msg'] ?? $data['ErrMsg'] ?? '';
            if (is_string($error) && $error !== '' && strcasecmp($error, 'Success') !== 0) {
                return '失败：' . $error;
            }
        }

        return '失败';
    }

    /**
     * 格式化原始返回内容，JSON 时美化输出
     */
    private function formatRecv(string $recv): string
    {
        if ($recv === '') {
            return '';
        }

        $data = json_decode($recv, true);
        if (is_array($data)) {
            return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $recv;
    }
}
