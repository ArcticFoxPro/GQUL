<?php

// 需要提取的文件列表
$filesToExtract = [
    'assets/appid.ini',
    'assets/jni.ini',
    'assets/qua.ini',
    'assets/revision.txt',
    'lib/arm64-v8a/libfekit.so'
];

/**
 * 获取必要的参数
 *
 * @return array|false 成功返回参数数组，失败返回 false
 */
function getParameters(): false|array
{
    $requiredParams = ['url'];
    $result = [];
    foreach ($requiredParams as $param) {
        if (!empty($_GET[$param])) {
            $result[$param] = $_GET[$param];
        } else {
            return false;
        }
    }
    $result['check'] = !empty($_GET['check']) ? $_GET['check'] : 'yes';
    return $result;
}

/**
 * 获取文件信息
 *
 * @param string $url 文件的 URL
 * @return array|false 成功返回文件信息数组，失败返回 false
 */
function getFileInfo(string $url): false|array
{
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_NOBODY, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        return false;
    }
    curl_close($curl);
    $headers = explode("\n", $response);
    $md5 = null;
    $contentLength = null;
    foreach ($headers as $header) {
        if (str_starts_with($header, 'X-COS-META-MD5:')) {
            $md5 = trim(substr($header, strpos($header, ':') + 1));
        } elseif (str_starts_with($header, 'Content-Length:')) {
            $contentLength = trim(substr($header, strpos($header, ':') + 1));
        } elseif (str_starts_with($header, 'Etag:')) {
            $md5 = str_replace(['"', "\r", "\n"], '', substr($header, strpos($header, ':') + 2));
        }
    }
    $sizeMB = number_format($contentLength / (1024 * 1024), 2);
    return [
        'md5' => $md5,
        'sizeMB' => $sizeMB
    ];
}

/**
 * 设置 cURL 选项
 *
 * @param CurlHandle $ch cURL 句柄
 * @param string $url 文件的 URL
 * @param string $range 请求范围
 * @param bool $isHead 是否执行 HEAD 请求
 */
function setCurlOptions(CurlHandle $ch, string $url, string $range, bool $isHead = false): void
{
    $headers = ["Range: bytes=$range"];
    if ($isHead) {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
}

/**
 * 下载数据
 *
 * @param string $url 文件的 URL
 * @param string $range 请求范围
 * @return int|string 返回数据字符串或 int 如果失败 0文件不存在 1下载失败
 */
function downloadData(string $url, string $range, bool $isHead = false): int|string
{
    if ($isHead) {
        $ch = curl_init();
        setCurlOptions($ch, $url, $range, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 && $httpCode !== 206) {
            return 0;
        }
    }
    $ch = curl_init();
    setCurlOptions($ch, $url, $range);
    $data = curl_exec($ch);
    curl_close($ch);
    if ($data === false) {
        return 1;
    }
    return $data;
}

/**
 * 并行下载数据
 *
 * @param string $url 文件的 URL
 * @param array $ranges 请求范围数组
 * @return array 返回响应数据数组
 */
function downloadDataParallel(string $url, array $ranges): array
{
    $mh = curl_multi_init();
    $handles = [];
    $responses = [];
    foreach ($ranges as $fileName => $range) {
        $ch = curl_init();
        setCurlOptions($ch, $url, $range);
        curl_multi_add_handle($mh, $ch);
        $handles[$fileName] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh);
        }
    } while ($running > 0);
    foreach ($handles as $fileName => $ch) {
        $responses[$fileName] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $responses;
}

/**
 * 查找中央目录结束位置
 *
 * @param string $url 文件的 URL
 * @return array|int 返回中央目录结束位置信息数组或 int 如果失败 0文件不存在 1下载失败 2找不到EOCD标记 3找不到EOCD数据
 */
function findEndOfCentralDirectory(string $url): int|array
{
    $eocdMaxSize = 22; // 从末尾往前找 EOCD 数据的最大长度
    $data = downloadData($url, '-' . $eocdMaxSize, true); // 从文件末尾开始下载 EOCD 数据
    if ($data === 0 || $data === 1) {
        return $data;
    }
    $eocdPos = strrpos($data, "\x50\x4b\x05\x06"); // 寻找 EOCD 标记
    if ($eocdPos === false) {
        return 2;
    }
    $totalNum = unpack('v', substr($data, $eocdPos + 10, 2))[1]; // 解析总文件数
    $size = unpack('V', substr($data, $eocdPos + 12, 4))[1]; // 中央目录的总大小
    $offset = unpack('V', substr($data, $eocdPos + 16, 4))[1]; // 中央目录相对于ZIP文件第一个entry的起始位置
    if ($size === false || $offset === false || $totalNum === false) {
        return 3;
    }
    return [
        'totalNum' => $totalNum,
        'centralDirectorySize' => $size,
        'centralDirectoryOffset' => $offset
    ];
}

