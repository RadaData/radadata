<?php

namespace ShvetsGroup\Service;

use \Symfony\Component\DependencyInjection\ContainerAware;

class Jobs extends ContainerAware
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
    public function launch($workers_count, $group, $service = null, $method = null)
    {
        $real_workers_count = min($workers_count, $this->proxy->count());
        _log('Launching ' . $real_workers_count . ' workers for "' . $group . '/' . ($service ? $service . '->' : '') . $method . '" operations.', 'title');

        if ($real_workers_count == 1) {
            while ($job = $this->fetch($group, $service, $method)) {
                $this->execute($job);
            }
        } else {
            $child = 0;
            while ($job = $this->fetch($group, $service, $method)) {
                $child++;
                close_db('db');
                close_db('misc');

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
                    $this->execute($job);
                    $this->proxy->releaseProxy();
                    exit;
                }
            }
        }
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
        $db = db('db');
        $db->beginTransaction();
        $sql = "SELECT * FROM jobs WHERE claimed IS NULL" . ($group ? " AND `group` = :group" : '') . ($service ? " AND `service` = :service" : '') . ($method ? " AND `method` = :method" : '') . " ORDER BY id LIMIT 1 FOR UPDATE";
        $query = $db->prepare($sql);
        if ($group) {
            $query->bindParam(':group', $group);
        }
        if ($service) {
            $query->bindParam(':service', $service);
        }
        if ($method) {
            $query->bindParam(':method', $method);
        }
        $query->execute();
        $row = $query->fetch();
        $db->prepare("UPDATE jobs SET claimed = :claimed WHERE id = :id;")->execute([
            ':claimed' => time(),
            ':id'      => $row['id']
        ]);
        $db->commit();

        if ($row) {
            $job = [
                'id'         => $row['id'],
                'service'    => $row['service'],
                'method'     => $row['method'],
                'parameters' => unserialize($row['parameters']),
                'group'      => $row['group'],
            ];

            return $job;
        }
    }

    /**
     * Execute a job.
     *
     * @param $job
     */
    private function execute($job)
    {
        if ($job['service']) {
            $object = $this->container->get($job['service']);
            $func = [$object, $job['method']];
        } else {
            $func = $job['method'];
        }
        call_user_func_array($func, $job['parameters']);

        db('db')->prepare("DELETE FROM jobs WHERE id = :id")->execute([':id' => $job['id']]);
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
        $serialized_parameters = serialize($parameters);

        $stmt = db('db')->prepare("INSERT INTO jobs (service, method, parameters, `group`) VALUES (:service, :method, :parameters, :group);");
        $stmt->bindParam(':service', $service);
        $stmt->bindParam(':method', $method);
        $stmt->bindParam(':parameters', $serialized_parameters);
        $stmt->bindParam(':group', $group);

        return $stmt->execute();
    }

    /**
     * Delete all jobs of the specific group.
     *
     * @param $group
     */
    public function deleteAll($group)
    {
        db('db')->exec("DELETE FROM jobs WHERE `group` = '" . $group . "'");
    }

    /**
     * Reset claimed but not executed jobs.
     */
    public function cleanup()
    {
        db('db')->prepare("UPDATE jobs SET claimed = NULL WHERE claimed IS NOT NULL")->execute([]);
    }
}