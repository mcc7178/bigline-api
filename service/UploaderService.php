<?php
/**
 * @author      : Kobin
 * @CreateTime  : 2020/10/9 11:32
 * @copyright Copyright (c) Bigline Group
 */

namespace app\api\service;


use app\api\lib\BizException;
use comm\model\SystemAttachment as SystemAttachmentModel;
use comm\service\upload\Upload;

class UploaderService
{
    const PATH_ATTACH = 'attach';
    const PATH_PDF = 'pdf';
    private $path = 'attach';

    public function __construct($path = 'attach')
    {
        $this->path = $path;
    }

    /**
     * 图片管理上传图片
     * @param \think\Request $request
     * @return array
     * @throws \Exception
     */
    public function upload(\think\Request $request)
    {
        $upload_type = env('upload.type', 3);
        try {
            $path = make_path('attach', 2, true);
            $upload = new Upload((int)$upload_type, [
                'accessKey' => env('ALIYUN_OSS.accessKey'),
                'secretKey' => env('ALIYUN_OSS.secretKey'),
                'uploadUrl' => env('ALIYUN_OSS.uploadUrl'),
                'storageName' => env('ALIYUN_OSS.storageName'),
                'storageRegion' => env('ALIYUN_OSS.storageRegion'),
            ]);
            $res = $upload->to($path)->validate()->move();
            if ($res === false) {
                BizException::throwException(9000, '上載失敗：' . $e->getMessage());
            } else {
                $fileInfo = $upload->getUploadInfo();
                if ($fileInfo) {
                    $attachment = SystemAttachmentModel::attachmentAdd($fileInfo['name'], $fileInfo['size'], $fileInfo['type'], $fileInfo['dir'], $fileInfo['thumb_path'], 0, $upload_type, $fileInfo['time']);
                    return [
                        'att_id' => (int)$attachment->att_id,
                        'src' => $res->filePath
                    ];
                }
            }
        } catch (\Exception $e) {
            BizException::throwException(9000, '上載失敗：' . $e->getFile() . $e->getLine() . $e->getMessage());
        }
    }

}