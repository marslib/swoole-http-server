#!/bin/env php
<?php

use MarsLib\Scaffold\Common\Config;
use MarsLib\Server\Http\SwooleYaf;

require dirname(__FILE__) . '/../vendor/autoload.php';
error_reporting(E_ALL);
/**
 * 检查exec 函数是否启用
 */
if(!function_exists('exec')) {
    exit('exec function is disabled' . PHP_EOL);
}
/**
 * 检查命令 lsof 命令是否存在
 */
exec("whereis lsof", $out);
if($out[0] == 'lsof:') {
    exit('lsof is not found' . PHP_EOL);
}
define('DS', DIRECTORY_SEPARATOR);
define('MH_SRC_PATH', realpath(__DIR__ . DS . '..'));
define("SW_APP_ROOT", realpath(MH_SRC_PATH . DS . '..' . DS . '..' . DS . '..'));
$httpConf = [
    'tz' => 'Asia/Shanghai',//时区设定，数据统计时区非常重要
    'host' => '127.0.0.1',  //默认监听ip
    'port' => '9523',   //默认监听端口
    'app_env' => 'dev', //运行环境 dev|test|prod, 基于运行环境加载不同配置
    'ps_name' => 'swTask',  //默认swoole 进程名称
    'daemonize' => 0,   //是否守护进程 1=>守护进程| 0 => 非守护进程
    'worker_num' => 2,    //worker进程 cpu核数 1-4倍,一般选择和cpu核数一致
    'task_worker_num' => 2,    //task进程,根据实际情况配置
    'task_max_request' => 10000,    //当task进程处理请求超过此值则关闭task进程,保障进程无内存泄露
    'open_tcp_nodelay' => 1,    //关闭Nagle算法,提高HTTP服务器响应速度
    'plat' => 'yaf',
    'log_file' => MH_SRC_PATH . '/logs/log_file.log',
    'master_process_name' => 'swoole-http-master',
    'manager_process_name' => 'swoole-http-manager',
    'event_worker_process_name' => 'swoole-http-evnet-worker-%d'
];
/**
 * @var array swoole-http_server支持的进程管理命令
 */
$cmds = [
    'start',
    'stop',
    'restart',
    'status',
    'list',
    'reload',
];
/**
 * @var array 命令行参数，FIXME: getopt 函数的长参数 格式 requried:, optionnal::,novalue 三种格式，可选参数这个有问题
 */
$longopt = [
    'help',//显示帮助文档
    'daemon:',//以守护进程模式运行,不指定读取配置文件
    'host:',//监听主机ip, 0.0.0.0 表示所有ip
    'port:',//监听端口
];
$opts = getopt('', $longopt);
if(isset($opts['help']) || $argc < 2) {
    echo <<<HELP
用法：php swoole-http-server.php 选项[help|daemon|host|port]  命令[start|stop|restart|status|list]
管理swoole-http-server服务,确保系统 lsof 命令有效
如果不指定监听host或者port，使用配置参数

参数说明
    --help      显示本帮助说明
    --daemon    指定此参数，以守护进程模式运行,不指定则读取配置文件值
    --host      指定监听ip,例如 php swoole.php -h 127.0.0.1
    --port      指定监听端口port， 例如 php swoole.php --host 127.0.0.1 --port 9520
    
启动swoole-http-server 如果不指定 host和port，读取http-server中的配置文件
关闭swoole-http-server 必须指定port,没有指定host，关闭的监听端口是  *:port, 指定了host，关闭 host:port端口
重启swoole-http-server 必须指定端口
获取swoole-http-server 状态，必须指定port(不指定host默认127.0.0.1), tasking_num是正在处理的任务数量(0表示没有待处理任务)

HELP;
    exit;
}
/**
 * 参数检查
 */
