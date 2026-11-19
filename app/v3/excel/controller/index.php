<?php

namespace app\v3\excel\controller;

use app\v3\common\V3EnvelopeAuth;
use app\v3\project\model\V3ProjectModel;
use BaseController\CommonController;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Ret;
use think\facade\Validate;
use think\Request;

/**
 * v3 Excel 开放接口：与 v1/excel 功能一致，鉴权改为 v3 凭证信封
 *
 * 项目凭证来自 ao_v3_project（后台「项目管理 v3」签发），
 * 与 v1 的 ao_oss token 体系完全独立，互不影响。
 */
class index extends CommonController
{
    use V3EnvelopeAuth;

    public function initialize()
    {
        set_time_limit(0);
        parent::initialize();

        $this->envelopeAuth(fn(string $kid) => V3ProjectModel::api_find_kid($kid), 'v3_excel_rl:');
    }

    /** create_file 落盘目录（相对 public） */
    protected function tempDir(): string
    {
        return './upload/excel/tempfile/v3/' . $this->kid;
    }

    /** create_file 对外访问 URL 前缀 */
    protected function tempUrlBase(): string
    {
        return request()->domain() . '/upload/excel/tempfile/v3/' . $this->kid;
    }

    /** 生成 xlsx 并直接输出二进制流（成功时不走 Ret 包） */
    public function create()
    {
        $data = \Input::PostJson('data');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data);

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /** 生成 xlsx 落盘并返回可下载 URL */
    public function create_file()
    {
        $data = \Input::PostJson('data');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data);

        $writer = new Xlsx($spreadsheet);
        $dir = $this->tempDir();
        if (!file_exists($dir . DIRECTORY_SEPARATOR)) {
            mkdir($dir . DIRECTORY_SEPARATOR, 0755, true);
        }
        $savename = md5(json_encode($data)) . '.xlsx';
        $writer->save($dir . DIRECTORY_SEPARATOR . $savename);
        Ret::Success(0, $this->tempUrlBase() . '/' . $savename);
    }

    /** 上传 xlsx/xls 解析为首行作 key 的行集合 */
    public function index(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            Ret::Fail(400, null, 'file字段没有用文件提交');
        }
        $reader = $this->loadUpload($request);
        $this->extracted($reader);
    }

    /** 与 index 相同（v1 兼容保留入口） */
    public function dp(Request $request)
    {
        $this->index($request);
    }

    /** 与 index 相同（v1 兼容保留入口） */
    public function force(Request $request)
    {
        $this->index($request);
    }

    /** 按已上传附件的 md5 解析（仅本地文件） */
    public function md5()
    {
        $md5 = input('md5');
        if (!$md5) {
            Ret::Fail(400, null, 'md5');
        }
        $reader = $this->loadAttachment((string)$md5);
        $this->extracted($reader);
    }

    /** 与 md5 相同（v1 兼容保留入口） */
    public function remote()
    {
        $this->md5();
    }

    /**
     * 校验并加载上传的表格文件
     * @return Spreadsheet
     */
    protected function loadUpload(Request $request): Spreadsheet
    {
        $file = $request->file('file');
        if (!Validate::fileExt($file, ['xls', 'xlsx'])) {
            Ret::Fail(406, null, 'ext not allow');
        }
        if (!Validate::fileSize($file, (float)8192 * 1024)) {
            Ret::Fail(406, null, 'size too big');
        }
        $dir = './upload/excel/v3/' . $this->kid;
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $info = $file->move($dir, $file->md5() . '.' . $file->getOriginalExtension());
        $reader = IOFactory::load($info->getPathname());
        unlink($info->getPathname());
        return $reader;
    }

    /**
     * 按附件 md5 定位本地文件并加载；本地缺失时从本站 /upload 回源取回
     * @return Spreadsheet
     */
    protected function loadAttachment(string $md5): Spreadsheet
    {
        $file_info = \app\v1\file\model\AttachmentModel::where('md5', $md5)->find();
        if (!$file_info || !file_exists('./upload/' . $file_info['path'])) {
            Ret::Fail(404, null, '文件未被上传或不属于本系统');
        }
        return IOFactory::load('./upload/' . $file_info['path']);
    }

    /**
     * 解析活动工作表：首行非空单元格作 key，后续行组装为行集合
     * @param Spreadsheet $reader
     * @return void
     */
    protected function extracted(Spreadsheet $reader): void
    {
        $datas = $reader->getActiveSheet()->toArray();
        if (count($datas) < 2) {
            Ret::Fail(400, null, '表格长度不足');
        }
        Ret::Success(0, $this->getArr($datas));
    }

    /**
     * @param array $datas
     * @return array
     */
    protected function getArr(array $datas): array
    {
        $keys = [];
        $colums = [];
        $i = 0;
        $key_count = 0;
        foreach ($datas as $data) {
            if ($i == 0) {
                foreach ($data as $val) {
                    if (!empty($val)) {
                        $keys[] = $val;
                    }
                }
                $key_count = count($keys);
            } else {
                if (is_null($data[0])) {
                    break;
                }
                $arr = [];
                for ($s = 0; $s < $key_count; $s++) {
                    $arr[$keys[$s]] = $data[$s];
                }
                $colums[] = $arr;
            }
            $i++;
        }
        return $colums;
    }
}
