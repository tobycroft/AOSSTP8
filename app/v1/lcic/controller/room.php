<?php

namespace app\v1\lcic\controller;

use app\v1\lcic\model\LcicRoomModel;
use app\v1\lcic\model\LcicUserModel;
use Input;
use Ret;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Lcic\V20220817\Models\CreateRoomRequest;
use TencentCloud\Lcic\V20220817\Models\DeleteRoomRequest;
use TencentCloud\Lcic\V20220817\Models\ModifyRoomRequest;


class room extends user
{

    public function auto()
    {
        $Name = Input::PostInt('Name');
        $TeacherId = Input::PostInt('TeacherId');
        $OriginId = Input::Post('TeacherId');
        $StartTime = Input::PostInt('StartTime');
        $EndTime = Input::PostInt('EndTime');
        $user = LcicUserModel::where('project', $this->token)->where(['OriginId' => $TeacherId])->findOrEmpty();
        if ($user->isEmpty()) {
            $this->create();
        } else {
            $this->modify();
        }
    }

    public function create()
    {
        $Name = Input::Post("Name");
        $TeacherId = Input::Post("TeacherId");
        $StartTime = Input::PostInt("StartTime");
        $EndTime = Input::PostInt("EndTime");
        $user = LcicUserModel::where('project', $this->token)->where(['OriginId' => $TeacherId])->findOrEmpty();
        if ($user->isEmpty()) {
            Ret::Fail(404, null, "教师用户不存在，请先添加");
        }
        if ($EndTime - $StartTime > 18000) {
            $EndTime = $StartTime + 18000;
        }
        try {
            $req = new CreateRoomRequest();
            $params = array(
                'Name' => $Name,
                'StartTime' => $StartTime,
                'EndTime' => $EndTime,
                'TeacherId' => $user["UserId"],
                'SdkAppId' => $this->sdkappid,
                'Resolution' => 1,
                'MaxMicNumber' => 16,
                'AutoMic' => 0,
                'AudioQuality' => 0,
                'SubType' => 'videodoc',
                'DisableRecord' => 1
            );
            $req->fromJsonString(json_encode($params));
            $resp = $this->client->CreateRoom($req);
            LcicRoomModel::create([
                'project' => $this->token,
                'Name' => $Name,
                'StartTime' => $StartTime,
                'EndTime' => $EndTime,
                'TeacherId' => $user['UserId'],
                'SdkAppId' => $this->sdkappid,
                'Resolution' => 1,
                'MaxMicNumber' => 16,
                'AutoMic' => 0,
                'AudioQuality' => 0,
                'SubType' => 'videodoc',
                'DisableRecord' => 1,
                'RoomId' => $resp->getRoomId(),
            ]);
            // 输出json格式的字符串回包
            Ret::Success(0, $resp, $resp->getRoomId());
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

    public function modify()
    {
        $Name = Input::Post('Name');
        $RoomId = Input::PostInt('RoomId');
        $TeacherId = Input::PostInt('TeacherId');
        $StartTime = Input::PostInt('StartTime');
        $EndTime = Input::PostInt('EndTime');
        $user = LcicUserModel::where('project', $this->token)->where(['OriginId' => $TeacherId])->findOrEmpty();
        if ($user->isEmpty()) {
            Ret::Fail(404, null, '教师用户不存在，请先添加');
        }
        if ($EndTime - $StartTime > 18000) {
            $EndTime = $StartTime + 18000;
        }
        try {
            $req = new ModifyRoomRequest();
            $params = array(
                'RoomId' => $RoomId,
                'Name' => $Name,
                'StartTime' => $StartTime,
                'EndTime' => $EndTime,
                'TeacherId' => $user['UserId'],
                'SdkAppId' => $this->sdkappid,
                'Resolution' => 1,
                'MaxMicNumber' => 16,
                'AutoMic' => 0,
                'AudioQuality' => 0,
                'SubType' => 'videodoc',
                'DisableRecord' => 1
            );
            $req->fromJsonString(json_encode($params));
            $resp = $this->client->ModifyRoom($req);
            LcicRoomModel::where("project", $this->token)
                ->where("RoomId", $RoomId)
                ->data(
                    [
                        'Name' => $Name,
                        'StartTime' => $StartTime,
                        'EndTime' => $EndTime,
                        'TeacherId' => $user['UserId'],
                        'Resolution' => 1,
                        'MaxMicNumber' => 16,
                        'AutoMic' => 0,
                        'AudioQuality' => 0,
                        'SubType' => 'videodoc',
                        'DisableRecord' => 1
                    ]
                )
                ->update();
            // 输出json格式的字符串回包
            Ret::Success(0, $resp, $RoomId);
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

    public function link()
    {
        $OriginId = Input::Post('OriginId');
        $TeacherId = Input::Post('TeacherId');
        $student = LcicUserModel::where('project', $this->token)->where('OriginId', $OriginId)->findOrEmpty();
        if ($student->isEmpty()) {
            Ret::Fail(404, null, "学生还未绑定，请先绑定老师");
        }
        $teacher = LcicUserModel::where('project', $this->token)->where('OriginId', $TeacherId)->findOrEmpty();
        if ($teacher->isEmpty()) {
            Ret::Fail(404, null, "老师还未绑定，请先绑定老师");
        }
        $room = LcicRoomModel::where("project", $this->token)
            ->where("TeacherId", $teacher["UserId"])
            ->where("EndTime", ">", time())
            ->order("StartTime asc")
            ->findOrEmpty();
        if ($room->isEmpty()) {
            Ret::Fail(404, null, '没有正在运行的房间');
        }
        $weburl = $this->weburl($student["UserId"], $student["Token"], $room["RoomId"]);
        $pcurl = $this->pcurl($student["UserId"], $student["Token"], $room["RoomId"]);

        Ret::Success(0, [
            "web" => $weburl,
            "pc" => $pcurl,
        ], $weburl);
    }

    public function delete()
    {
        $RoomId = Input::PostInt('RoomId');
        try {
            $req = new DeleteRoomRequest();
            $params = array(
                'RoomId' => $RoomId,
            );
            $req->fromJsonString(json_encode($params));
            $resp = $this->client->DeleteRoom($req);
            LcicUserModel::where("RoomId", $RoomId)->delete();
            // 输出json格式的字符串回包
            Ret::Success(0, $resp, $resp->getRoomId());
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

}