/**
 * 解析中央目录数据
 *
 * @param string $data 中央目录数据
 * @param array $filesToExtract 需要解析的文件列表
 * @return array 返回解析后的文件条目数组
 */
function parseCentralDirectory(string $data, array $filesToExtract): array
{
    $entries = [];
    $offset = 0;
    while ($offset + 4 < strlen($data)) {
        // 是否解析符合要求
        if (unpack('V', substr($data, $offset, 4))[1] !== 0x02014b50) {
            break;
        }
        // 获取文件名长度
        $fileNameLength = unpack('v', substr($data, $offset + 28, 2))[1];

        // 获取文件名
        $fileName = substr($data, $offset + 46, $fileNameLength);

        // 判断文件是否需要解析
        if (in_array($fileName, $filesToExtract)) {
            // 解析文件数据
            $entry = [
                'compressionMethod' => unpack('v', substr($data, $offset + 10, 2))[1],
                'lastModTime' => unpack('v', substr($data, $offset + 12, 2))[1],
                'lastModDate' => unpack('v', substr($data, $offset + 14, 2))[1],
                'crc32' => unpack('V', substr($data, $offset + 16, 4))[1],
                'compressedSize' => unpack('V', substr($data, $offset + 20, 4))[1],
                'uncompressedSize' => unpack('V', substr($data, $offset + 24, 4))[1],
                'fileNameLength' => $fileNameLength,
                'extraFieldLength' => unpack('v', substr($data, $offset + 30, 2))[1],
                'fileCommentLength' => unpack('v', substr($data, $offset + 32, 2))[1],
                'fileHeaderOffset' => unpack('V', substr($data, $offset + 42, 4))[1],
                'fileName' => $fileName
            ];

            // 保存解析的数据
            $entries[$entry['fileName']] = $entry;

            // 添加到已找到的文件列表
            $foundFiles[] = $fileName;
            if (count($foundFiles) === count($filesToExtract)) { // 判断是否找到所有需要的文件
                break;
            }
        }

        // 计算下一个文件条目的偏移量
        $nextEntryOffset = 46 + $fileNameLength + unpack('v', substr($data, $offset + 30, 2))[1] + unpack('v', substr($data, $offset + 32, 2))[1];
        $offset += $nextEntryOffset;
    }
    return $entries;
}

/**
 * DOS 时间转换为 Unix 时间戳
 *
 * @param int $dosTime DOS 时间
 * @param int $dosDate DOS 日期
 * @return false|int 成功返回 Unix 时间戳，失败返回 false
 */
function dosToUnixTime(int $dosTime, int $dosDate): false|int
{
    $seconds = ($dosTime & 0x1F) * 2;
    $minutes = ($dosTime >> 5) & 0x3F;
    $hours = ($dosTime >> 11) & 0x1F;
    $day = $dosDate & 0x1F;
    $month = ($dosDate >> 5) & 0x0F;
    $year = (($dosDate >> 9) & 0x7F) + 1980;
    return mktime($hours, $minutes, $seconds, $month, $day, $year);
}

/**
 * 提取并打印文件信息
 *
 * @param string $centralDirectoryData 中央目录数据
 * @param string $url 文件的 URL
 * @param array $filesToExtract 需要提取的文件列表
 * @return string 返回 JSON 编码的文件信息
 */
