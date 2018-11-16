<?php
/**
 * Created by PhpStorm.
 * User: ifehrim@gmail.com
 * Date: 10/24/2018
 * Time: 12:50
 */

namespace Frame\Core;

declare(ticks=1);

class Forker
{
    CONST VERSION = '1.0.1';
    CONST STATUS_STARTING = 1;
    CONST STATUS_RUNNING = 2;
    CONST STATUS_SHUTDOWN = 4;
    CONST PT = 'A::Forker-';

    public $id = 0;
    public $name = 'none';
    public $count = 1;
    public $status = 'ok';

    public static $proccess_title = 'custom';
    public static $daemonize = false;

    public static $pidFile = '';
    public static $logFile = '';
    public static $statFile = '';

    public static $calls = [];

    public static $_masterPid = 0;
    /**
     * @var self[]
     */
    public static $_workers = [];
    public static $_pidMap = [];

    public static $_status = self::STATUS_STARTING;


    public static $_startFile = '';


    protected static $stat = ['s' => 0];

    protected static $_outputDecorated = true;
    protected $workerId;

    /**
     * Construct.
     * @param array $arr
     */
    public function __construct($arr = [])
    {
        $_path = __DIR__ . "/../";
        @list($title, $count, $path) = $arr;
        if (!empty($title)) self::$proccess_title = $title;
        if (!empty($count)) $this->count = $count;
        if (empty($path)) $path = $_path;


        if (php_sapi_name() != "cli") {
            static::ext("only run in command line mode");
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            static::ext('only run a linux system');
        }
        $this->workerId = spl_object_hash($this);
        static::$_workers[$this->workerId] = $this;
        static::$_pidMap[$this->workerId] = [];

        $backtrace = debug_backtrace();
        static::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        if(!is_dir($path)){
            exec('mkdir -p '.$path);
        }

        $unique_prefix = str_replace('/', '_', static::$_startFile);
        if (empty(static::$pidFile)) {
            static::$pidFile = $path . "$unique_prefix.pid";
        }
        if (!is_file(static::$pidFile)) {
            touch(static::$pidFile);
            chmod(static::$pidFile, 0622);
        }
        if (empty(static::$logFile)) {
            static::$logFile = $path . "$unique_prefix.log";
        }
        if (!is_file(static::$logFile)) {
            touch(static::$logFile);
            chmod(static::$logFile, 0622);
        }
        if (empty(static::$statFile)) {
            static::$statFile = $path . "$unique_prefix.status";
        }
        if (!is_file(static::$statFile)) {
            touch(static::$statFile);
            chmod(static::$statFile, 0622);
        }
        // State.
        static::$_status = static::STATUS_STARTING;

        // For statistics.
        static::$stat['s'] = time();
    }

    public static function reg($arr = [])
    {
        return new static($arr);
    }


    public static function off($func, ...$params)
    {
        $res = null;
        if (isset(self::$calls[$func])) {
            try {
                $res = call_user_func_array(self::$calls[$func], $params);
            } catch (\Exception $e) {
                $tag = $func;
                $func = "error";
                if (isset(self::$calls[$func])) {
                    call_user_func(self::$calls[$func], $tag, $e, $params);
                } else {
                    throw $e;
                }
            }
        }
        return $res;
    }

    public static function on($func, $call)
    {
        self::$calls[$func] = $call;
    }


