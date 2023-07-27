<?php


namespace app\v1\file\controller;


use app\v1\file\action\OssSelectionAction;
use app\v1\file\model\AttachmentChunkModel;
use app\v1\project\model\ProjectModel;
use Ret;

class chunk extends dp
{


    /*
     * array(7) {
      ["id"] =&gt; string(9) "WU_FILE_0"
      ["name"] =&gt; string(12) "AUDIO002.WAV"
      ["type"] =&gt; string(9) "audio/wav"
      ["lastModifiedDate"] =&gt; string(54) "Sat Apr 21 2018 01:05:14 GMT+0800 (中国标准时间)"
      ["size"] =&gt; string(9) "191905506"
      ["chunks"] =&gt; string(2) "19"
      ["chunk"] =&gt; string(2) "18"
      }
     */

    public function upload_chunk($dir = '', $from = '', $module = '')
    {
        $token = $this->token;
        $proc = ProjectModel::api_find_token($token);
        if (!$proc) {
            return $this->uploadError($from, "项目不可用");
        }
        if ($proc['type'] == 'none') {
            return $this->uploadError($from, '本项目没有存储权限');
        }
        $proc = OssSelectionAction::App_find_byProc($proc);

        $file = request()->file('file');
        if ($file) {
            $name = input('name');
            $ext = explode('.', $name);
            $chunk = input('chunk');
            $chunks = input('chunks');
            $file_ident = md5($name . '_' . input('size') . '_' . $chunks);

            if (file_exists('./upload/chunks/' . $this->token . DIRECTORY_SEPARATOR . $file_ident . DIRECTORY_SEPARATOR . $file_ident . '_' . $chunk . '.' . end($ext))) {
                if (AttachmentChunkModel::where("token", $token)->where("ident", $file_ident)->where("chunk", $chunk)->where("chunks", $chunks)->find()) {
                    return $this->uploadSuccess($from, "分块文件已收到:" . $chunk, $name, $file_ident, "", $file_ident . '_' . $chunk);
                } else {
                    if (AttachmentChunkModel::create([
                        "token" => $token,
                        "ident" => $file_ident,
                        "chunk_name" => $file_ident,
                        "chunk" => $chunk,
                        "chunks" => $chunks,
                    ])) {
                        return $this->uploadSuccess($from, "分块数据库已写入:" . $chunk, $name, $file_ident, "", $file_ident . '_' . $chunk);
                    } else {
                        return $this->uploadError($from, "数据库写入失败");
                    }
                }
            }
            $info = $file->move('./upload/chunks/' . $this->token, $file_ident . '_' . $chunk);
            if ($info) {
                $count = AttachmentChunkModel::where($file_ident)->count();
                if ($count >= ($chunks - 1)) {
                    if (AttachmentChunkModel::where($file_ident)->data("is_complete", true)->update()) {
                        //todo:加入合并文件
                        return $this->uploadSuccess($from, "", $file_ident, $file_ident, "", $file_ident . '_' . $chunk);
                    } else {
                        return $this->uploadError($from, "数据库update失败");
                    }
                } else {
                    return $this->uploadSuccess($from, $count . "-总分块文件已收到" . $chunk, $name, $file_ident, "", $file_ident . '_' . $chunk);
                }
            } else {
                return $this->uploadError($from, "文件移动失败");
            }
        } else {
            return $this->uploadError($from, "nofile没有上传文件");
        }
    }


    public function isupload()
    {
        $pathname = config('app.video-upload-path');
        $directory = md5(session('uid') . '_' . input('size'));
        $name = input('name');
        $ext = explode('.', $name);
        if (file_exists($pathname . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $directory . '_' . input('chunk') . '.' . end($ext))) {
            $chunks = input('chunks');
            if (count(cache('file_' . $directory)) >= ($chunks - 1)) {
                Ret::Success(0, input('chunk') . '块可以上传');
            }
            if (cache('file_' . $directory) == NULL) {
                cache('file_' . $directory, [], 600);
            }
            $arr = cache('file_' . $directory);
            $arr[input('chunk')] = true;
            cache('file_' . $directory, $arr, 600);
            Ret::Success(0, input('chunk') . '分块文件已上传自动忽略', -1);
        } else {
            Ret::Success(0, input('chunk') . '块可以上传');
        }
    }

}
