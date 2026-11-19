<?php

namespace app\v3\excel\controller;

use app\v1\excel\model\ExcelModel;
use app\v1\file\model\AttachmentModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ret;

/**
 * v3 Excel 查询接口：与 v1/excel/search 功能一致，带 ao_excel 解析缓存
 */
class search extends index
{
    public function md5()
    {
        $md5 = \Input::Post('md5');
        if (!$md5) {
            Ret::Fail(400, null, 'md5');
        }
        $file_info = AttachmentModel::where('md5', $md5)->find();
        if (!$file_info) {
            Ret::Fail(404, null, '文件未被上传或不属于本系统');
        }

        $excel_info = ExcelModel::where('md5', $md5)->find();
        if ($excel_info) {
            Ret::Success(0, json_decode($excel_info['value'], 1));
        }
        if (!file_exists('./upload/' . $file_info['path'])) {
            Ret::Fail(404, null, '本地文件不存在');
        }

        $reader = IOFactory::load('./upload/' . $file_info['path']);
        $datas = $reader->getActiveSheet()->toArray();
        if (count($datas) < 2) {
            Ret::Fail(400, null, '表格长度不足');
        }
        $colums = $this->getArr($datas);
        ExcelModel::create([
            'project' => $this->kid,
            'md5' => $md5,
            'value' => json_encode($colums, 320),
        ]);
        Ret::Success(0, $colums);
    }
}
