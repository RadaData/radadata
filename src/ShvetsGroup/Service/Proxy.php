<?php

namespace ShvetsGroup\Service;

use Aws\Ec2\Ec2Client;
use GuzzleHttp\Promise;

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
     * @var Ec2Client.
     */
    private $ec2Client;

    /**
     * @var string Absolute path to server keys.
     */
    private $serverKeysPath;

    /**
     * @var string Whether or not to use proxies.
     */
    private $useProxy;

    public function __construct($aws_config, $useProxy)
    {
        $this->ec2Client = new Ec2Client($aws_config);
        $this->serverKeysPath = BASE_PATH . $aws_config['server_keys_dir'];
        $this->useProxy = $useProxy;
    }

    public function useProxy()
    {
        return $this->useProxy;
    }

    public function killAll()
    {
        $this->dropConnections();
        $promises = [
            'instances'     => $this->terminateInstances(),
            'spot_requests' => $this->cancelSpotRequests()
        ];
        Promise\unwrap($promises);
    }

    private function getProxyInstances($key = null)
    {
        $result = $this->ec2Client->describeInstances([
            'Filters' => [
                ['Name' => 'instance-type', 'Values' => ['t1.micro']],
                ['Name' => 'instance-state-name', 'Values' => ['running']],
            ]
        ]);

        $path = 'Reservations[].Instances[]';
        if ($key) {
            $path .= '.' . $key;
        }
        $results = $result->getPath($path);

        return $results;
    }

    private function terminateInstances()
    {
        $instance_ids = $this->getProxyInstances('InstanceId');

        if (empty($instance_ids)) {
            return new Promise\FulfilledPromise(true);
        }

        return $this->ec2Client->terminateInstancesAsync(['InstanceIds' => $instance_ids]);
    }

    private function cancelSpotRequests()
    {
        $result = $this->ec2Client->describeSpotInstanceRequests([
            'Filters' => [
                ['Name' => 'state', 'Values' => ['open', 'active', 'closed']],
            ]
        ]);
        $spot_request_ids = $result->getPath('SpotInstanceRequests[].SpotInstanceRequestId');

        if (empty($spot_request_ids)) {
            return new Promise\FulfilledPromise(true);
        }

        return $this->ec2Client->cancelSpotInstanceRequestsAsync(['SpotInstanceRequestIds' => $spot_request_ids]);
    }

    /**
     * Sleep until 90% of required proxies are born or die after 10 minutes of waiting.
     */
    public function makeProxiesOrDie($count, $timeout = 10)
    {
        try {
            $instanceIds = $this->makeSpotRequests($count);

            $this->ec2Client->waitUntil('InstanceRunning', [
                'InstanceIds' => $instanceIds,
            ]);
            $this->ec2Client->waitUntil('InstanceStatusOk', [
                'InstanceIds' => $instanceIds,
            ]);
            $this->establishConnections();
        }
        catch (\Exception $e) {
            _log('makeProxiesOrDie: ' . $e->getMessage(), 'red');
            $this->proxy->killAll();
            die();
        }
    }

    private function makeSpotRequests($count)
    {
        try {
            $result = $this->ec2Client->requestSpotInstances([
                'InstanceCount'       => $count,
                'LaunchSpecification' => [
                    'ImageId' => 'ami-4a268b3d',
                    'InstanceType' => 't1.micro',
                    'KeyName' => 'AMI',
                    'Monitoring' => [
                        'Enabled' => false,
                    ],
                    'SecurityGroupIds' => ['sg-f5a19481'],
                ],
                'SpotPrice' => '0.01'
            ]);
            $spotRequestIds = $result->getPath('SpotInstanceRequests[].SpotInstanceRequestId');

            $this->ec2Client->waitUntil('SpotInstanceRequestFulfilled', [
                'SpotInstanceRequestIds' => $spotRequestIds,
            ]);

            $result = $this->ec2Client->describeSpotInstanceRequests([
                'SpotInstanceRequestIds' => $spotRequestIds
            ]);
            $instanceIds = $result->getPath('SpotInstanceRequests[].InstanceId');

            return $instanceIds;
        }
        catch (\Exception $e) {
            _log('makeSpotRequests: ' . $e->getMessage(), 'red');
            $this->proxy->killAll();
            die();
        }
    }

    /**
     * Get a proxy address from the pool. If proxy config is disabled, 'localhost' will be returned.
     *
     * @return string Proxy string (for example '127.0.0.1:2345').
     */
    public function getProxy()
    {
        if (!$this->useProxy()) {
            return 'localhost';
        }

        try {
            $this->establishConnections();

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
        if (!$this->useProxy()) {
            return 1;
        }

        // Init proxies if needed.
        $this->establishConnections();

        return count($this->proxies);
    }

    /**
     * Return active proxy to the pool.
     */
    public function releaseProxy()
    {
        $this->proxy = null;
    }

    /**
     * Kill old proxy connections, get list of live EC2 servers (t1.micro in eu-west-1), save their addresses in DB and
     * establish ssh tunnels to these servers by forking current script. If the main php script will be killed, all
     * connections will die as well.
     *
     * @throws \Exception
     */
    private function establishConnections()
    {
        static $initialized;
        if ($initialized) {
            return;
        }

        $this->dropConnections();

        $proxy_port = 7999; // Starting proxy port.

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
                exec('ssh -o UserKnownHostsFile=/dev/null -o "StrictHostKeyChecking no" -i ' . $this->serverKeysPath . '/AMI.pem -L ' . $proxy_port . ':localhost:8888 -N ubuntu@' . $ip);
                die();
            }
        }
        $initialized = true;
    }

    /**
     * Drop connections with proxy servers.
     */
    private function dropConnections()
    {
        exec('pkill -f "ssh -o UserKnownHostsFile"');
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
        $ips = $this->getProxyInstances('PublicIpAddress');
        if (!count($ips)) {
            throw new \Exception('There are no available IP addresses of proxies.');
        }

        return $ips;
    }

    /**
     * Save proxy address to the database pool.
     */
    private function saveProxy($proxy_port)
    {
        $proxy = '127.0.0.1:' . $proxy_port;
        $this->proxies[$proxy] = 0;
    }

    /**
     * Select least used proxy from the pool.
     *
     * @return string
     * @throws \Exception
     */
    private function selectProxy()
    {
        if (empty($this->proxies)) {
            throw new \Exception('No available proxy.');
        }

        $this->proxies = sort($this->proxies);
        reset($this->proxies);
        $this->proxy = key($this->proxies);
        $this->proxies[$this->proxy] = time();

        return $this->proxy;
    }
}