<?php

namespace app\v1\rcon\controller;

use app\v1\rcon\action\ShowPlayerAction;
use Ret;

class palworld extends index
{
    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
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
        $ret = ($this->conn->command($query));
        Ret::Success(0, $ret,);
    }

    public function kick()
    {
        $id = \Input::Post('id');
        $query = "KickPlayer " . ($id) . "";
        $ret = ($this->conn->command($query));
        Ret::Success(0, $ret,);
    }
}