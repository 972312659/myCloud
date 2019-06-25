<?php
/**
 * 上传附件和上传视频
 * User: Jinqn
 * Date: 14-04-09
 * Time: 上午10:17
 */
include "Uploader.class.php";

/* 上传配置 */
$base64 = "upload";
switch (htmlspecialchars($_GET['action'])) {
    case 'uploadimage':
        $config = [
            "pathFormat" => $CONFIG['imagePathFormat'],
            "maxSize"    => $CONFIG['imageMaxSize'],
            "allowFiles" => $CONFIG['imageAllowFiles'],
        ];
        $fieldName = $CONFIG['imageFieldName'];
        break;
    case 'uploadscrawl':
        $config = [
            "pathFormat" => $CONFIG['scrawlPathFormat'],
            "maxSize"    => $CONFIG['scrawlMaxSize'],
            "allowFiles" => $CONFIG['scrawlAllowFiles'],
            "oriName"    => "scrawl.png",
        ];
        $fieldName = $CONFIG['scrawlFieldName'];
        $base64 = "base64";
        break;
    case 'uploadvideo':
        $config = [
            "pathFormat" => $CONFIG['videoPathFormat'],
            "maxSize"    => $CONFIG['videoMaxSize'],
            "allowFiles" => $CONFIG['videoAllowFiles'],
        ];
        $fieldName = $CONFIG['videoFieldName'];
        break;
    case 'uploadfile':
    default:
        $config = [
            "pathFormat" => $CONFIG['filePathFormat'],
            "maxSize"    => $CONFIG['fileMaxSize'],
            "allowFiles" => $CONFIG['fileAllowFiles'],
        ];
        $fieldName = $CONFIG['fileFieldName'];
        break;
}

/* 生成上传实例对象并完成上传 */
$up = new Uploader($fieldName, $config, $base64);

/**
 * 得到上传文件所对应的各个参数,数组结构
 * array(
 *     "state" => "",          //上传状态，上传成功时必须返回"SUCCESS"
 *     "url" => "",            //返回的地址
 *     "title" => "",          //新文件名
 *     "original" => "",       //原始文件名
 *     "type" => ""            //文件类型
 *     "size" => "",           //文件大小
 * )
 */
$imageInfo = $up->getFileInfo();
if ($imageInfo['state'] === 'SUCCESS') {
//上传到七牛云
    $uploadMan = new \Qiniu\Storage\UploadManager();
    $filename = $imageInfo['original'];
    $filePath = BASE_PATH . '/public/' . $imageInfo['original'];
    $bucket = 'referral';
    $token = $this->getDI()->getShared('qiniu')->uploadToken($bucket, $filename, 3600, null, true);
    list($ret, $err) = $uploadMan->putFile($token, $filename, $filePath);
    /* 返回数据 */
    if ($ret) {
        @unlink($filePath);
        return json_encode(array_merge($ret, $imageInfo));
    } else {
        var_dump($err);
    }
}
