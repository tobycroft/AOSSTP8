<?php

namespace app\v1\hook\controller;

use BaseController\CommonController;

class ssh extends CommonController
{
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