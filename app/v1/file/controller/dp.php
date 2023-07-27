<?php

namespace app\v1\file\controller;


use app\v1\file\action\OssSelectionAction;
use app\v1\file\model\AttachmentModel;
use app\v1\project\model\ProjectModel;
use BaseController\CommonController;
use getID3;
use Input;
use OSS\AliyunOSS;
use OSS\Core\OssException;
use Ret;
use SendFile\SendFile;

class dp extends CommonController
{

    public $token;

    public function index()
    {
        dump(config('aliyun.'));
    }

    public function upload($dir = '', $from = '', $module = '')
    {
        // 临时取消执行时间限制
        set_time_limit(0);
        if (!input('chunks')) {
            parent::initialize();
            if ($from == 'ueditor') {
                return $this->ueditor();
            }

            if ($from == 'jcrop') {
                return $this->jcrop();
            }
            return $this->saveFile($dir, $from, $module);
        } else {
            $chunk = new chunk();
            $chunk->upload_chunk();
        }

    }

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        $this->token = Input::Get("token");
        if (!$this->token) {
            Ret::Fail(401, null, 'token');
        }
    }

    public function ueditor()
    {
        $action = $this->request->get('action');
        $config_file = './static/libs/ueditor/php/config.json';
        $config = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents($config_file)), true);
        switch ($action) {
            /* 获取配置信息 */
            case 'config':
                $result = $config;
                break;

            /* 上传图片 */
            case 'uploadimage':
                return $this->saveFile('images', 'ueditor');
                break;
            /* 上传涂鸦 */
            case 'uploadscrawl':
                return $this->saveFile('images', 'ueditor_scrawl');
                break;

            /* 上传视频 */
            case 'uploadvideo':
                return $this->saveFile('videos', 'ueditor');
                break;

            /* 上传附件 */
            case 'uploadfile':
                return $this->saveFile('files', 'ueditor');
                break;

            /* 列出图片 */
            case 'listimage':
                return $this->showFile('listimage', $config);
                break;

            /* 列出附件 */
            case 'listfile':
                return $this->showFile('listfile', $config);
                break;

            /* 抓取远程附件 */
//            case 'catchimage':
            //                $result = include("action_crawler.php");
            //                break;

            default:
                $result = ['state' => '请求地址出错'];
                break;
        }

        /* 输出结果 */
        if (isset($_GET["callback"])) {
            if (preg_match("/^[\w_]+$/", $_GET["callback"])) {
                return htmlspecialchars($_GET["callback"]) . '(' . $result . ')';
            } else {
                return json(['state' => 'callback参数不合法']);
            }
        } else {
            return json($result);
        }
    }

    public function saveFile($dir = '', $from = '', $module = '')
    {
        set_time_limit(0);
        $token = $this->token;
        $proc = ProjectModel::api_find_token($token);
        if (!$proc) {
            return $this->uploadError($from, "项目不可用");
        }
        if ($proc['type'] == 'none') {
            return $this->uploadError($from, '本项目没有存储权限');
        }
        $proc = OssSelectionAction::App_find_byProc($proc);

        // 获取附件数据
        $callback = '';
        switch ($from) {
            case 'editormd':
                $file_input_name = 'editormd-image-file';
                break;
            case 'ckeditor':
                $file_input_name = 'upload';
                $callback = $this->request->get('CKEditorFuncNum');
                break;
            case 'ueditor_scrawl':
                return $this->saveScrawl();
                break;
            default:
                $file_input_name = 'file';
        }
        $file = $this->request->file($file_input_name);
        if (!$file) {
            return $this->uploadError($from, "请先上传文件", $callback);
        }
        $file_name = $file->getFileInfo('name');
        $md5 = $file->hash('md5');
        $sha1 = $file->hash("sha1");
        $mime = $file->getFileInfo('type');
        // 判断附件格式是否符合

        if ($file_info = AttachmentModel::find(['token' => $token, 'md5' => $md5, 'sha1' => $sha1])) {
            $sav = $proc['url'] . '/' . $file_info['path'];
            return $this->uploadSuccess($from, $sav, $file_info['name'], $sav, $callback, $file_info);
        } elseif ($file_info = AttachmentModel::find(['token' => $token, 'md5' => $md5])) {
            if (!AttachmentModel::update(["sha1" => $sha1], ['token' => $token, 'md5' => $md5])) {
                $this->uploadError($from, "sha更新失败", $callback);
            }
            $sav = $proc['url'] . '/' . $file_info['path'];
            return $this->uploadSuccess($from, $sav, $file_info['name'], $sav, $callback, $file_info);
        }

        if ($file->getMime() == 'text/x-php' || $file->getMime() == 'text/html') {
            return $this->uploadError($from, "禁止上传非法文件", $callback);
        }
        $info = $file->validate(['size' => (float)$proc['size'] * 1024, 'ext' => $proc['ext']])->move('./upload/' . $this->token);
        if (!$info) {
            return $this->uploadError($from, "上传不符合规范", $callback);
        }

        $fileName = $proc['name'] . '/' . $info->getSaveName();
        $fileName = str_replace("\\", "/", $fileName);


        $duration = 0;
        $duration_str = "00:00";
        $bitrate = 0;
        $width = 0;
        $height = 0;

        $ext = $info->getExtension();

        switch ($ext) {
            case "mp3":
            case "wav":
            case "ogg":
            case "asf":
            case "wmv":
            case "avi":
            case "mp4":
            case "aac":
                $getId3 = new getID3();
                $ana = $getId3->analyze($info->getPathname());
                if (!empty($ana["error"])) {
                    return $this->uploadError($from, "无法解析视频：" . $ana['error'][0], $callback);
                }
                $duration = $ana["playtime_seconds"];
                $bitrate = $ana["bitrate"];
                $duration_str = $ana["playtime_string"];
                break;

            case "png":
            case "jpg":
            case "jpeg":
            case "bmp":
            case "gif":
            case "tiff":
                $getId3 = new getID3();
                $ana = $getId3->analyze($info->getPathname());
                $width = $ana["video"]["resolution_x"];
                $height = $ana["video"]["resolution_y"];
                $bitrate = $ana["video"]["bits_per_sample"];
                $duration_str = $ana["video"]["compression_ratio"];
                break;
        }

        $file_info = [
            'token' => $token,
            'name' => $file_name,
            'mime' => $mime,
            'path' => $fileName,
            'ext' => $ext,
            'size' => $info->getSize(),
            'md5' => $md5,
            'sha1' => $sha1,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'duration_str' => $duration_str,
            'bitrate' => $bitrate,
        ];

        if ($proc["type"] == "local" || $proc["type"] == "all") {
            if ($proc['main_type'] == 'local') {
                $sav = $proc['url'] . '/' . $fileName;
            }
        }
        if ($proc["type"] == "dp" || $proc["type"] == "all") {
            $sf = new SendFile();
            $ret = $sf->send('http://' . $proc["endpoint"] . '/up?token=' . $proc["bucket"], realpath('./upload/' . $fileName), $file->getFileInfo('type'), $file->getFileInfo('name'));
            $json = json_decode($ret, 1);
            $sav = $proc['url'] . '/' . $json["data"];
        }
        if ($proc["type"] == "oss" || $proc["type"] == "all") {
            try {
                $oss = new AliyunOSS($proc);
                $ret = $oss->uploadFile($proc['bucket'], $fileName, $info->getPathname());
            } catch (OssException $e) {
                Ret::Fail(200, null, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                Ret::Fail(300, null, "OSS不正常");
            }
            if ($proc['main_type'] == 'oss') {
                $sav = $proc['url'] . '/' . $fileName;
            }
            if ($proc["type"] != "all") {
                unlink($info->getPathname());
            }
        }

        // 写入数据库
        if ($file_add = AttachmentModel::create($file_info)) {
            return $this->uploadSuccess($from, $sav, $file_info['name'], $sav, $callback, $file_info);
        } else {
            return $this->uploadError($from, '上传失败', $callback);
        }
    }

    public function uploadError($from, $msg = '', $callback = '')
    {
        parent::initialize();
        switch ($from) {
            case 'wangeditor':
                return "error|" . $msg;
                break;
            case 'ueditor':
                return json(['state' => $msg]);
                break;
            case 'editormd':
                return json(["success" => 0, "message" => $msg]);
                break;
            case 'ckeditor':
                return ck_js($callback, '', $msg);
                break;
            default:
                return json([
                    'code' => 0,
                    'class' => 'danger',
                    'info' => $msg,
                ]);
        }
    }

    public function uploadSuccess($from, $file_path = '', $file_name = '', $file_id = '', $callback = '', $data = [])
    {
        parent::initialize();
        switch ($from) {
            case 'wangeditor':
                return $file_path;
                break;
            case 'ueditor':
                return json([
                    "state" => "SUCCESS", // 上传状态，上传成功时必须返回"SUCCESS"
                    "url" => $file_path, // 返回的地址
                    "title" => $file_name, // 附件名
                    "data" => $data,
                ]);
                break;
            case 'editormd':
                return json([
                    "success" => 1,
                    "message" => '上传成功',
                    "url" => $file_path,
                    "data" => $data,
                ]);
                break;
            case 'ckeditor':
                return ck_js($callback, $file_path);
                break;
            default:
                return json([
                    'code' => 1,
                    'info' => '上传成功',
                    'class' => 'success',
                    'id' => $file_path,
                    'path' => $file_path,
                    "data" => $data,
                ]);
        }
    }

    public function showFile($type, $config)
    {
        /* 判断类型 */
        switch ($type) {
            /* 列出附件 */
            case 'listfile':
                $allowFiles = $config['fileManagerAllowFiles'];
                $listSize = $config['fileManagerListSize'];
                $path = realpath(config('upload_path') . '/files/');
                break;
            /* 列出图片 */
            case 'listimage':
            default:
                $allowFiles = $config['imageManagerAllowFiles'];
                $listSize = $config['imageManagerListSize'];
                $path = realpath(config('upload_path') . '/images/');
        }
        $allowFiles = substr(str_replace(".", "|", join("", $allowFiles)), 1);

        /* 获取参数 */
        $size = isset($_GET['size']) ? htmlspecialchars($_GET['size']) : $listSize;
        $start = isset($_GET['start']) ? htmlspecialchars($_GET['start']) : 0;
        $end = $start + $size;

        /* 获取附件列表 */
        $files = $this->getfiles($path, $allowFiles);
        if (!count($files)) {
            return json(array(
                "state" => "no match file",
                "list" => array(),
                "start" => $start,
                "total" => count($files),
            ));
        }

        /* 获取指定范围的列表 */
        $len = count($files);
        for ($i = min($end, $len) - 1, $list = array(); $i < $len && $i >= 0 && $i >= $start; $i--) {
            $list[] = $files[$i];
        }
        //倒序
        //for ($i = $end, $list = array(); $i < $len && $i < $end; $i++){
        //    $list[] = $files[$i];
        //}

        /* 返回数据 */
        $result = array(
            "state" => "SUCCESS",
            "list" => $list,
            "start" => $start,
            "total" => count($files),
        );

        return json($result);
    }

}
