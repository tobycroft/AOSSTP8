<?php


namespace app\admin\controller;

use app\admin\model\Attachment as AttachmentModel;
use app\admin\model\Menu as MenuModel;
use app\common\controller\Common;
use think\Db;
use think\facade\Cache;
use Tobycroft\AossSdk\Aoss;

/**
 * 用于处理ajax请求的控制器
 * @package app\admin\controller
 */
class Ajax extends Common
{
    /**
     * 获取联动数据
     * @param string $token token
     * @param int $pid 父级ID
     * @param string $pidkey 父级id字段名
     * @return \think\response\Json
     */
    public function getLevelData($token = '', $pid = 0, $pidkey = 'pid')
    {
        if ($token == '') {
            return json(['code' => 0, 'msg' => '缺少Token']);
        }

        $token_data = session($token);
        $table = $token_data['table'];
        $option = $token_data['option'];
        $key = $token_data['key'];

        $data_list = Db::name($table)->where($pidkey, $pid)->column($option, $key);

        if ($data_list === false) {
            return json(['code' => 0, 'msg' => '查询失败']);
        }

        if ($data_list) {
            $result = [
                'code' => 1,
                'msg' => '请求成功',
                'list' => format_linkage($data_list)
            ];
            return json($result);
        } else {
            return json(['code' => 0, 'msg' => '查询不到数据']);
        }
    }

    /**
     * 获取筛选数据
     * @param string $token
     * @param array $map 查询条件
     * @param string $options 选项，用于显示转换
     * @param string $list 选项缓存列表名称
     * @return \think\response\Json
     */
    public function getFilterList($token = '', $map = [], $options = '', $list = '')
    {
        if ($list != '') {
            $result = [
                'code' => 1,
                'msg' => '请求成功',
                'list' => Cache::get($list)
            ];
            return json($result);
        }
        if ($token == '') {
            return json(['code' => 0, 'msg' => '缺少Token']);
        }

        $token_data = session($token);
        $table = $token_data['table'];
        $field = $token_data['field'];

        if ($field == '') {
            return json(['code' => 0, 'msg' => '缺少字段']);
        }
        if (!empty($map) && is_array($map)) {
            foreach ($map as &$item) {
                if (is_array($item)) {
                    foreach ($item as &$value) {
                        $value = trim($value);
                    }
                } else {
                    $item = trim($item);
                }
            }
        }

        if (strpos($table, '/')) {
            $data_list = model($table)->where($map)->group($field)->column($field);
        } else {
            $data_list = Db::name($table)->where($map)->group($field)->column($field);
        }

        if ($data_list === false) {
            return json(['code' => 0, 'msg' => '查询失败']);
        }

        if ($data_list) {
            if ($options != '') {
                // 从缓存获取选项数据
                $options = cache($options);
                if ($options) {
                    $temp_data_list = [];
                    foreach ($data_list as $item) {
                        $temp_data_list[$item] = isset($options[$item]) ? $options[$item] : '';
                    }
                    $data_list = $temp_data_list;
                } else {
                    $data_list = parse_array($data_list);
                }
            } else {
                $data_list = parse_array($data_list);
            }

            $result = [
                'code' => 1,
                'msg' => '请求成功',
                'list' => $data_list
            ];
            return json($result);
        } else {
            return json(['code' => 0, 'msg' => '查询不到数据']);
        }
    }

    /**
     * 获取指定模块的菜单
     * @param string $module 模块名
     * @return mixed
     */
    public function getModuleMenus($module = '')
    {
        if (!is_signin()) {
            $this->error('请先登录');
        }
        $menus = MenuModel::getMenuTree(0, '', $module);
        $result = [
            'code' => 1,
            'msg' => '请求成功',
            'list' => format_linkage($menus)
        ];
        return json($result);
    }

    /**
     * 设置配色方案
     * @param string $theme 配色名称
     */
    public function setTheme($theme = '')
    {
        if (!is_signin()) {
            $this->error('请先登录');
        }
        $themes = ['default', 'amethyst', 'city', 'flat', 'modern', 'smooth'];
        if (!in_array($theme, $themes)) {
            $this->error('非法操作');
        }
        $map['name'] = 'system_color';
        $map['group'] = 'system';

        if (Db::name('admin_config')->where($map)->setField('value', $theme)) {
            $this->success('设置成功');
        } else {
            $this->error('设置失败，请重试');
        }
    }