function extractAndPrintFiles(string $centralDirectoryData, string $url, array $filesToExtract): string
{
    $entries = parseCentralDirectory($centralDirectoryData, $filesToExtract);
    $ranges = [];
    foreach ($entries as $entry) {
        $fileDataOffset = $entry['fileHeaderOffset'] + 30 + $entry['fileNameLength'] + $entry['extraFieldLength'];
        $fileDataRange = $fileDataOffset . '-' . ($fileDataOffset + $entry['compressedSize'] - 1);
        // 判断是否是.so文件
        if (!str_ends_with($entry['fileName'], '.so')) {
            $ranges[$entry['fileName']] = $fileDataRange;
        }
    }
    $responses = downloadDataParallel($url, $ranges);
    $filesInfo = [];
    foreach ($entries as $entry) {
        $fileName = $entry['fileName'];
        $fileData = $responses[$fileName] ?? '';
        if ($fileData === '') {
            $fileContent = null;
        } else {
            if ($entry['compressionMethod'] == 8) { // DEFLATE
                $fileContent = @gzinflate($fileData);
                if ($fileContent === false) {
                    continue;
                }
            } elseif ($entry['compressionMethod'] == 0) { // STORED
                $fileContent = $fileData;
            } else {
                continue; // 不支持的压缩方法，跳过文件
            }
        }
        if ($fileName === 'assets/qua.ini') { // 处理特殊文件
            $startingPos = strpos($fileContent, "V1_");
            if ($startingPos !== false) {
                $fileContent = substr($fileContent, $startingPos);
            }
            $fileContent = str_replace("\n", '', $fileContent);
            if (str_ends_with($fileContent, '_')) {
                $fileContent = substr($fileContent, 0, -1);
            }
        }
        $lastModified = date("Y-m-d H:i:s", dosToUnixTime($entry['lastModTime'], $entry['lastModDate']));
        $fileInfo = [
            'compressionType' => $entry['compressionMethod'],
            'compressionSize' => $entry['compressedSize'],
            'decompressionSize' => $entry['uncompressedSize'],
            'fileName' => substr($fileName, strrpos($fileName, '/') + 1),
            'crc32' => sprintf("%08x", $entry['crc32']),
            'lastModified' => ($lastModified === '1979-11-30 00:00:00') ? null : $lastModified
        ];
        if (str_ends_with($fileName, '.so')) {
            $fileInfo['sizeMB'] = number_format($entry['uncompressedSize'] / (1024 * 1024), 2);
        }
        if (!str_ends_with($entry['fileName'], '.so')) {
            $fileInfo['content'] = $fileContent;
        }
        $filesInfo[] = $fileInfo;
        unset($fileData, $fileContent); // 释放不再需要的资源
    }
    return json_encode($filesInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

header('Content-Type: application/json');

$param = getParameters();
if ($param === false) {
    $result = [
        'error' => 'Bad Request'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
$apkUrl = $param['url'];
$apkUrlBase64 = base64_encode($apkUrl);

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1');
    $password = '1008611';
    if (!$redis->auth($password)) {
        throw new RedisException('Redis authentication failed');
    }
    $redis->select(7);
    if ($redis->exists($apkUrlBase64) && $param['check'] === 'yes') {
        $result = $redis->get($apkUrlBase64);
        $redis->close();
        exit($result);
    }
} catch (RedisException $e) {
    $result = [
        'error' => 'Redis error：' . $e->getMessage()
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$eocd = findEndOfCentralDirectory($apkUrl); // 查找 EOCD
if ($eocd === 0) {
    $result = [
        'error' => 'File not found'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} elseif ($eocd === 1) {
    $result = [
        'error' => 'Download data failed'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} elseif ($eocd === 2) {
    $result = [
        'error' => 'Not a zip file'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
} elseif ($eocd === 3) {
    $result = [
        'error' => 'Failed to retrieve EOCD information'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$centralDirectoryData = downloadData($apkUrl, $eocd['centralDirectoryOffset'] . '-' . ($eocd['centralDirectoryOffset'] + $eocd['centralDirectorySize'] - 1)); // 获取中央目录数据
if ($centralDirectoryData === 1) {
    $result = [
        'error' => 'Download data failed'
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$fileJson = extractAndPrintFiles($centralDirectoryData, $apkUrl, $filesToExtract); // 提取文件信息

$fileInfo = getFileInfo($apkUrl); // 获取文件总大小和MD5

if ($fileInfo) {
    $fileInfo['totalNum'] = $eocd['totalNum'];
    $fileInfo['files'] = json_decode($fileJson, true); // 合并文件信息
    $result = json_encode($fileInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    $result = json_encode($fileJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

try {
    $redis->set($apkUrlBase64, $result); // 缓存结果
} catch (RedisException $e) {
    $result = [
        'error' => 'Redis error：' . $e->getMessage()
    ];
    exit(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

exit($result); // 输出结果