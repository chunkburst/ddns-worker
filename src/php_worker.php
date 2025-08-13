<?php
header('Content-Type: application/json');

/**
 * DDNS 主控端(PHP版)
 * 理论和CF worker版的主控提供相同的服务
 * 即worker端主控和php worker端主控可以任选一个使用~
 */

define('CF_API_KEY', 'autoccb'); //CF的global key
define('CF_API_EMAIL', 'autoccb@autoccb.ccb'); //注册CF的邮箱
define('CF_ZONE_NAME', 'autoccb.ccb'); //主域名

define('API_KEY', 'apikey'); //客户端验证密钥(通信密钥)
define('DEFAULT_TTL', 1); //默认ttl,如果客户端不传递ttl就会使用此参数(1在CF代表auto)

// 内部常量配置
define('HIDE_IP_SEGMENTS', true); // 设置为true时，隐藏IP的C段和D段（最后两段）
define('DEFAULT_NODE_NAME', '主节点'); // 默认节点名称，如果客户端未提供则使用此值

//[不知道这两个是干什么的可以无视]
define('CACHE_CF_RECORD_ID', true); //是否开启record_id缓存(可减少与CF的通信次数)
define('CACHE_FILE_NAME', 'cf_cache.db'); //缓存文件名

// IP地址隐藏处理函数
function maskIPAddress($ip) {
    if (!HIDE_IP_SEGMENTS) {
        return $ip;
    }
    
    // 处理IPv4地址
    if (strpos($ip, '.') !== false) {
        $segments = explode('.', $ip);
        if (count($segments) === 4) {
            return $segments[0] . '.' . $segments[1] . '.*.*';
        }
    }
    
    // 处理IPv6地址（隐藏最后4段）
    if (strpos($ip, ':') !== false) {
        $segments = explode(':', $ip);
        if (count($segments) >= 4) {
            $visiblePart = implode(':', array_slice($segments, 0, count($segments) - 4));
            return $visiblePart . ':****:****:****:****';
        }
    }
    
    return $ip;
}

// Telegram通知函数（如果需要的话）
function sendTelegramNotification($action, $recordName, $ip, $nodeName) {
    // 如果没有配置Telegram，直接返回
    if (!defined('TG_BOT_TOKEN') || !defined('TG_CHANNEL_ID')) {
        return;
    }
    
    $action = ($action == 'updated') ? '更新' : '创建';
    $displayIP = maskIPAddress($ip);
    
    $message = "🚀 CCB-DDNS\n";
    $message .= "- 节点名称: {$nodeName}\n";
    $message .= "- 记录变更: " . strtoupper($action) . "\n";
    $message .= "- 记录名称: {$recordName}\n";
    $message .= "- 新 IP: {$displayIP}";
    
    $telegramUrl = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $telegramUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'chat_id' => TG_CHANNEL_ID,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (!$data['ok']) {
        error_log('TG Error: ' . json_encode($data));
    }
}


$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if(!preg_match('/Bearer\s+(.+)/i', $auth, $matches) || $matches[1] !== API_KEY){
    errno(501, ['error' => 'Unauthorized']);
}

$data = json_decode(file_get_contents('php://input'), true);
if(json_last_error() !== JSON_ERROR_NONE || !isset($data['prefix']) || !isset($data['ip'])){
    errno(502, ['error' => 'Bad Gateway: Invalid JSON or Invalid Data']);
}

$prefix = trim($data['prefix']);
$ip = trim($data['ip']);
$type = strtoupper($data['type'] ?? 'A');
$ttl = (int)($data['ttl'] ?? DEFAULT_TTL);
$nodeName = $data['node_name'] ?? DEFAULT_NODE_NAME;

if($type !== 'A' && $type !== 'AAAA'){
    errno(503, ['error' => 'Error type']);
}

if (($type === 'A' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ||
    ($type === 'AAAA' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
    errno(504, ['error' => 'IP address not match record type']);
}

$pubHeader = [
    'X-Auth-Email: ' . CF_API_EMAIL,
    'X-Auth-Key: ' . CF_API_KEY,
    'Content-Type: application/json'
];

$cache = cache_load();
$db = $cache === false ? [] : $cache;
$recordName = $prefix . '.' . CF_ZONE_NAME;

if($db == NULL || !isset($db[CF_ZONE_NAME])){
    $api = 'https://api.cloudflare.com/client/v4/zones?name=' . CF_ZONE_NAME;
    $data = json_decode(request($api, $pubHeader), true);
    $zone_id = $data['result'][0]['id'] ?? NULL;
    if(!$zone_id){
        errno(-1, ['error' => 'Internal Zone Error']);
    }
    if(CACHE_CF_RECORD_ID){
        $db[CF_ZONE_NAME] = $zone_id;
        cache_save($db);
    }
}else{
    $zone_id = $db[CF_ZONE_NAME];
}

$api = "https://api.cloudflare.com/client/v4/zones/" . $zone_id . "/dns_records?type={$type}&name={$recordName}";
$recordsData = json_decode(request($api, $pubHeader), true);
$lastrecord = ($recordsData['success']
               && isset($recordsData['result'])
               && isset($recordsData['result'][0])) ? $recordsData['result'][0] : null;

$baseApi = "https://api.cloudflare.com/client/v4/zones/" . $zone_id . "/dns_records";
$param = [
    'type' => $type,
    'name' => $recordName,
    'content' => $ip,
    'ttl' => $ttl ?? DEFAULT_TTL,
    'proxied' => false //不要小黄云
];
$method = 'POST';
$recordId = false;

//如果已经存在dns记录就选择覆盖
if($lastrecord){
    $method = 'PUT';
    $recordId = $lastrecord['id'];
    $baseApi .= "/{$recordId}";
    $param['id'] = $recordId;
}

$caller = json_decode(request($baseApi, $pubHeader, [
    'method' => $method,
    'data' => $param
]), true);

if($caller['success']){
    // 发送Telegram通知（如果配置了的话）
    $action = $recordId ? 'updated' : 'created';
    sendTelegramNotification($action, $recordName, $ip, $nodeName);
    
    errno(200, [
        'success' => true,
        'action'  => $action,
        'record'  => $caller['result']
    ]);
}else{
    errno(504, [
        'success' => false,
        'errors'  => $caller['errors']
    ]);
}


function request($url, $header, $options = []){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if(isset($options['method']))
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $options['method'] ?? 'GET');
    if(isset($options['data']))
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['data']));

    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function cache_load(){
    if(!CACHE_CF_RECORD_ID || !is_file(CACHE_FILE_NAME)) return false;
    $db = file_get_contents(CACHE_FILE_NAME);
    $data = json_decode($db, true);
    if(json_last_error() !== JSON_ERROR_NONE || !isset($data['status'])) return false;
    return $data['data'];
}

function cache_save($data){
    return file_put_contents(CACHE_FILE_NAME, json_encode([
        'status' => true,
        'data' => $data
    ]));
}

function errno($code, $data = []){
    http_response_code($code);
    echo json_encode($data);
    exit(1);
}