    /**
     * 获取侧栏菜单
     * @param string $module_id 模块id
     * @param string $module 模型名
     * @param string $controller 控制器名
     * @return string
     */
    public function getSidebarMenu($module_id = '', $module = '', $controller = '')
    {
        if (!is_signin()) {
            $this->error('登录已失效，请重新登录', 'user/publics/signin');
        }

        role_auth();
        $menus = MenuModel::getSidebarMenu($module_id, $module, $controller);

        $output = '';
        foreach ($menus as $key => $menu) {
            if (!empty($menu['url_value'])) {
                $output = $menu['url_value'];
                break;
            }
            if (!empty($menu['child'])) {
                $output = $menu['child'][0]['url_value'];
                break;
            }
        }
        $this->success('获取成功', null, $output);
    }

    /**
     * 检查附件是否存在
     * @param string $md5 文件md5
     * @return \think\response\Json
     */
    public function check($md5 = '')
    {
        $md5 == '' && $this->error('参数错误');
        if ($file_exists = AttachmentModel::get(['md5' => $md5])) {
            $data = [
                'code' => 1,
                'info' => '文件已上传',
                'class' => 'success',
                'id' => $file_exists['path'],
                'path' => $file_exists['path'],
                'data' => $file_exists,
            ];
            return json($data);
        }
        $Aoss = new Aoss(config('upload_prefix'), 'complete');
        $md5_data = $Aoss->md5($md5);
        if ($md5_data->isSuccess()) {
            $file_info = [
                'uid' => session('user_auth.uid'),
                'name' => $md5_data->name,
                'mime' => $md5_data->mime,
                'path' => $md5_data->url,
                'ext' => $md5_data->ext,
                'size' => $md5_data->size,
                'md5' => $md5_data->md5,
                'sha1' => $md5_data->sha1,
                'thumb' => '',
                'module' => 'remote',
                'width' => $md5_data->width,
                'height' => $md5_data->height,
                'driver' => 'remote',
            ];
            // 写入数据库
            if (AttachmentModel::create($file_info)) {
                $data = [
                    'code' => 1,
                    'info' => '同步成功',
                    'class' => 'success',
                    'id' => $md5_data->url,
                    'path' => $md5_data->url,
                    'data' => $md5_data->data,
                ];
                return json($data);
            } else {
                $this->error('文件同步失败');
            }
        } else {
            $this->error($md5_data->getError());
        }
    }

    /**
     * 获取我的角色集合
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public
    function getMyRoles()
    {
        if (!is_signin()) {
            $this->error('请先登录');
        }

        $user = Db::name('admin_user')->where('id', session('user_auth.uid'))->find();
        !$user && $this->error('获取失败');

        $roles = [$user['role']];
        if ($user['roles'] != '') {
            $roles = array_merge($roles, explode(',', $user['roles']));
        }
        $roles = array_unique($roles);
        $roles = Db::name('admin_role')->where('id', 'in', $roles)->column('id,name');
        $this->success('获取成功', null, [
            'curr' => session('user_auth.role'),
            'roles' => $roles
        ]);
    }

    /**
     * 设置我的当前角色
     * @param string $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public
    function setMyRole($id = '')
    {
        if (!is_signin()) {
            $this->error('请先登录');
        }

        $id == '' && $this->error('请选择要设置的角色');

        // 读取当前用户能设置的角色
        $user = Db::name('admin_user')->where('id', session('user_auth.uid'))->find();
        !$user && $this->error('设置失败');

        $roles = [$user['role']];
        if ($user['roles'] != '') {
            $roles = array_merge($roles, explode(',', $user['roles']));
        }
        $roles = array_unique($roles);

        if (!in_array($id, $roles)) {
            $this->error('无法设置当前角色');
        }

        cache('role_menu_auth_' . session('user_auth.role'), null);
        session('user_auth.role', $id);
        session('user_auth.role_name', Db::name('admin_role')->where('id', $id)->value('name'));
        session('user_auth_sign', data_auth_sign(session('user_auth')));
        $this->success('设置成功');
    }
}