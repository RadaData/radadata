<?php

namespace ShvetsGroup\Service;

use \Symfony\Component\DependencyInjection\ContainerAware;
use Illuminate\Database\Capsule\Manager as DB;
use ShvetsGroup\Model\Job;

class JobsManager extends ContainerAware
{

    /**
     * @var Proxy
     */
    private $proxy;

    /**
     * @param Proxy $proxy
     */
    public function __construct($proxy)
    {
        $this->proxy = $proxy;
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

        $real_workers_count = min($workers_count, $this->proxy->count());
        _log('Launching ' . $real_workers_count . ' workers for "' . ($group ? ($group . '/') : '' ). ($service ? $service . '->' : '') . $method . '" operations.', 'title');

        if ($real_workers_count == 1) {
            while ($job = $this->fetch($group, $service, $method)) {
                $job->execute($this->container);
            }
        } else {
            $child = 0;
            while (true) {
                $job = $this->fetch($group, $service, $method);

                if (!$job) {
                    if ($wait_if_no_jobs) {
                        sleep(1000);
                        continue;
                    } else {
                        return;
                    }
                }

                $child++;
                DB::connection()->disconnect();

                $pid = pcntl_fork();

                if ($pid == -1) {
                    throw new \Exception("Could not fork worker process.");
                }

                // Parent process.
                if ($pid) {
                    if ($child >= $real_workers_count) {
                        pcntl_wait($status);
                        $child--;
                    }
                } // Worker.
                else {
                    $job->execute($this->container);
                    exit;
                }
            }
        }
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
     * @return array
     */
    public function fetch($group = null, $service = null, $method = null)
    {
        $job = null;

        DB::connection()->transaction(function() use ($group, $service, $method, &$job) {
            $query = Job::whereNull('claimed')->orderBy('job_id');
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
     *
     * @return bool
     */
    public function add($service, $method, $parameters, $group)
    {
        Job::create(['service' => $service, 'method' => $method , 'parameters' => $parameters, 'group' => $group]);
    }

    /**
     * Delete all jobs of the specific group.
     *
     * @param $group
     */
    public function deleteAll($group)
    {
        Job::where('group', $group)->delete();
    }

    /**
     * Reset claimed but not executed jobs.
     */
    public function cleanup()
    {
        Job::whereNotNull('claimed')->update(['claimed' => null]);
    }

    /**
     * See how many jobs there are.
     */
    public function count()
    {
        return Job::count();
    }

}