    public static function run($call = null)
    {
        static::command();
        static::masters();
        static::signal();
        static::display();
        static::workers();
        static::$_status = static::STATUS_RUNNING;
        if (is_callable($call) && !empty($call)) static::on('loop', $call);
        static::off('start');
        while (true) {
            static::off('loop');
            static::signal('loop');
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            static::signal('loop');
            if ($pid > 0) {
                foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        if ($status !== 0) {
                            static::log("[$pid] exit with status $status");
                        }
                        unset(static::$_pidMap[$worker_id][$pid]);
                        break;
                    }
                }
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::workers();
                }
            }
            if (static::$_status === static::STATUS_SHUTDOWN && !static::pids()) {
                @unlink(static::$pidFile);
                exit(0);
            }
        }
        static::off('stop');
    }

    public static function display()
    {
        $i = 100;
        $title = '<w> ' . self::PT . self::$proccess_title . ' </w>';
        $body = [];
        $body[] = '<n>' . str_pad($title, $i, '-', 2) . '</n>';
        $body[] = self::PT . self::$proccess_title . 'version:' . self::VERSION . '     PHP version:' . PHP_VERSION;
        $body[] = str_pad($title, $i, '-', 2);
        $body[] = "Start success.";
        if (!static::$daemonize) {
            $body[] = "Press Ctrl+C to stop.";
        }
        static::prt(implode(PHP_EOL, $body) . PHP_EOL);
        usleep(10000);
        static::prt("\n");
    }

    public static function masters()
    {
        if (static::$daemonize) {
            umask(0);
            $pid = pcntl_fork();
            if (-1 === $pid) {
                static::ext('fork fail');
            } elseif ($pid > 0) {
                exit(0);
            }
            if (-1 === posix_setsid()) {
                static::ext("setsid fail");
            }
            $pid = pcntl_fork();
            if (-1 === $pid) {
                static::ext("fork fail");
            } elseif (0 !== $pid) {
                exit(0);
            }
        }
        static::$_masterPid = posix_getpid();
        if (false === file_put_contents(static::$pidFile, static::$_masterPid)) {
            static::ext('can not save pid to ' . static::$pidFile);
        }

    }

    public static function workers()
    {
        foreach (static::$_workers as $worker) {
            while (count(static::$_pidMap[$worker->workerId]) < $worker->count) {
                $worker->id = count(static::$_pidMap[$worker->workerId]);
                $pid = pcntl_fork();
                if ($pid > 0) {
                    @cli_set_process_title(self::PT . self::$proccess_title . '-master');
                    static::$_pidMap[$worker->workerId][$pid] = $pid;
                } elseif (0 === $pid) {
                    @cli_set_process_title(self::PT . self::$proccess_title . '-child');
                    static::$_pidMap = [];
                    static::$_status = static::STATUS_RUNNING;
                    static::signal();
                    static::off('fork', $worker->id);
                    static::ext('event-loop exited', 250);
                } else {
                    static::log('fork fail');
                }
            }
        }
    }

    public static function signal($signal = 'install')
    {
        switch ($signal) {
            case 'loop':
                pcntl_signal_dispatch();
                break;
            case 'install':
                pcntl_signal(SIGINT, [self::class, 'signal'], false);
                pcntl_signal(SIGTERM, [self::class, 'signal'], false);
                pcntl_signal(SIGIO, [self::class, 'signal'], false);
                pcntl_signal(SIGPIPE, SIG_IGN, false);
                break;
            case SIGINT:
            case SIGTERM:
                static::$_status = static::STATUS_SHUTDOWN;
                if (static::$_masterPid === posix_getpid()) {
                    $worker_pid_array = static::pids();
                    static::log("stopping... ");
                    foreach ($worker_pid_array as $worker_pid) {
                        if ($worker_pid != static::$_masterPid) {
                            posix_kill($worker_pid, $signal);
                        }
                    }
                    @unlink(static::$statFile);
                    break;
                }
                exit(0);
                break;
            case SIGIO:
                static::statistics(false);
                break;
        }
    }

    public static function prt($msg, $decorated = false)
    {
        $stream = STDOUT;
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$_outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = str_replace(['<n>', '<w>', '<g>'], array($line, $white, $green), $msg);
            $msg = str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        } elseif (!static::$_outputDecorated) {
            return false;
        }
        fwrite($stream, $msg);
        fflush($stream);
        return true;
    }

    public static function log($msg)
    {
        $msg = self::PT . self::$proccess_title . ' ' . $msg . "\n";
        if (!static::$daemonize) {
            static::prt($msg);
        }
        $msg = 'pid:' . posix_getpid() . ' ' . $msg;
        static::off('log', $msg);
    }

    public static function ext($msg, $status = 0)
    {
        static::log($msg);
        exit($status);
    }

    public static function pids()
    {
        $pid_array = array();
        foreach (static::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    public static function statistics($is = true)
    {
        if ($is) return @file_get_contents(static::$statFile) . "\n";

        if (static::$_masterPid === posix_getpid()) {
            $pids = static::pids();
            $st = static::$stat['s'];
            $body = [];
            $body[] = "----------------------------------------------" . static::PT . static::$proccess_title . " STATUS----------------------------------------------------";
            $body[] = static::PT . static::$proccess_title . ' version:' . static::VERSION . "    PHP version:" . PHP_VERSION;
            $body[] = 'start time:' . date('Y-m-d H:i:s', $st) . ' run     '
                . floor((time() - $st) / (24 * 60 * 60)) . ' days   '
                . floor(((time() - $st) % (24 * 60 * 60)) / (60 * 60)) . ' hours    '
                . floor(((time() - $st) % (24 * 60 * 60)) / (60 * 60 * 60)) . ' minute    '
                . floor(time() - $st) . ' second    ';
            $body[] = 'load average: ' . implode(", ", sys_getloadavg());
            $body[] = count(static::$_pidMap) . ' workers       ' . count($pids) . " processes";
            $body[] = "----------------------------------------------PROCESS STATUS---------------------------------------------------";
            $body[] = "pid\tmemory  "
                . str_pad('worker_name', 12) . " "
                . str_pad('timers', 8) . " "
                . str_pad('status', 13) . " "
                . "\n";
            file_put_contents(static::$statFile, implode("\n", $body));
            chmod(static::$statFile, 0722);
            foreach ($pids as $worker_pid) {
                posix_kill($worker_pid, SIGIO);
            }
        } else {

            reset(static::$_workers);
            $worker = current(static::$_workers);
            $mem = round(memory_get_usage(true) / (1024 * 1024), 2);
            $str = posix_getpid() . "\t"
                . str_pad($mem . "M", 7) . " "
                . str_pad($worker->name, 12) . " "
                . str_pad(0, 11) . " "
                . str_pad($worker->status, 13) . "\n";
            $stat = static::off('stat');
            file_put_contents(static::$statFile, $str . $stat . "\n", FILE_APPEND);
        }
        return '';
    }

    public static function command()
    {
        global $argv;
        $available_commands = array(
            'start',
            'stop',
            'restart',
            'status',
        );

        if (!isset($argv[1]) || !in_array($argv[1], $available_commands)) {
            if (isset($argv[1])) {
                static::prt('Unknown command: ' . $argv[1] . "\n");
            }
            static::ext("only use these: " . implode(',', $available_commands) . "\n");
        }
        // Get command.
        $command = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Get master process PID.
        $master_pid = is_file(static::$pidFile) ? file_get_contents(static::$pidFile) : 0;
        $master_is_alive = $master_pid && posix_kill($master_pid, 0) && posix_getpid() != $master_pid;

        static::log("$command $command2");


        $stop_func = function () use ($master_is_alive, $command2, $command, $master_pid) {
            if ($command2 === '-g') {
                $sig = SIGTERM;
                static::log("is gracefully stopping ...");
            } else {
                $sig = SIGINT;
                static::log("is stopping ...");
            }
            posix_kill($master_pid, $sig);
            $timeout = 5;
            $start_time = time();
            // Check master process is still alive?
            while (true) {
                $master_is_alive = posix_kill($master_pid, 0);
                if ($master_is_alive) {
                    if ($sig == SIGINT && time() - $start_time >= $timeout) {
                        static::ext("stop fail");
                    }
                    usleep(10000);
                    continue;
                }
                static::prt("stop success");
                if ($command === 'stop') exit(0);
                break;
            }
        };

        self::off("_{$command}",$argv);

        // execute command.
        switch ($command) {
            case 'start':
                if ($command2 === '-d') static::$daemonize = true;
                $master_is_alive && static::ext("already running");
                break;
            case 'stop':
                !$master_is_alive && static::ext("not run");
                $stop_func();
                break;
            case 'status':
                !$master_is_alive && static::ext("not run");
                while (true) {
                    posix_kill($master_pid, SIGIO);
                    sleep(1);
                    if ($command2 === '-d') {
                        static::prt("\33[H\33[2J\33(B\33[m", true);
                    }
                    static::prt(static::statistics());
                    if ($command2 !== '-d') {
                        static::ext("stop status");
                    }
                    static::prt("\nPress Ctrl+C to quit.\n\n");
                }
                exit(0);
                break;
            case 'restart':
                !$master_is_alive && static::ext("not run");
                $stop_func();
                if ($command2 === '-d') static::$daemonize = true;
                break;
        }
    }


}