foreach($opts as $k => $v) {
    if($k == 'host') {
        if(empty($v)) {
            exit("参数 --host 必须指定值\n");
        }
    }
    if($k == 'port') {
        if(empty($v)) {
            exit("参数--port 必须指定值\n");
        }
    }
}
//命令检查
$cmd = $argv[$argc - 1];
if(!in_array($cmd, $cmds)) {
    exit("输入命令有误 : {$cmd}, 请查看帮助文档\n");
}
function swTaskPort($port)
{
    $ret = [];
    $cmd = "lsof -i :{$port}|awk '$1 != \"COMMAND\"  {print $1, $2, $9}'";
    exec($cmd, $out);
    if($out) {
        foreach($out as $v) {
            $a = explode(' ', $v);
            list($ip, $p) = explode(':', $a[2]);
            $ret[$a[1]] = [
                'cmd' => $a[0],
                'ip' => $ip,
                'port' => $p,
            ];
        }
    }

    return $ret;
}

/**
 * @var string 监听主机ip, 0.0.0.0 表示监听所有本机ip, 如果命令行提供 ip 则覆盖配置项
 */
if(!empty($opts['host'])) {
    if(!filter_var($host, FILTER_VALIDATE_IP)) {
        exit("输入host有误:{$host}");
    }
    $httpConf['host'] = $opts['host'];
}
/**
 * @var int 监听端口
 */
if(!empty($opts['port'])) {
    $port = (int)$opts['port'];
    if($port <= 0) {
        exit("输入port有误:{$port}");
    }
    $httpConf['port'] = $port;
}
$conf = array_merge($opts, $httpConf);
//确定port之后则进程文件确定，可在conf中加入
$conf['master_pid_file'] = MH_SRC_PATH . DS . 'master_pid_file_' . $conf['port'] . '.pid';
$conf['manager_pid_file'] = MH_SRC_PATH . DS . 'manager_pid_file_' . $conf['port'] . '.pid';
/**
 * @var  bool swoole-httpServer运行模式，参数nodaemon 以非守护进程模式运行，否则以配置文件设置值为默认值
 */
if(isset($opts['daemon']) && $opts['daemon']) {
    $conf['daemonize'] = 1;
}
Config::set('server', $conf);
Config::add([
    'log' => [
        'level' => 'DEBUG',
        'dir' => './logs',
        'output' => true,
    ],
]);
define('ENV', strtoupper($conf['app_env'] ?? 'dev'));
function swTaskStart($conf)
{
    echo "正在启动 swoole-http-server 服务" . PHP_EOL;
    if(!is_writable(dirname($conf['master_pid_file']))) {
        exit("swoole-http-server-pid文件需要目录的写入权限:" . dirname($conf['master_pid_file']) . PHP_EOL);
    }
    if(file_exists($conf['master_pid_file'])) {
        $pid = explode("\n", file_get_contents($conf['master_pid_file']));
        $cmd = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
        exec($cmd, $out);
        if(!empty($out)) {
            exit("swoole-http-server pid文件 " . $conf['master_pid_file'] . " 存在，swoole-http-server 服务器已经启动，进程pid为:{$pid[0]}" . PHP_EOL);
        } else {
            echo "警告:swoole-http-server pid文件 " . $conf['master_pid_file'] . " 存在，可能swoole-http-server服务上次异常退出(非守护模式ctrl+c终止造成是最大可能)" . PHP_EOL;
            unlink($conf['master_pid_file']);
        }
    }
    $bind = swTaskPort($conf['port']);
    if($bind) {
        foreach($bind as $k => $v) {
            if($v['ip'] == '*' || $v['ip'] == $conf['host']) {
                exit("端口已经被占用 {$conf['host']}:{$conf['port']}, 占用端口进程ID {$k}" . PHP_EOL);
            }
        }
    }
    date_default_timezone_set($conf['tz']);
    $server = SwooleYaf::getInstance();
    $server->start();
    //确保服务器启动后swoole-http-server-pid文件必须生成
    /*if (!empty(portBind($port)) && !file_exists(SWOOLE_TASK_PID_PATH)) {
        exit("swoole-http-server pid文件生成失败( " . SWOOLE_TASK_PID_PATH . ") ,请手动关闭当前启动的swoole-http-server服务检查原因" . PHP_EOL);
    }*/
    exit("启动 swoole-http-server 服务成功" . PHP_EOL);
}

