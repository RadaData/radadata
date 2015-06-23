<?php

namespace ShvetsGroup\Service;

class Proxy
{

    /**
     * @var string Current proxy.
     */
    private $proxy;

    /**
     * @var array List of available proxies.
     */
    private $proxies = [];

    /**
     * Get a proxy address from the pool. If proxy config is disabled, 'localhost' will be returned.
     *
     * @return string Proxy string (for example '127.0.0.1:2345').
     */
    public function getProxy()
    {
        if (!variable_get('use_proxy')) {
            return 'localhost';
        }

        try {
            $this->createProxies();
            return $this->selectProxy();
        } catch (\Exception $e) {
            _log($e->getMessage(), 'red');
            die();
        }
    }

    /**
     * Return number of all available proxies.
     *
     * @return int
     */
    public function count()
    {
        if (!variable_get('use_proxy')) {
            return 1;
        }

        // Init proxies if needed.
        $this->createProxies();

        return count($this->proxies);
    }

    /**
     * Return active proxy to the pool.
     */
    public function releaseProxy()
    {
        db('misc')->prepare("UPDATE proxies SET last_used = :time WHERE proxy = :proxy;")->execute([
            ':time'  => 0,
            ':proxy' => $this->proxy
        ]);
        $this->proxy = null;
    }

    /**
     * Kill old proxy connections, get list of live EC2 servers (t1.micro in eu-west-1), save their addresses in DB and
     * establish ssh tunnels to these servers by forking current script. If the main php script will be killed, all
     * connections will die as well.
     *
     * @throws \Exception
     */
    private function createProxies()
    {
        static $initialized;
        if ($initialized) {
            return;
        }

        $proxy_port = 7999; // Starting proxy port.

        exec('pkill -f "ssh -o UserKnownHostsFile"');
        db('misc')->exec('DELETE FROM proxies');

        $ips = $this->describeAvailableEC2Instances();

        foreach ($ips as $ip) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new \Exception("Could not fork proxy process.");
            }

            // This will be executed by main script.
            if ($pid) {
                $this->saveProxy($proxy_port);
                $proxy_port--;
                continue;
            } // This will be executed by forks.
            else {
                exec('ssh -o UserKnownHostsFile=/dev/null -o "StrictHostKeyChecking no" -i servers/AMI.pem -L ' . $proxy_port . ':localhost:8888 -N ubuntu@' . $ip);
                die();
            }
        }
        $initialized = true;
    }

    /**
     * Get the list of live Amazon servers, which server as proxies. Micro servers (t1.micro) are used, since their only
     * job is to provide ssh tunnel.
     *
     * @return array
     * @throws \Exception
     */
    private function describeAvailableEC2Instances()
    {
        $ips = [];
        $ec2Client = \Aws\Ec2\Ec2Client::factory(aws());
        $result = $ec2Client->DescribeInstances([
            'Filters' => [
                ['Name' => 'instance-type', 'Values' => ['t1.micro']],
            ]
        ]);
        $reservations = $result['Reservations'];
        foreach ($reservations as $reservation) {
            $instances = $reservation['Instances'];
            foreach ($instances as $instance) {
                if ($instance['State']['Name'] == 'running' && isset($instance['PublicIpAddress'])) {
                    $ips[] = $instance['PublicIpAddress'];
                }
            }
        }

        if (!count($ips)) {
            throw new \Exception('Error: There is no t1.micro instances for proxies in eu-west-1 region.');
        } else {
            _log(count($ips) . ' proxy servers found', 'green');
        }

        return $ips;
    }

    /**
     * Save proxy address to the database pool.
     */
    private function saveProxy($proxy_port)
    {
        $proxy = '127.0.0.1:' . $proxy_port;
        $this->proxies[] = $proxy;

        db('misc')->prepare('INSERT INTO proxies (proxy, last_used) VALUES (:proxy, 0)')
            ->execute([':proxy' => $proxy]);
    }

    /**
     * Select least used proxy from the pool.
     *
     * @return string
     * @throws \Exception
     */
    private function selectProxy()
    {
        $db = db('misc');
        $db->beginTransaction();
        $sql = "SELECT proxy FROM proxies ORDER BY last_used ASC LIMIT 1";
        $query = $db->prepare($sql);
        $query->execute();
        $proxy = $query->fetchColumn();
        $db->prepare("UPDATE proxies SET last_used = :time WHERE proxy = :proxy;")->execute([
            ':time'  => time(),
            ':proxy' => $proxy
        ]);
        $db->commit();

        if (!$proxy) {
            throw new \Exception('No available proxy.');
        }

        $this->proxy = $proxy;

        return $proxy;
    }
}