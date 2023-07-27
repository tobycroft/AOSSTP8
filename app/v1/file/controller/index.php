<?php

namespace app\v1\file\controller;


use app\v1\file\action\OssSelectionAction;
use app\v1\file\model\AttachmentModel;
use Exception;
use getID3;
use OSS\AliyunOSS;
use OSS\Core\OssException;
use Ret;
use SendFile\SendFile;
use think\Request;
use Throwable;

class index extends search
{


    public function initialize()
    {

        parent::initialize();
        $this->proc = OssSelectionAction::App_find_byProc($this->proc);
        if ($this->proc['type'] == 'none') {
            Ret::Fail(400, null, '本项目没有存储权限');
        }
    }

    public function up(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                try {
                    $this->upload_file($request);
                } catch (Exception $e) {
                    Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
                }
            } else {
                Ret::Fail(400, null, '请上传binary文件');
//            $this->upload_base64($request);
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }

    }

    public function upload_file(Request $request, $full = 0, $type = null)
    {
        $token = $this->token;
        $proc = $this->proc;

        $file = $request->file('file');
        if (!$file) {
            Ret::Fail(400, null, 'file字段没有用文件提交');
        }
        $file_name = $file->getFileInfo('name');
        $md5 = $file->hash('md5');
        $sha1 = $file->hash("sha1");
        $mime = $file->getFileInfo('type');
        // 判断附件格式是否符合

        $file_exists = AttachmentModel::get(['token' => $token, 'md5' => $md5, 'sha1' => $sha1]);

        if ($file_exists) {
            if ($proc['type'] != 'all' || file_exists('./upload/' . $file_exists['path'])) {
                $sav = $this->getStr($full, $proc['url'], $file_exists, $type);
            }
        }
        $info = $file->move('./upload/' . $this->token);
        if (!$info) {
            Ret::Fail(300, null, $file->getError());
            return;
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
                $sav = ($full ? $proc['url'] . '/' : '') . $fileName;
            }
        }
        if ($proc["type"] == "dp") {
            $sf = new SendFile();
            $ret = $sf->send('http://' . $proc["endpoint"] . '/up?token=' . $proc["bucket"], realpath('./upload/' . $fileName), $file->getFileInfo('type'), $file->getFileInfo('name'));
            $json = json_decode($ret, 1);
            $sav = ($full ? $proc['url'] . '/' : '') . $json["data"];
        }
        if ($proc["type"] == "oss" || $proc["type"] == "all") {
            try {
                $oss = new AliyunOSS($proc);
                $ret = $oss->uploadFile($proc['bucket'], $fileName, $info->getPathname());
            } catch (OssException $e) {
                Ret::Fail(200, null, $e->getMessage());
            }
            if (empty($ret->getData()["info"]["url"])) {
                Ret::Fail(200, null, "OSS不正常");
            }
            if ($proc['main_type'] == 'oss') {
                $sav = ($full ? $proc['url'] . '/' : '') . $fileName;
            }
            if ($proc["type"] != "all") {
                unlink($info->getPathname());
            }
        }

        AttachmentModel::create($file_info);
        if ($info) {
            switch ($type) {
                case "ue":
                    Ret::Success(0, ['src' => $sav]);
                    break;

                case "complete":
                    $file_info["src"] = $sav;
                    $file_info["url"] = $proc['url'] . '/' . $file_info['path'];
                    $file_info["surl"] = $file_info['path'];
                    Ret::Success(0, $file_info);
                    break;

                default:
                    Ret::Success(0, $sav);
                    break;
            }
        } else {
            Ret::Fail(300, null, $file->getError());
        }
    }

    /**
     * @param mixed $full
     * @param $url
     * @param AttachmentModel $file_exists
     * @param mixed $type
     * @return string
     */
    public function getStr(mixed $full, $url, AttachmentModel $file_exists, mixed $type): string
    {
        $sav = ($full ? $url . '/' : '') . $file_exists['path'];
        // 附件已存在
        switch ($type) {
            case "ue":
                Ret::Success(0, ['src' => $sav]);
                break;

            case "complete":
                $file_exists["src"] = $file_exists['path'];
                $file_exists["url"] = $url . '/' . $file_exists['path'];
                $file_exists["surl"] = $file_exists['path'];
                Ret::Success(0, $file_exists);
                break;

            default:
                Ret::Success(0, $sav);
                break;
        }
        return $sav;
    }

    public function upfull(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                try {
                    $this->upload_file($request, 1);
                } catch (Exception $e) {
                    Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
                }
            } else {
                Ret::Fail(400, null, '请上传binary文件');
//            $this->upload_base64($request, 1);
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }

    }

    public function up_ue(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                $this->upload_file($request, 1, 'ue');
            } else {
                Ret::Fail(400, null, "请上传binary文件");
//            $this->upload_base64($request, 1, 1);
            }
        } catch (Throwable $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }


    public function up_complete(Request $request)
    {
        try {
            $file = $request->file('file');
            if ($file) {
                $this->upload_file($request, 1, 'complete');

            } else {
                Ret::Fail(400, null, "请上传binary文件");
//            $this->upload_base64($request, 1, 1);
            }
        } catch (Exception $e) {
            Ret::Fail(400, $e->getTraceAsString(), $e->getMessage());
        }
    }


}
