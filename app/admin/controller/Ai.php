<?php

namespace app\admin\controller;

use app\admin\model\AdminAiModel;
use app\admin\utils\AdminAuth;
use app\admin\utils\ImageCache;
use app\admin\utils\Layout;
use app\v3\ai\action\AiAction;
use Aoss\Envelope;
use BaseController\CommonController;
use Exception;
use Input;
use Ret;

class Ai extends CommonController
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
        $search = input('get.search', '');

        $model = new AdminAiModel();
        $query = $model->order('id', 'desc');
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                // 名称模糊匹配；若输入恰为 64 位十六进制，则视为按 secret 精确定位
                $q->where('name', 'like', '%' . $search . '%');
                if (preg_match('/^[0-9a-fA-F]{64}$/', $search) === 1) {
                    $q->whereOr('secret', '=', strtolower($search));
                }
            });
        }
        $list = $query->paginate($limit, false, ['page' => $page])->toArray();

        $items = [];
        foreach ($list['data'] as $item) {
            $items[] = [
                'id' => $item['id'],
                'name' => $item['name'],
                'secret' => $item['secret'],
                'secret_mask' => $this->maskSecret((string)$item['secret']),
                'secret_strong' => Envelope::isStrongSecret((string)$item['secret']),
                'kid' => Envelope::kid((string)$item['secret']),
                'base_url' => $item['base_url'],
                'model' => $item['model'],
                'api_key' => $item['api_key'],
                'api_key_short' => mb_strlen($item['api_key']) > 16 ? mb_substr($item['api_key'], 0, 8) . '...' . mb_substr($item['api_key'], -4) : $item['api_key'],
                'status' => $item['status'],
            ];
        }

        $pagination = Layout::pagination((int)$list['current_page'], max(1, (int)ceil($list['total'] / $limit)), '/admin/ai', ['search' => $search], $limit);

        return $this->renderPage('ai/index', [
            'list' => $items,
            'search' => $search,
            'pagination' => $pagination,
        ], 'agnes', 'ai', 'ai');
    }

    /**
     * 凭证掩码，仅用于列表展示，避免整串 secret 平铺在页面上
     */
    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '-';
        }
        if (mb_strlen($secret) <= 12) {
            return $secret . '（熵不足）';
        }
        return mb_substr($secret, 0, 6) . '····' . mb_substr($secret, -4);
    }

    private function create()
    {
        $name = Input::Put('name');
        if (empty($name)) {
            Ret::Fail(400, null, '项目名称不能为空');
        }

        // 凭证由服务端签发，不接受人工输入：secret 的熵直接决定信封机密性上限
        $secret = Envelope::randomSecret();
        $data = [
            'name' => $name,
            'secret' => $secret,
            'api_key' => Input::Put('api_key', ''),
            'base_url' => Input::Put('base_url', 'https://apihub.agnes-ai.com/v1'),
            'model' => Input::Put('model', 'agnes-3.0-flash'),
            'status' => Input::PutInt('status', 1),
        ];

        AdminAiModel::create($data);
        Ret::Success(0, [
            'secret' => $secret,
            'kid' => Envelope::kid($secret),
        ], '创建成功，secret 仅此处展示一次，请立即交付调用方');
    }

    /**
     * 重置凭证
     * 旧 secret 与旧 kid 立即失效，调用方需同步更换，否则 v3 接口一律 401。
     */
    public function resetSecret()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $item = AdminAiModel::findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $secret = Envelope::randomSecret();
        $item->save(['secret' => $secret]);

        Ret::Success(0, [
            'secret' => $secret,
            'kid' => Envelope::kid($secret),
        ], 'Secret 已重置，旧凭证与旧 kid 立即失效');
    }

    private function update()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $data = [];
        if (request()->has('name', 'post')) $data['name'] = Input::Post('name');
        if (request()->has('api_key', 'post')) $data['api_key'] = Input::Post('api_key');
        if (request()->has('base_url', 'post')) $data['base_url'] = Input::Post('base_url');
        if (request()->has('model', 'post')) $data['model'] = Input::Post('model');
        if (request()->has('status', 'post')) $data['status'] = Input::PostInt('status');

        $item->save($data);
        Ret::Success(0, ['kid' => Envelope::kid((string)$item['secret'])], '更新成功');
    }

    private function delete()
    {
        $id = intval(request()->delete('id'));
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        $item->delete();
        Ret::Success(0, [], '删除成功');
    }

    /**
     * 在线测试：调用一次 chat/completions 验证配置可用性
     */
    public function test()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        if ((string)$item['api_key'] === '') {
            Ret::Fail(400, null, '未配置API密钥');
        }

        $prompt = Input::Post('prompt', '用一句话介绍你自己。');
        if ($prompt === '') {
            $prompt = '用一句话介绍你自己。';
        }

        $proc = [
            'base_url' => $item['base_url'] ?: 'https://apihub.agnes-ai.com/v1',
            'model' => $item['model'] ?: 'agnes-3.0-flash',
            'api_key' => $item['api_key'],
        ];

        try {
            $data = AiAction::chatCompletions($proc, [
                ['role' => 'user', 'content' => $prompt],
            ], [
                'max_tokens' => 256,
            ]);
        } catch (Exception $e) {
            Ret::Fail(500, null, '测试失败: ' . $e->getMessage());
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        Ret::Success(0, [
            'reply' => $content,
            'usage' => $usage,
        ], '测试成功');
    }

    /**
     * 图片生成测试：调用 agnes 图片接口验证配置可用性（带本地缓存）
     *
     * 与 v3 图片接口共用 AiAction::imageGenerations，默认模型 agnes-image-2.5-flash，
     * 不读取项目的对话模型。response_format / image 由 AiAction 统一归入 extra_body。
     *
     * 缓存：结果按参数指纹落到 public/ai/，TTL 内命中直接返回本地 URL（不请求上游）；
     * 过期或 force=true 时重新生成并覆盖。cache_ttl 单位为秒，缺省 24 小时。
     */
    public function testImage()
    {
        $id = Input::PostInt('id');
        if (!$id) {
            Ret::Fail(400, null, '缺少参数[id]');
        }

        $model = new AdminAiModel();
        $item = $model->findOrEmpty($id);
        if ($item->isEmpty()) {
            Ret::Fail(404, null, '项目不存在');
        }

        if ((string)$item['api_key'] === '') {
            Ret::Fail(400, null, '未配置API密钥');
        }

        $prompt = Input::Post('prompt', false);
        if ($prompt === '') {
            $prompt = 'A luminous floating city above a misty canyon at sunrise, cinematic realism';
        }
        $size = Input::Post('size', false) ?: '1K';
        $ratio = request()->has('ratio', 'post') ? Input::Post('ratio') : '';
        $responseFormat = request()->has('response_format', 'post') ? Input::Post('response_format') : '';

        $image = [];
        if (request()->has('image', 'post')) {
            $raw = request()->post('image');
            $imageArr = is_array($raw) ? $raw : json_decode((string)$raw, true);
            if (is_array($imageArr)) {
                $image = array_values($imageArr);
            }
        }

        $ttl = ImageCache::normalizeTtl(Input::Post('cache_ttl', false));
        $force = Input::PostBool('force', false);
        $params = [
            'prompt' => $prompt,
            'size' => $size,
            'ratio' => $ratio,
            'response_format' => $responseFormat,
            'image' => $image,
        ];
        $key = ImageCache::key($id, $params);

        // 快路径：命中未过期缓存直接返回，不请求上游
        if (!$force) {
            $hit = ImageCache::get($key, $ttl);
            if ($hit !== null) {
                Ret::Success(0, $hit, '命中本地缓存');
            }
        }

        $proc = [
            'base_url' => $item['base_url'] ?: 'https://apihub.agnes-ai.com/v1',
            'api_key' => $item['api_key'],
        ];

        $options = [];
        if ($ratio !== '') {
            $options['ratio'] = $ratio;
        }
        if ($responseFormat !== '') {
            $options['response_format'] = $responseFormat;
        }
        if (!empty($image)) {
            $options['image'] = $image;
        }

        // 加独占锁并二次校验：等锁期间可能已被其它请求生成，避免重复调用上游
        $lock = ImageCache::getLock($key);
        $error = null;
        $result = null;
        $echo = $force ? '已强制刷新并缓存' : '测试成功并已缓存';
        try {
            if (!$force) {
                $hit = ImageCache::get($key, $ttl);
                if ($hit !== null) {
                    $result = $hit;
                    $echo = '命中本地缓存';
                }
            }
            if ($result === null) {
                $data = AiAction::imageGenerations($proc, $prompt, $size, $options);
                $result = ImageCache::put($key, $data, $params, $ttl);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } finally {
            ImageCache::releaseLock($lock);
        }

        if ($error !== null) {
            Ret::Fail(500, null, '测试失败: ' . $error);
        }
        Ret::Success(0, $result, $echo);
    }
}