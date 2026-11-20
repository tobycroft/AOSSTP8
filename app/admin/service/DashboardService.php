<?php

namespace app\admin\service;

use think\facade\Db;

/**
 * 控制台仪表板统计服务
 *
 * 聚合全系统各业务表可统计维度：核心指标、资源总览、趋势、分布与健康度。
 * 仅负责数据聚合，不依赖请求/响应对象，供控制器直接编排。
 */
class DashboardService
{
    /**
     * 采集全部仪表板数据
     *
     * @return array{kpi:array,resources:array,charts:array,health:array,updatedAt:string}
     */
    public static function collect(): array
    {
        return [
            'kpi'       => self::kpi(),
            'resources' => self::resources(),
            'charts'    => [
                'certTrend'    => self::monthlyTrend('ao_cert_log', 'date', 12),
                'hookTrend'    => self::monthlyTrend('ao_hook_log', 'date', 12),
                'attachType'   => self::attachmentType(),
                'attachExt'    => self::topBy('ao_attachment', 'ext', 8),
                'certOpType'   => self::topBy('ao_cert_log', 'type', 8),
                'certSiteType' => self::groupBy('ao_cert_website', 'type'),
                'wechatMsgType'=> self::topBy('ao_wechat_message', 'MsgType', 6),
                'dnsRecordType'=> self::topBy('ao_dnspod_record', 'type', 8),
            ],
            'health'    => self::health(),
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 核心指标卡片
     */
    private static function kpi(): array
    {
        $attach = self::row("SELECT COUNT(*) n, ROUND(SUM(size)/1024/1024/1024, 2) gb FROM ao_attachment");
        $cert30 = self::count('ao_cert_log', 'date >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $hook30 = self::count('ao_hook_log', 'date >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $captcha30 = self::count('ao_captcha', 'date >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $wechatAll = self::count('ao_wechat_user');
        $wechatSub = self::count('ao_wechat_user', 'is_suscribe = 1');
        $siteAll = self::count('ao_cert_website');
        $siteOn = self::count('ao_cert_website', 'status = 1');
        $record = self::count('ao_dnspod_record');
        $domain = self::count('ao_dnspod_domain');

        return [
            ['label' => '附件总数',   'value' => self::num($attach['n']), 'unit' => '个', 'sub' => '占用空间 ' . $attach['gb'] . ' GB', 'tone' => 'blue'],
            ['label' => '证书操作',   'value' => self::num($cert30),      'unit' => '次/30天', 'sub' => '累计 ' . self::num(self::count('ao_cert_log')) . ' 次', 'tone' => 'cyan'],
            ['label' => 'Hook 调用',  'value' => self::num($hook30),      'unit' => '次/30天', 'sub' => '累计 ' . self::num(self::count('ao_hook_log')) . ' 次', 'tone' => 'purple'],
            ['label' => '微信用户',   'value' => self::num($wechatAll),   'unit' => '人', 'sub' => '已订阅 ' . self::num($wechatSub) . ' 人', 'tone' => 'green'],
            ['label' => '证书站点',   'value' => self::num($siteAll),     'unit' => '个', 'sub' => '启用中 ' . self::num($siteOn) . ' 个', 'tone' => 'orange'],
            ['label' => '域名解析',   'value' => self::num($record),      'unit' => '条', 'sub' => '管理域名 ' . self::num($domain) . ' 个', 'tone' => 'red'],
            ['label' => '验证码',     'value' => self::num($captcha30),   'unit' => '次/30天', 'sub' => '累计 ' . self::num(self::count('ao_captcha')) . ' 次', 'tone' => 'blue'],
            ['label' => 'IP 地址库',  'value' => self::num(self::count('ao_ip')), 'unit' => '条', 'sub' => '归属地数据', 'tone' => 'cyan'],
        ];
    }

    /**
     * 资源总览：按业务域分组，一块一个数据的表格呈现
     */
    private static function resources(): array
    {
        $def = [
            '证书管理' => [
                '证书项目'   => 'ao_cert',
                '证书 URL'   => 'ao_cert_url',
                '证书站点'   => 'ao_cert_website',
                '爱快节点'   => 'ao_cert_ikuai',
                'PVE 节点'   => 'ao_cert_pve',
                '证书日志'   => 'ao_cert_log',
            ],
            '存储与附件' => [
                '附件文件'   => 'ao_attachment',
                '附件令牌'   => 'ao_attachment_token',
                '存储配置'   => 'ao_oss',
                '阿里云 OSS' => 'ao_oss_aliyun',
            ],
            '短信服务' => [
                '短信项目'   => 'ao_sms',
                '阿里云短信' => 'ao_sms_aliyun',
                '腾讯云短信' => 'ao_sms_tencent',
                'LC 短信'    => 'ao_sms_lc',
                '网路万象'   => 'ao_sms_wlwx',
                '黑名单'     => 'ao_sms_blacklist',
                '拦截规则'   => 'ao_sms_intercept',
                '发送日志'   => 'ao_log_sms',
            ],
            '邮件服务' => [
                '邮件服务器' => 'ao_mail_server',
                '邮件模板'   => 'ao_mail_template',
                '发送日志'   => 'ao_mail_log',
            ],
            'Hook 与缓存' => [
                'Hook 配置'  => 'ao_hook',
                'Hook 日志'  => 'ao_hook_log',
                'Excel 缓存' => 'ao_excel',
            ],
            '微信生态' => [
                '微信配置'   => 'ao_wechat',
                '微信用户'   => 'ao_wechat_user',
                '微信消息'   => 'ao_wechat_message',
                '微信 KV'    => 'ao_wechat_kv',
            ],
            'DNS 管理' => [
                '域名'       => 'ao_dnspod_domain',
                '解析记录'   => 'ao_dnspod_record',
            ],
            '项目与 AI' => [
                '开放项目'   => 'ao_ak',
                'v3 项目'    => 'ao_v3_project',
                'Agnes API'  => 'ao_ai_agnes',
                'AI 配置'    => 'ao_ai',
            ],
            '管理员与门户' => [
                '管理员'     => 'admin_user',
                '登录日志'   => 'admin_login_log',
                '门户用户'   => 'ao_user',
                '邀请码'     => 'ao_invite_code',
                '门户登录日志' => 'ao_login_log',
            ],
            '系统与日志' => [
                'IP 地址库'  => 'ao_ip',
                '验证码'     => 'ao_captcha',
                'Web 日志'   => 'ao_log_web',
                '系统参数'   => 'system_param',
            ],
        ];

        $groups = [];
        foreach ($def as $domain => $items) {
            $rows = [];
            foreach ($items as $label => $table) {
                $rows[] = ['label' => $label, 'value' => self::num(self::count($table))];
            }
            $groups[] = ['domain' => $domain, 'rows' => $rows];
        }
        return $groups;
    }

    /**
     * 健康度：关键操作的成功率
     */
    private static function health(): array
    {
        return [
            self::rate('证书下发成功率', 'ao_cert_log'),
            self::rate('Hook 调用成功率', 'ao_hook_log'),
            self::rate('管理员登录成功率', 'admin_login_log'),
            [
                'label' => '证书站点启用率',
                'value' => self::percent(self::count('ao_cert_website', 'status = 1'), self::count('ao_cert_website')),
            ],
        ];
    }

    /**
     * 构造成功率数据项
     */
    private static function rate(string $label, string $table): array
    {
        $ok = self::count($table, 'success = 1');
        $all = self::count($table);
        return [
            'label' => $label,
            'value' => self::percent($ok, $all),
            'detail' => self::num($ok) . ' / ' . self::num($all),
        ];
    }

    /**
     * 附件 MIME 大类分布
     */
    private static function attachmentType(): array
    {
        $rows = Db::query("SELECT
            CASE
                WHEN mime LIKE 'image%' THEN '图片'
                WHEN mime LIKE 'audio%' THEN '音频'
                WHEN mime LIKE 'video%' THEN '视频'
                WHEN mime = 'application/pdf' THEN 'PDF'
                ELSE '其他'
            END AS k, COUNT(*) AS n
            FROM ao_attachment GROUP BY k ORDER BY n DESC");
        return self::toChart($rows, 'k', 'n');
    }

    /**
     * 近 N 个月的月度趋势（缺失月份补 0）
     */
    private static function monthlyTrend(string $table, string $dateField, int $months): array
    {
        $keys = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $keys[] = date('Y-m', strtotime("-$i months"));
        }
        $rows = Db::query("SELECT DATE_FORMAT(`$dateField`, '%Y-%m') AS m, COUNT(*) AS n
            FROM `$table` WHERE `$dateField` >= :start GROUP BY m", ['start' => $keys[0] . '-01']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['m']] = (int)$r['n'];
        }
        $values = array_map(static fn($k) => $map[$k] ?? 0, $keys);
        // 标签只保留月份数字，跨年用 yy-mm 标注
        $labels = array_map(static fn($k) => substr($k, 2), $keys);
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * 按字段统计并取 TOP N
     */
    private static function topBy(string $table, string $field, int $limit): array
    {
        $rows = Db::query("SELECT `$field` AS k, COUNT(*) AS n FROM `$table`
            GROUP BY `$field` ORDER BY n DESC LIMIT $limit");
        return self::toChart($rows, 'k', 'n');
    }

    /**
     * 按字段全量分组统计
     */
    private static function groupBy(string $table, string $field): array
    {
        $rows = Db::query("SELECT `$field` AS k, COUNT(*) AS n FROM `$table`
            GROUP BY `$field` ORDER BY n DESC");
        return self::toChart($rows, 'k', 'n');
    }

    /**
     * 查询结果转图表结构
     */
    private static function toChart(array $rows, string $keyField, string $numField): array
    {
        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string)($r[$keyField] ?? '-');
            $values[] = (int)$r[$numField];
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * 统计表记录数
     */
    private static function count(string $table, string $where = ''): int
    {
        $sql = "SELECT COUNT(*) AS n FROM `$table`" . ($where !== '' ? " WHERE $where" : '');
        return (int)self::row($sql)['n'];
    }

    /**
     * 查询单行
     */
    private static function row(string $sql): array
    {
        return Db::query($sql)[0] ?? ['n' => 0];
    }

    /**
     * 百分比（一位小数）
     */
    private static function percent(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
    }

    /**
     * 千分位格式化
     */
    private static function num($value): string
    {
        return number_format((int)$value);
    }
}
