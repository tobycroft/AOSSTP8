<?php

namespace app\v1\nlp\controller;


use AlibabaCloud\SDK\Alinlp\V20200629\Alinlp;
use AlibabaCloud\SDK\Alinlp\V20200629\Models\GetSaChGeneralRequest;
use AlibabaCloud\Tea\Exception\TeaUnableRetryError;
use app\v1\image\controller\create;
use app\v1\nlp\model\NlpAliyunModel;
use app\v1\nlp\model\NlpModel;
use Darabonba\OpenApi\Models\Config;

class index extends create
{

    protected $nlp_proc = [];

    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $project = NlpModel::where("project", $this->token)->findOrEmpty();
        if (empty($project)) {
            \Ret::Fail(404, null, "项目不存在");
        } else {
            $this->nlp_proc = $project->toArray();
        }
        switch ($this->nlp_proc["type"]) {
            case "aliyun":
                $this->aliyun();
                break;
        }
    }

    public function aliyun()
    {
        $text = \Input::Post("text");
        $ali = NlpAliyunModel::where('tag', $this->nlp_proc['tag'])->find();

        $config = new Config();
        $config->accessKeyId = $ali["accessKeyId"];
        $config->accessKeySecret = $ali["accessKeySecret"];
        $config->regionId = $ali["regionId"];
        $config->endpoint = $ali["endpoint"];
        $client = new Alinlp($config);
        $request = new GetSaChGeneralRequest();
        $request->serviceCode = 'alinlp';
        $request->text = $text;

        try {
            $response = $client->getSaChGeneral($request);
            $ret = $response->body->toMap();
            $data = json_decode($ret['Data'], true);
            \Ret::Success(0, $data);
        } catch (TeaUnableRetryError $e) {
            \Ret::Fail(500, $e->getTraceAsString(), $e->getMessage());
        }
    }
}