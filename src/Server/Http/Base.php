<?php
namespace MarsLib\Server\Http;

use MarsLib\Scaffold\Common\Config;

class Base
{

    /**
     * Variable  defaultIp
     * 默认监听绑定IP
     * @var      string
     */
    protected $defaultIp;
    /**
     * Variable  defaultPort
     * 默认监听绑定端口
     * @var      int
     */
    protected $defaultPort;
    /**
     * Variable  serverConfig
     * @var
     */
    protected $serverConfig;
    /**
     * Variable  appConfigFile
     * @var
     */
    /**
     * Variable  serverObj
     * @var \Swoole\Http\Server
     */
    protected $serverObj;
    /**
     * Variable  instance
     * @static
     * @var      null
     */
    protected static $instance = null;
    protected        $startTime;
    protected $master_pid_file;
    protected $manager_pid_file;

    /**
     * Method  getInstance
     * @desc   获取对象
     * @static
     * @return mixed
     */
    public static function getInstance()
    {
        if(empty(self::$instance) || !(self::$instance instanceof Base)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function __construct()
    {
        $this->defaultIp = Config::get('server.host', '127.0.0.1');
        $this->defaultPort = Config::get('server.port', 8080);

        if(!Config::get('server.master_pid_file')) {
            Config::set('server.master_pid_file', MH_SRC_PATH . "/master_pid_file_{$this->defaultPort}.pid");
        }
        if(!Config::get('server.manager_pid_file')) {
            Config::set('server.manager_pid_file', MH_SRC_PATH . "/manager_pid_file_{$this->defaultPort}.pid");
        }
        $this->serverConfig = array_merge($this->serverConfig ?? [], Config::get('server', []));
    }

    /**
     * Method  setServerConfigIni
     * @desc   设置server_config
     *
     * @param $serverConfigIni
     *
     * @return void
     */
    public function setServerConfigIni($serverConfigIni)
    {
        if(!is_file($serverConfigIni)) {
            trigger_error('Server Config File Not Exist!', E_USER_ERROR);
        }
        $serverConfig = parse_ini_file($serverConfigIni, true);
        if(empty($serverConfig)) {
            trigger_error('Server Config Content Empty!', E_USER_ERROR);
        }
        $this->serverConfig = array_merge($this->serverConfig ?? [], Config::get('server', []));
    }

    /**
     * Method  start
     * @desc   启动server
     * @return void
     */
    public function start()
    {
        $ip = isset($this->serverConfig['server']['ip']) ? $this->serverConfig['server']['ip'] : $this->defaultIp;
        $port = isset($this->serverConfig['server']['port']) ? $this->serverConfig['server']['port'] : $this->defaultPort;
        $this->serverObj = new \swoole_http_server($ip, $port);
        $conf = $this->serverConfig ?: Config::get('server');
        $this->serverObj->set($conf);
        $this->setCallBack();
        $this->serverObj->start();
    }

    public function setCallBack()
    {
        //回调函数
        $call = [
            'start',
            'workerStart',
            'managerStart',
            'request',
            'task',
            'finish',
            'workerStop',
            'shutdown',
        ];
        //事件回调函数绑定
        foreach ($call as $v) {
            $m = 'on' . ucfirst($v);
            if (method_exists($this, $m)) {
                $this->serverObj->on($v, [$this, $m]);
            }
        }
    }

    function checkFilesChange($monitor_dir)
    {
        $server = $this->serverObj;
        // recursive traversal directory
        $dir_iterator = new \RecursiveDirectoryIterator($monitor_dir);
        $iterator = new \RecursiveIteratorIterator($dir_iterator);
        $is_change = false;
        foreach($iterator as $file) {
            // only check php files
            if(pathinfo($file, PATHINFO_EXTENSION) != 'php') {
                continue;
            }
            // check mtime
            if($this->startTime < $file->getMTime()) {
                $is_change = true;
                log_message('file_change ' . $file . ' ' . date('H:i:s', $this->startTime) . ': ' . date('H:i:s', $file->getMTime()));
            }
        }
        if($is_change) {
            $server->reload();
        }
    }

    /**
     * 修改swooleTask进程名称，如果是macOS 系统，则忽略(macOS不支持修改进程名称)
     *
     * @param $name 进程名称
     *
     * @return bool
     * @throws \Exception
     */
    protected function setProcessName($name)
    {
        $name .= '-' . $this->defaultPort;
        if(PHP_OS == 'Darwin') {
            return false;
        }
        if(function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } else {
            if(function_exists('swoole_set_process_name')) {
                swoole_set_process_name($name);
            } else {
                log_message(__METHOD__ . "failed,require cli_set_process_title|swoole_set_process_name", LOG_ERR);
            }
        }
    }

    /**
     * 热更新
     * @var $serverObj
     * @var $dir      string|array
     * @var $workerId int
     */
    protected function hotReload($dir, $workerId)
    {
        if($workerId == 0 && !is_prod()) {
            swoole_timer_tick(2000, function($timer_id) use ($dir)
            {
                $dir = is_array($dir) ? $dir : (array)$dir;
                foreach($dir as $path) {
                    $this->checkFilesChange($path);
                }
            });
        }
    }

    public function onStart(\swoole_http_server $serverObj) {
        $this->setProcessName(Config::get('server.master_process_name'));
        file_put_contents(Config::get('server.master_pid_file'), $serverObj->master_pid);
        return true;

    }

    public function onManagerStart(\swoole_http_server $serverObj) {
        $this->setProcessName(Config::get('server.manager_process_name'));
        file_put_contents(Config::get('server.manager_pid_file'), $serverObj->manager_pid);
        return true;
    }

    public function onWorkerStart(\swoole_http_server $serverObj, $workerId) {
        $this->startTime = time();
        $processName = sprintf(Config::get('server.event_worker_process_name'), $workerId);
        $this->setProcessName($processName);
    }

    public function onWorkerStop(\swoole_http_server $serverObj, $workerId) {
    }

    public function onRequest(\swoole_http_request $request, \swoole_http_response $response) {
        if(!is_prod() && isset($request->get['cmd']) && $request->get['cmd'] == 'status') {
            $res = $this->serverObj->stats();
            $res['start_time'] = date('Y-m-d H:i:s', $res['start_time']);
            $response->end(json_encode($res));

            return true;
        }
    }

    public function onTask($server, $taskId, $fromId, $request)
    {
    }

    public function onFinish($server, $taskId, $ret)
    {
    }
    /**
     * swoole-server master shutdown
     */
    public function onShutdown()
    {
        $master_pid = Config::get('server.master_pid_file');
        if(file_exists($master_pid)) {
            @unlink($master_pid);
        }

        $manager_pid = Config::get('server.manager_pid_file');
        if(file_exists($manager_pid)) {
            @unlink($manager_pid);
        }

        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server shutdown\n";
    }

    public function afterStart()
    {

    }
}