function swTaskStop($conf, $isRestart = false)
{
    echo "正在停止 swoole-http-server 服务" . PHP_EOL;
    if(!file_exists($conf['master_pid_file'])) {
        exit('swoole-http-server-pid文件:' . $conf['master_pid_file'] . '不存在' . PHP_EOL);
    }
    $pid = explode("\n", file_get_contents($conf['master_pid_file']));
    $bind = swTaskPort($conf['port']);
    if(empty($bind) || !isset($bind[$pid[0]])) {
        exit("指定端口占用进程不存在 port:{$conf['port']}, pid:{$pid[0]}" . PHP_EOL);
    }
    $cmd = "kill -9 {$pid[0]}";
    exec($cmd);
    do {
        $out = [];
        $c = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
        exec($c, $out);
        if(!$out) {
            break;
        }
    } while(true);
    //确保停止服务后swoole-http-server-pid文件被删除
    if(file_exists($conf['master_pid_file'])) {
        unlink($conf['master_pid_file']);
    }
    $msg = "执行命令 {$cmd} 成功，端口 {$conf['host']}:{$conf['port']} 进程结束" . PHP_EOL;
    if($isRestart) {
        echo $msg;
    } else {
        exit($msg);
    }
}

function swTaskStatus($conf)
{
    echo "swoole-http-server {$conf['host']}:{$conf['port']} 运行状态" . PHP_EOL;
    $cmd1 = "ps aux | grep -v grep | grep -E 'sw_http|COMMAND|swoole-http' |  sort -n -k 2";
    exec($cmd1, $out1);
    foreach($out1 as $v) {
        if(strpos($v, 'status') === false) {
            echo "$v" . PHP_EOL;
        }
    }
    echo PHP_EOL;
    $cmd = "curl -s '{$conf['host']}:{$conf['port']}?cmd=status'";
    exec($cmd, $out);
    if(empty($out)) {
        exit("{$conf['host']}:{$conf['port']} swoole-http-server服务不存在或者已经停止" . PHP_EOL);
    }
    foreach($out as $v) {
        $a = json_decode($v);
        foreach($a as $k1 => $v1) {
            echo "$k1:\t$v1" . PHP_EOL;
        }
    }
    exit();
}

function swReload($conf)
{
    echo "正在重启 swoole-http-server 服务" . PHP_EOL;
    if(!file_exists($conf['master_pid_file'])) {
        exit('swoole-http-server-pid文件:' . $conf['manager_pid_file'] . '不存在' . PHP_EOL);
    }
    $master_pid = explode("\n", file_get_contents($conf['master_pid_file']));
    $manager_pid = explode("\n", file_get_contents($conf['manager_pid_file']));
    $bind = swTaskPort($conf['port']);
    if(empty($bind) || !isset($bind[$master_pid[0]])) {
        exit("指定端口占用进程不存在 port:{$conf['port']}, pid:{$master_pid[0]}" . PHP_EOL);
    }
    $cmd = "kill -USR1 {$manager_pid[0]}";
    exec($cmd);
    echo "swoole-http-server 服务 重启成功" . PHP_EOL;
}

//WARN macOS 下因为不支持进程修改名称，此方法使用有问题
function swTaskList($conf)
{
    echo "本机运行的swoole-http-server服务进程" . PHP_EOL;
    $cmd = "ps aux|grep " . $conf['ps_name'] . "|grep -v grep|awk '{print $1, $2, $6, $8, $9, $11}'";
    exec($cmd, $out);
    if(empty($out)) {
        exit("没有发现正在运行的swoole-http-server服务" . PHP_EOL);
    }
    echo "USER PID RSS(kb) STAT START COMMAND" . PHP_EOL;
    foreach($out as $v) {
        echo $v . PHP_EOL;
    }
    exit();
}

//启动
if($cmd == 'start') {
    swTaskStart($conf);
}
//停止
if($cmd == 'stop') {
    swTaskStop($conf);
}
//重启
if($cmd == 'restart') {
    echo "重启swoole-http-server服务" . PHP_EOL;
    swTaskStop($conf, true);
    swTaskStart($conf);
}
//状态
if($cmd == 'status') {
    swTaskStatus($conf);
}
//列表 WARN macOS 下因为进程名称修改问题，此方法使用有问题
if($cmd == 'list') {
    swTaskList($conf);
}
// 重载
if($cmd == 'reload') {
    swReload($conf);
}


