<?php

namespace app\v1\rcon\controller;

use app\v1\rcon\action\ShowPlayerAction;
use Kekalainen\GameRQ\Rcon\SourceRcon;
use Ret;

class palworld extends index
{
    public SourceRcon $conn;
    
    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->connect();
    }

    private function connect()
    {
        $this->conn = new SourceRcon;
        $this->conn->connect($this->rcon_info['ip'], $this->rcon_info['port'], $this->rcon_info['password'], 3);
        if (trim($this->conn->command('Ping')) != 'Pong') {
            Ret::Fail(500, null, '连接服务器失败');
        }
    }

    public function ping()
    {
        $ping = trim($this->conn->command('Ping'));
        Ret::Success(0, $ping);
    }

    public function manual()
    {
        $query = \Input::Post('query');
        $ret = ($this->conn->command($query));
        Ret::Success(0, $ret,);
    }

    public function info()
    {
        $query = "Info";
        $ret = ($this->conn->command($query));
        Ret::Success(0, $ret);
    }

    public function save()
    {
        $query = "Save";
        $ret = trim($this->conn->command($query));
        Ret::Success(0, $ret);
    }

    public function players()
    {
        $query = "ShowPlayers";
        $ret = ($this->conn->command($query));
        $players = ShowPlayerAction::input($ret);
        Ret::Success(0, $players, $ret);
    }

    public function broadcast()
    {
        $message = \Input::Post('message');
        $query = "Broadcast " . ($message) . "";
        $ret = trim($this->conn->command($query));
        Ret::Success(0, $ret,);
    }

    public function kick()
    {
        $id = \Input::Post('id');
        $query = "KickPlayer " . ($id) . "";
        $ret = trim($this->conn->command($query));
        Ret::Success(0, $ret,);
    }

    public function ban()
    {
        $id = \Input::Post('id');
        $query = "BanPlayer " . ($id) . "";
        $ret = trim($this->conn->command($query));
        Ret::Success(0, $ret,);
    }
}