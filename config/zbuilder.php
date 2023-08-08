<?php
// +----------------------------------------------------------------------
// | 海豚PHP框架 [ DolphinPHP ]
// +----------------------------------------------------------------------
// | 版权所有 2016~2019 广东卓锐软件有限公司 [ http://www.zrthink.com ]
// +----------------------------------------------------------------------
// | 官方网站: http://dolphinphp.com
// +----------------------------------------------------------------------

/**
 * ZBuilder相关设置
 */
return [
    // +----------------------------------------------------------------------
    // | 表格相关设置
    // +----------------------------------------------------------------------
    'web_site_title' => '页面标题',
    'web_site_description' => '',
    'system_color' => 'default',
    //default:Default
    // amethyst:Amethyst
    // city:City
    // flat:Flat
    // modern:Modern
    // smooth:Smooth
    'upload_image_size' => 100 * 1024,
    'upload_image_ext' => 'jpg,png',
    'tpl_replace_string' => 'jpg,png',
    'upload_file_size' => 100 * 1024, //0为不限制大小，单位：kb
    'upload_file_ext' => 'doc,docx,xls,xlsx,ppt,pptx,pdf,wps,txt,rar,zip,gz,bz2,7z', //允许上传的文件后缀 多个后缀用逗号隔开，不填写则不限制类型
    'upload_image_thumb' => '', //不填写则不生成缩略图，如需生成 <code>300x300</code> 的缩略图，则填写 <code>300,300</code> ，请注意，逗号必须是英文逗号
    'upload_image_thumb_type' => 1, //缩略图裁剪类型 1:等比例缩放     2:缩放后填充     3:居中裁剪    4:左上角裁剪    5:右下角裁剪    6:固定尺寸缩放 //该项配置只有在启用生成缩略图时才生效
    'upload_thumb_water' => false, //添加水印
    'upload_thumb_water_pic' => '', //水印图片 只有开启水印功能才生效
    'upload_thumb_water_position' => 9, //1:左上角     2:上居中     3:右上角    4:左居中    5:居中    6:右居中    7:左下角    8:下居中    9:右下角


    // 弹出层
    'pop' => [
        'type' => 2,
        'area' => ['80%', '90%'],
        'shadeClose' => true,
        'isOutAnim' => false,
        'anim' => -1
    ],

    // 右侧按钮
    'right_button' => [
        // 是否显示按钮文字
        'title' => false,
        // 是否显示图标，只有显示文字时才起作用
        'icon' => true,
        // 按钮大小：xs/sm/lg，留空则为普通大小
        'size' => 'xs',
        // 按钮样式：default/primary/success/info/warning/danger
        'style' => 'default'
    ],

    // 搜索框
    'search_button' => false,

    // 表单令牌名称，如果不启用，请设置为false，也可以设置其他名称，如：__hash__
    'form_token_name' => '__token__',

    'view' => [
        'top_menu_url' => '',
        'theme_url' => 'default',

        'jcrop_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "from" => "jcrop", "module" => app("http")->getName()]),
        'editormd_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "from" => "editormd", "module" => app("http")->getName()]),
        'ueditor_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "from" => "ueditor", "module" => app("http")->getName()]),
        'wangeditor_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "from" => "wangeditor", "module" => app("http")->getName()]),
        'ckeditor_img_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "from" => "ckeditor", "module" => app("http")->getName()]),
        'file_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "files", "module" => app("http")->getName()]),
        'image_upload_url' => '', //(string)url("attachment/{$is_admin}.attachment/upload", ["dir" => "images", "module" => app("http")->getName()]),
        'upload_check_url' => '', //(string)url("attachment/ajax/check"),
        'get_level_data' => '', //(string)url("attachment/ajax/getLevelData"),
        'quick_edit_url' => '', //(string)url("quickEdit"),
        'aside_edit_url' => '', //(string)url("attachment/system/quickEdit"),
        'triggers' => isset($_vars['field_triggers']) ? $_vars['field_triggers'] : [], // 触发器集合
        'field_hide' => isset($_vars['field_hide']) ? $_vars['field_hide'] : '', // 需要隐藏的字段
        'field_values' => isset($_vars['field_values']) ? $_vars['field_values'] : '',
        'validate' => isset($_vars['validate']) ? $_vars['validate'] : '', // 验证器
        'validate_fields' => isset($_vars['validate_fields']) ? $_vars['validate_fields'] : '', // 验证字段
        'search_field' => request()->param('search_field', ''), // 搜索字段
        // 字段过滤
        '_filter' => request()->param('_filter') ? request()->param('_filter') : (isset($this->_vars['_filter']) ? $_vars['_filter'] : ""),
        '_filter_content' => request()->param('_filter_content') == '' ? (isset($this->_vars['_filter_content']) ? $_vars['_filter_content'] : "") : request()->param('_filter_content'),
        '_field_display' => request()->param('_field_display') ? request()->param('_field_display') : (isset($this->_vars['_field_display']) ? $_vars['_field_display'] : ""),
        '_field_clear' => json_encode(isset($this->_vars['field_clear']) ? $_vars['field_clear'] : []),
        'get_filter_list' => 'attachment/ajax/getFilterList',
        'curr_url' => '',
        'curr_params' => request()->param(),
        'layer' => [
            'type' => 2,
            'area' => ['80%', '90%'],
            'shadeClose' => true,
            'isOutAnim' => false,
            'anim' => -1
        ],

    ],
];
