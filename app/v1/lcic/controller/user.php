<?php

namespace app\v1\lcic\controller;

use app\v1\image\controller\create;
use app\v1\lcic\model\LcicModel;
use app\v1\lcic\model\LcicUserModel;
use Ret;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Lcic\V20220817\LcicClient;
use TencentCloud\Lcic\V20220817\Models\LoginUserRequest;
use TencentCloud\Lcic\V20220817\Models\ModifyUserProfileRequest;
use TencentCloud\Lcic\V20220817\Models\RegisterUserRequest;


class user extends create
{

    public string|null $appid;
    public string|null $secretid;
    public string|null $secretkey;
    public string|null $end_point;

    public int|null $sdkappid;

    protected mixed $lcic;

    protected Credential $cred;
    protected HttpProfile $httpProfile;
    protected ClientProfile $clientProfile;

    protected LcicClient $client;


    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->lcic = LcicModel::where('project', $this->token)->find();
        if (!$this->lcic) {
            Ret::Fail(404, null, '未找到项目');
        }
        $this->appid = $this->lcic['appid'];
        $this->secretid = $this->lcic['secretid'];
        $this->secretkey = $this->lcic['secretkey'];
        $this->sdkappid = $this->lcic['sdkappid'];
        $this->end_point = $this->lcic['end_point'];
        try {
            if (!isset($this->cred)) {
                $this->cred = new Credential($this->secretid, $this->secretkey);
            }
            if (!isset($this->httpProfile)) {
                $this->httpProfile = new HttpProfile();
                $this->httpProfile->setEndpoint($this->end_point);
            }
            if (!isset($this->clientProfile)) {
                $this->clientProfile = new ClientProfile();
                $this->clientProfile->setHttpProfile($this->httpProfile);
            }
            if (!isset($this->client)) {
                $this->client = new LcicClient($this->cred, '', $this->clientProfile);
            }
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

    public function auto()
    {
        $OriginId = \Input::Post('OriginId');
        $user = LcicUserModel::where('project', $this->token)->where('OriginId', $OriginId)->findOrEmpty();
        if ($user->isEmpty()) {
            $this->create();
        } else {
            $this->modify();
        }
    }

    public function create()
    {
        $Name = \Input::Post("Name");
        $OriginId = \Input::Post("OriginId");
        $Avatar = \Input::Post("Avatar");
        try {
            $req = new RegisterUserRequest();

            $params = array(
                'Name' => $Name,
                'SdkAppId' => $this->sdkappid,
//                'OriginId' => $OriginId,
                'Avatar' => $Avatar,
            );
            $req->fromJsonString(json_encode($params));
            $resp = $this->client->RegisterUser($req);

            // 输出json格式的字符串回包
//            print_r($resp->toJsonString());
            LcicUserModel::create([
                'project' => $this->token,
                'OriginId' => $OriginId,
                'Name' => $Name,
                'Avatar' => $Avatar,
                'UserId' => $resp->getUserId(),
                'Token' => $resp->getToken(),
                'change_date' => date('Y-m-d H:i:s', time() + 86400 * 6),
            ]);
            Ret::Success(0, $resp, $resp->getToken());
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

    public function modify()
    {
        $Name = \Input::Post('Name');
        $OriginId = \Input::Post('OriginId');
        $Avatar = \Input::Post('Avatar');
        $user = LcicUserModel::where("project", $this->token)
            ->where("OriginId", $OriginId)
            ->field('Name,Avatar,UserId,Token,change_date')
            ->findOrEmpty();
        if ($user->isEmpty()) {
            $this->create();
        } elseif ($user["Name"] != $Name || $user["Avatar"] != $Avatar) {
            try {
                // 实例化一个请求对象,每个接口都会对应一个request对象
                $req = new ModifyUserProfileRequest();
                $params = array(
                    'UserId' => $user['UserId'],
                    'Nickname' => $Name,
                    'Avatar' => $Avatar,
                );
                $req->fromJsonString(json_encode($params));

                // 返回的resp是一个ModifyUserProfileResponse的实例，与请求对象对应
                $resp = $this->client->ModifyUserProfile($req);
                LcicUserModel::where([
                    'project' => $this->token,
                    'OriginId' => $OriginId,
                ])->update([
                    'Name' => $Name,
                    'Avatar' => $Avatar,
                ]);
                // 输出json格式的字符串回包
                Ret::Success(0, $user->field("UserId,Token")->findOrEmpty(), $user['Token']);
            } catch (TencentCloudSDKException $e) {
                Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
            }
        } else if (strtotime($user["change_date"]) < time()) {
            $this->login();
        } else {
            Ret::Success(0, $user, $user["Token"]);
        }
    }

    public function login($user_id = null)
    {
        if (!$user_id) {
            $OriginId = \Input::Post('OriginId');
            $user = LcicUserModel::where('project', $this->token)
                ->where('OriginId', $OriginId)
                ->field('UserId,Token')
                ->findOrEmpty();
            if ($user->isEmpty()) {
                $this->create();
            } else {
                $user_id = $user["UserId"];
            }
        }
        try {
            $req = new LoginUserRequest();
            $params = array(
                'UserId' => $user_id,
            );
            $req->fromJsonString(json_encode($params));

            // 返回的resp是一个LoginUserResponse的实例，与请求对象对应
            $resp = $this->client->LoginUser($req);
            LcicUserModel::where([
                'project' => $this->token,
                'UserId' => $user_id,
            ])->update([
                'Token' => $resp->getToken(),
                'UserId' => $resp->getUserId(),
                'change_date' => date('Y-m-d H:i:s', time() + 86400 * 6),
            ]);
            Ret::Success(0, $resp, $resp->getToken());
        } catch (TencentCloudSDKException $e) {
            Ret::Fail(500, $e->getErrorCode(), $e->getMessage());
        }
    }

    protected function weburl($userid, $token, $classid)
    {
        return "https://class.qcloudclass.com/1.7.2/index.html?userid=$userid&token=$token&classid=$classid";
    }

    protected function pcurl($userid, $token, $classid)
    {
        return "tcic://class.qcloudclass.com/1.7.2/class.html?userid=$userid&token=$token&classid=$classid";
    }
}