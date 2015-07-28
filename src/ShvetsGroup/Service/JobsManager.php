<?php

namespace ShvetsGroup\Service;

use \Symfony\Component\DependencyInjection\ContainerAware;
use Illuminate\Database\Capsule\Manager as DB;
use ShvetsGroup\Model\Job;
use ShvetsGroup\Service\Database as DBManager;
use ShvetsGroup\Service\Proxy\ProxyManager;

declare(ticks = 1);

class JobsManager extends ContainerAware
{

    /**
     * @var ProxyManager
     */
    private $proxyManager;

    private $currentJobs = [];
    private $signalQueue = [];

    /**
     * @param ProxyManager $proxyManager
     */
    public function __construct($proxyManager)
    {
        $this->proxyManager = $proxyManager;
        \pcntl_signal(SIGCHLD, [$this, "childSignalHandler"]);
    }

    /**
     * Fork multiple PHP processes, which will execute specific service methods, taken from the jobs pool.
     *
     * @param int    $workers_count Number of workers to launch. Can be lower if there are no available proxies.
     * @param string $group         Workers group name. Filter the jobs worker will take. Also useful to terminate
     *                              specific workers later.
     * @param string $service       Filter the service work workers will work on.
     * @param string $method        Filter the methods work workers will work on.
     *
     * @throws \Exception
     */
    public function launch($workers_count, $group = null, $service = null, $method = null, $wait_if_no_jobs = false)
    {
        $this->cleanup();

        _log('Launching ' . $this->realWorkersCount($workers_count) . ' workers for "' . ($group ? ($group . '/') : '') . ($service ? $service . '->' : '') . $method . '" operations.', 'title');

        if ($this->realWorkersCount($workers_count) == 1) {
            while ($job = $this->fetch($group, $service, $method)) {
                $job->execute($this->container);
            }
        } else {
            while (true) {
                if (!$this->realWorkersCount($workers_count)) {
                    _log('Can not create any workers. Exiting.' . "\n", 'red');
                    die();
                }
                if (count($this->currentJobs) >= $this->realWorkersCount($workers_count)) {
                    sleep(1000);
                    continue;
                }

                if (!$this->count()) {
                    if ($wait_if_no_jobs) {
                        sleep(1000);
                        continue;
                    } else {
                        return;
                    }
                }

                $pid = pcntl_fork();

                if ($pid == -1) {
                    throw new \Exception("Could not fork worker process.");
                }

                DBManager::disconnect();

                // Parent process.
                if ($pid) {
                    $this->currentJobs[$pid] = 1;

                    if (isset($this->signalQueue[$pid])){
                        $this->childSignalHandler(SIGCHLD, $pid, $this->signalQueue[$pid]);
                        unset($this->signalQueue[$pid]);
                    }
                } // Worker.
                else {
                    $job = $this->fetch($group, $service, $method);
                    $job->execute($this->container);
                    exit;
                }
            }
        }
    }

    public function realWorkersCount($workers_count)
    {
        return min($workers_count, $this->proxyManager->count());
    }

    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        if (!$pid) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        while ($pid > 0) {
            if ($pid && isset($this->currentJobs[$pid])) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode != 0) {
                    echo "$pid exited with status " . $exitCode . "\n";
                }
                unset($this->currentJobs[$pid]);
            } else {
                if ($pid) {
                    $this->signalQueue[$pid] = $status;
                }
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        return true;
    }

    /**
     * Alias to 'launch', which waits by default if there are no jobs.
     */
    public function launchAndWait($workers_count, $group = null, $service = null, $method = null)
    {
        $this->launch($workers_count, $group, $service, $method, true);
    }

    /**
     * Fetch a single job from the pool. The job stays in the pool until finished, but blocked from taking by other
     * workers unless a cleanup happen. This extremely useful to restart jobs if their worker has died in the process.
     *
     * @param null $group   Filter jobs by group.
     * @param null $service Filter jobs by service.
     * @param null $method  Filter jobs by method.
     *
     * @return Job
     */
    public function fetch($group = null, $service = null, $method = null)
    {
        $job = null;

        DB::transaction(function () use ($group, $service, $method, &$job) {
            $query = Job::where('claimed', 0)->where('finished', 0)->orderBy('priority', 'DESC')->orderBy('id')->lockForUpdate();
            if ($group) {
                $query->where('group', $group);
            }
            if ($service) {
                $query->where('service', $service);
            }
            if ($method) {
                $query->where('method', $method);
            }
            $job = $query->first();
            $job->update(['claimed' => time()]);
        });

        return $job;
    }

    /**
     * Add a new job to the pool.
     *
     * @param string|null $service    Service, which contain the method to execute. Pass the NULL if you want to
     *                                execute a function.
     * @param string      $method     Name of the method to execute.
     * @param array       $parameters List of parameters to pass to the method.
     * @param string      $group      Job group (could be used as a filter).
     * @param int         $priority   Job priority (bigger = more important).
     *
     * @return bool
     */
    public function add($service, $method, $parameters, $group, $priority = 0)
    {
        Job::updateOrCreate([
            'service' => $service,
            'method' => $method,
            'parameters' => $parameters,
            'group' => $group,
            'priority' => $priority
        ]);
    }

    /**
     * Delete all jobs of the specific group.
     *
     * @param $group
     */
    public function deleteAll($group = null)
    {
        if ($group) {
            Job::where('group', $group)->delete();
        } else {
            DB::table('jobs')->truncate();
        }
    }

    /**
     * Reset claimed but not executed jobs.
     */
    public function cleanup()
    {
        Job::where('claimed', '<>', 0)->update(['claimed' => 0]);
    }

    /**
     * See how many jobs there are.
     */
    public function count()
    {
        return Job::count();
    }

}