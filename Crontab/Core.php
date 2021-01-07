<?php
/**
 * Created by PhpStorm.
 * User: YY
 * Date: 2021/1/5
 * Time: 18:57
 */

namespace Crontab;


use Cron\CronExpression;

class Core
{
    public $config = [];
    public $path = "";
    public $task_file = "/tmp/task";
    public $master_pid_file = "/tmp/master_pid";
    public $log = "/tmp/crontab_log";
    public $error_log = "/tmp/crontab_error_log";

    public function __construct($arr)
    {
        ["path"=>$path,"params"=>$params]= $arr;
        if ($params[count($params) - 1] == "-d") {
            $this->daemon();
        }

        if ($params[count($params) - 1] == "stop") {
            $this->stopProcess();
        }

        //保存主进程的id,且判断是否已经存在pid了
        $this->savaMasterId();

        //管理进程
        $this->getIniContent($path,$params)->startManager();

        //文件监控进程
        $this->startMonitorFile();
    }


    public function savaMasterId(){

        if (file_exists($this->master_pid_file)) {
            die("Already running\n");
        }

        $master_pid = posix_getpid();
        file_put_contents($this->master_pid_file, $master_pid);
    }

    public function stopProcess(){
        $master_pid = file_get_contents($this->master_pid_file);
        exec("ps --ppid {$master_pid} | awk '/[0-9]/{print $1}' | xargs", $output, $status);
        if ($status == 0) {
            posix_kill($master_pid, SIGKILL);
            $childs = explode(' ', current($output));
            foreach ($childs as $id) {
                posix_kill($id, SIGKILL);
            }
        }

        while (true) {
            if (! posix_kill($master_pid, 0)) {
                @unlink('/tmp/master_pid');
                break;
            }
        }
        exit;
    }

    /**
     * 启动管理进程
     */
    public function startManager(){
        $config = $this->config;
        $manager = function () use ($config) {
            cli_set_process_title("manage_crontab");

            while (true) {
                $keys = [];
                foreach ($config as $key => &$value){
                    if(trim($value['current']) == "now"){
                        $value['current'] = date("Y-m-d H:i");
                    }
                    ["cron" => $cron, "func" => $func, "current" => $current, "nth" => $nth] = $value;
                    $cronClass = CronExpression::factory($cron);
                    $time = $cronClass->getNextRunDate($value["current"], $nth, true)->getTimestamp() - strtotime(date("Y-m-d H:i"));
                    if ($time === 0) {
                        $keys[] = $key;
                        $value['nth'] = $value['nth'] + 1;
                    }
                    sleep(1);
                }

                if(count($keys)>0){
                    //echo implode(',',$keys);
                    $this->TalkToMaster(implode(',',$keys));
                    posix_kill(posix_getppid(), SIGUSR2);
                }
            }
        };

        $this->fork($manager);
    }

    /**
     * 主进程
     */
    public function startMaster(){
        cli_set_process_title("master");
        //启动任务
        $sighandler = function ($signo) {
            $keys = explode(',', file_get_contents($this->task_file));
            $config = $this->config;
            foreach ($keys as $key) {
                $c = $config[$key];
                $func = $c["func"];
                $info = explode(",", $func);
                $process_name = explode('.', basename($info[2]));
                $output = [];
                exec("ps -ef | grep {$process_name[0]} | grep -v grep", $output, $status);
                if (count($output) == 1) {
                    echo "{$process_name[0]}.php运行中";
                    continue;
                }
                $this->fork($info);
            }

        };
        pcntl_signal(SIGUSR2, $sighandler, false);


        $check = function($signo){
            exec("ps -ef | grep manage_crontab | grep -v grep", $output, $status);
            $res = explode(" ",$output[0]);
            posix_kill(trim($res[0]), SIGUSR1);
        };
        pcntl_signal(SIGUSR1, $check,false);

        while (true) {
            \pcntl_signal_dispatch();
            if (($exit_id = pcntl_wait($status,WUNTRACED)) > 0) {
                exec("ps -ef | grep manage_crontab | grep -v grep", $output, $status);
                if(empty($output)){
                    $this->getIniContent()->startManager();
                }
            }
            \pcntl_signal_dispatch();
        }
    }

    /**
     * 启动文件监控进程监控
     */
    public function startMonitorFile(){
        $checkfile=function (){
            cli_set_process_title("manage_file");
            $watchFile_md5=md5_file($this->path);
            while (true){
                $getMd5=md5_file($this->path);
                if(strcmp($watchFile_md5,$getMd5)!==0){
                    posix_kill(posix_getppid(), SIGUSR1);
                    $watchFile_md5=$getMd5;
                }
                sleep(2);
            }
        };
        $this->fork($checkfile);
    }



    /**
     * 文件通信
     */

    public function TalkToMaster($string)
    {
        file_put_contents($this->task_file,$string);
    }

    public function getIniContent($path,$params){

        if (in_array("-path", $params)) {
            $path_key = array_search('-path', $params);
            $path = $params[$path_key + 1];
        }
        $this->path = $path;

        //todo 读取且检测配置文件
        $config = parse_ini_file($path, true);
        //print_r($config);exit;
        $this->config = $config;
        return $this;
    }

    /**
     * 创建子进程，然后退出父进程
     */
    private function daemon()
    {

        $pid = pcntl_fork();

        switch ($pid) {
            case -1:
                die('Create failed');
                break;
            case 0:
                // 1-1：Child

                // 2.在子进程中创建新会话
                if (($sessionid = posix_setsid()) <= 0) {//posix_setsid函数将子进程会话转为主会话；当返回值大于0表示执行成功
                    die("Set sid failed.\n");//失败就退出
                }

                // 3.改变工作目录（默认继承了父进程的当前工作目录）
                if (chdir('/') === false) {
                    die("Change dir failed.\n");//失败就退出
                }

                //4.重设文件创建掩码（默认继承了父进程的文件创建掩码）
                umask(0);//umask与chmod相反，chmod是授予权限，umask是拿走权限

                global $STDOUT, $STDERR;

                // 5.//关闭父进程打开的文件指针   这里父进程没有打开文件，就把把标准的输入输出和错误关闭
                //fclose(STDIN);
                fclose(STDOUT);
                fclose(STDERR);

                //a 写入方式打开，将文件指针指向文件末尾。如果文件不存在则尝试创建之。
                //把标准输出和错误 定位到 /dev/null
                $STDOUT = fopen($this->log, "a");
                $STDERR = fopen($this->error_log, "a");

                break;
            default:
                // Parent
                // 1-2退出父进程
                exit;
                break;
        }
    }

    /**
     * Fork一个子进程
     * @param $mix
     */
    private function fork($mix)
    {
        $pid = pcntl_fork();

        switch ($pid) {
            case -1:
                die('Create failed');
                break;
            case 0:
                if (is_callable($mix)) {
                    $mix();
                }elseif (is_array($mix)){
                    pcntl_exec($mix[0], [$mix[1], $mix[2]]);
                }
                break;
            default:

                break;
        }
    }

}