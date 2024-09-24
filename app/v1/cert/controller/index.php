<?php

namespace app\v1\hook\controller;

use app\v1\project\model\ProjectModel;
use BaseController\CommonController;
use Input;
use Ret;

class index extends CommonController
{

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();
        if (!$this->token) {
            $this->token = Input::Combi('token');
        }
        $this->proc = ProjectModel::api_find_token($this->token);
        if (!$this->proc) {
            Ret::Fail(401, null, '项目不可用');
        }
    }

    public function test()
    {
        $connection = ssh2_connect("10.0.0.182", 22);
        if (ssh2_auth_password($connection, 'username', 'password')) {
            echo "Authentication Successful\n";

            // 执行远程命令
            $stream = ssh2_exec($connection, 'ls -l');
            stream_set_blocking($stream, true);
            $data = '';
            while ($buffer = fread($stream, 4096)) {
                $data .= $buffer;
            }
            fclose($stream);

            echo $data;
        } else {
            echo "Authentication Failed\n";
        }
    }
}