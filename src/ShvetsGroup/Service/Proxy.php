<?php

namespace ShvetsGroup\Service;

use Aws\Ec2\Ec2Client;
use GuzzleHttp\Promise;
use Illuminate\Database\Capsule\Manager as DB;

class Proxy
{

    /**
     * @var object Current proxy.
     */
    public $proxy;

    /**
     * @var Ec2Client.
     */
    private $ec2Client;

    /**
     * @var array AWS configuration.
     */
    private $aws_config;

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
        $this->aws_config = $aws_config;
        $this->ec2Client = new Ec2Client($aws_config);
        $this->serverKeysPath = BASE_PATH . $aws_config['server_keys_dir'];
        $this->useProxy = $useProxy;
    }

    public function useProxy($forceUseProxy = null)
    {
        if ($forceUseProxy) {
            $this->useProxy = true;
        }
        return $this->useProxy;
    }

    public function killAll()
    {
        $this->dropConnections();
        $this->terminateInstances();
        $this->cancelSpotRequests();

        _log('Old proxies have been terminated.', 'green');
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

        if (!$instance_ids) {
            return new Promise\FulfilledPromise(true);
        }

        _log('Terminating ' . count($instance_ids) . ' instances.');
        $this->ec2Client->terminateInstances(['InstanceIds' => $instance_ids]);
        $this->waitUntil('InstanceTerminated', ['InstanceIds' => $instance_ids]);
    }

    private function cancelSpotRequests()
    {
        $result = $this->ec2Client->describeSpotInstanceRequests([
            'Filters' => [
                ['Name' => 'state', 'Values' => ['open', 'active', 'closed']],
            ]
        ]);
        $spot_request_ids = $result->getPath('SpotInstanceRequests[].SpotInstanceRequestId');

        if (!$spot_request_ids) {
            return new Promise\FulfilledPromise(true);
        }

        _log('Canceling ' . count($spot_request_ids) . ' spot requests.');
        $this->ec2Client->cancelSpotInstanceRequests(['SpotInstanceRequestIds' => $spot_request_ids]);

        $this->waitUntil('SpotInstanceRequestCanceled', ['SpotInstanceRequestIds' => $spot_request_ids]);
    }

    /**
     * Sleep until 90% of required proxies are born or die after 10 minutes of waiting.
     */
    public function makeProxiesOrDie($count, $timeout = 10)
    {
        try {
            $real_proxy_count = count($this->getProxyInstances());
            $proxy_to_create = $count - $real_proxy_count;

            if ($real_proxy_count > 0) {
                if ($proxy_to_create <= 0) {
                    _log('There are ' . $real_proxy_count . ' existing proxies, no need to create new.', 'green');
                    $this->establishConnections($count);
                    return;
                }
                else {
                    _log('There are ' . $real_proxy_count . ' existing proxies, I am going to create ' . $proxy_to_create . ' more.', 'green');

                }
            }

            $instanceIds = $this->makeSpotRequests($proxy_to_create);

            _log('Waiting for ' . $proxy_to_create . ' instances to launch.');
            $this->waitUntil('InstanceRunning', [
                'InstanceIds' => $instanceIds,
            ]);
            $this->waitUntil('InstanceStatusOk', [
                'InstanceIds' => $instanceIds,
            ]);
            _log('' . $proxy_to_create . ' new proxy instances have been launched.', 'green');

            $this->establishConnections($count);

            _log('' . $count . ' proxy instances are ready.', 'green');
        } catch (\Exception $e) {
            _log('makeProxiesOrDie: ' . $e->getMessage(), 'red');
            $this->killAll();
            die();
        }
    }

    private function makeSpotRequests($count)
    {
        try {
            _log('Requesting ' . $count . ' spot instances.');
            $result = $this->ec2Client->requestSpotInstances([
                'InstanceCount'       => $count,
                'LaunchSpecification' => [
                    'ImageId'          => $this->aws_config['ImageId'],
                    'InstanceType'     => $this->aws_config['InstanceType'],
                    'KeyName'          => $this->aws_config['KeyName'],
                    'Monitoring'       => [
                        'Enabled' => false,
                    ],
                    'SecurityGroupIds' => [$this->aws_config['SecurityGroupIds']],
                ],
                'SpotPrice'           => $this->aws_config['SpotPrice']
            ]);
            $spot_request_ids = $result->getPath('SpotInstanceRequests[].SpotInstanceRequestId');

            $this->waitUntil('SpotInstanceRequestFulfilled', [
                'SpotInstanceRequestIds' => $spot_request_ids,
            ]);

            $result = $this->ec2Client->describeSpotInstanceRequests([
                'SpotInstanceRequestIds' => $spot_request_ids
            ]);
            $instanceIds = $result->getPath('SpotInstanceRequests[].InstanceId');

            return $instanceIds;
        } catch (\Exception $e) {
            _log('makeSpotRequests: ' . $e->getMessage(), 'red');
            $this->killAll();
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

            if (!$this->proxy) {
                return $this->selectProxy();
            }
            else {
                return $this->proxy->address;
            }
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

        return DB::table('proxy')->count();
    }

    /**
     * Return active proxy to the pool.
     */
    public function releaseProxy()
    {
        _log(getmypid() . '::' . 'Proxy released: ' . $this->proxy->ip);
        DB::table('proxy')->where('address', $this->proxy->address)->update(['in_use' => 0, 'last_used' => round(microtime(true) * 100)]);
        $this->proxy = null;
    }

    /**
     * Kill old proxy connections, get list of live EC2 servers (t1.micro in eu-west-1), save their addresses in DB and
     * establish ssh tunnels to these servers by forking current script. If the main php script will be killed, all
     * connections will die as well.
     *
     * @param null $count
     *
     * @throws \Exception
     */
    private function establishConnections($count = null)
    {
        static $initialized;
        if ($initialized) {
            return;
        }

        $this->dropConnections();

        $proxy_port = 7999; // Starting proxy port.

        $ips = $this->describeAvailableEC2Instances();

        _log('Connecting to ' . ($count != null ? $count : count($ips)) . ' proxy instances.');
        $i = 0;
        foreach ($ips as $ip) {
            if ($count != null && $i == $count) {
                break;
            }
            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new \Exception("Could not fork proxy process.");
            }

            // This will be executed by main script.
            if ($pid) {
                $this->saveProxy($ip, $proxy_port);
                $proxy_port--;
                $i++;
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
        _log('Dropping old connections.');
        exec('pkill -f "ssh -o UserKnownHostsFile"');
        DB::table('proxy')->truncate();
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
    private function saveProxy($ip, $proxy_port)
    {
        $proxy = ['address' => '127.0.0.1:' . $proxy_port, 'ip' => $ip, 'last_used' => 0];
        DB::table('proxy')->insert($proxy);
    }

    /**
     * Select least used proxy from the pool.
     *
     * @return string
     * @throws \Exception
     */
    private function selectProxy()
    {
        if (!$this->count()) {
            throw new \Exception('No available proxy.');
        }

        $this->proxy = null;
        $p = $this;
        DB::transaction(function() use ($p) {
            $p->proxy = DB::table('proxy')->where('in_use', 0)->orderBy('last_used')->first();
            if (!$p->proxy) {
                throw new \Exception('Proxy can not be selected.');
            }
            DB::table('proxy')->where('address', $p->proxy->address)->update(['in_use' => 1, 'last_used' => round(microtime(true) * 100)]);
        });
        _log(getmypid() . '::' . 'Proxy claimed: ' . $this->proxy->ip);

        return $this->proxy->address;
    }

    /**
     * Terminate banned proxy instance.
     */
    public function banProxy()
    {
        _log('Proxy ' . $this->proxy->ip . ' banned.', 'red');
        DB::table('proxy')->where('address', $this->proxy->address)->delete();

        $target_instance_ids = [];
        $instances = $this->getProxyInstances();
        foreach ($instances as $instance) {
            if ($instance['PublicIpAddress'] == $this->proxy->ip) {
                $target_instance_ids[] = $instance['InstanceId'];
            }
        }
        if (!$target_instance_ids) {
            return;
        }
        $this->ec2Client->terminateInstances(['InstanceIds' => $target_instance_ids]);
        $this->waitUntil('InstanceTerminated', ['InstanceIds' => $target_instance_ids]);

        _log('Proxy ' . $this->proxy->ip . ' terminated.', 'red');
    }

    /**
     * Call which can handle custom waiters.
     *
     * @param $name
     * @param $arg
     *
     * @throws \Exception
     */
    private function waitUntil($name, $arg)
    {
        if ($config = $this->customEc2WaiterConfig($name)) {
            $waiter = new \Aws\Waiter($this->ec2Client, $name, $arg, $config);
            $waiter->promise()->wait();
        } else {
            $this->ec2Client->waitUntil($name, $arg);
        }
    }

    /**
     * Custom EC2 waiter definitions.
     *
     * @param $name
     *
     * @return mixed
     */
    private function customEc2WaiterConfig($name)
    {
        $config = [
            'SpotInstanceRequestCanceled'  => [
                "operation"   => "DescribeSpotInstanceRequests",
                "maxAttempts" => 40,
                "delay"       => 15,
                "acceptors"   => [
                    [
                        "state"    => "success",
                        "matcher"  => "pathAll",
                        "argument" => "SpotInstanceRequests[].State",
                        "expected" => "cancelled"
                    ]
                ]
            ],
            'SpotInstanceRequestFulfilled' => [
                'operation'   => 'DescribeSpotInstanceRequests',
                'maxAttempts' => 40,
                'delay'       => 15,
                'acceptors'   => [
                    [
                        'state'    => 'success',
                        'matcher'  => 'pathAll',
                        'argument' => 'SpotInstanceRequests[].Status.Code',
                        'expected' => 'fulfilled',
                    ],
                    [
                        "matcher"  => "error",
                        "expected" => "InvalidSpotInstanceRequestID.NotFound",
                        "state"    => "retry"
                    ],
                    [
                        'state'    => 'failure',
                        'matcher'  => 'pathAny',
                        'argument' => 'SpotInstanceRequests[].Status.Code',
                        'expected' => 'schedule-expired',
                    ],
                    [
                        'state'    => 'failure',
                        'matcher'  => 'pathAny',
                        'argument' => 'SpotInstanceRequests[].Status.Code',
                        'expected' => 'canceled-before-fulfillment',
                    ],
                    [
                        'state'    => 'failure',
                        'matcher'  => 'pathAny',
                        'argument' => 'SpotInstanceRequests[].Status.Code',
                        'expected' => 'bad-parameters',
                    ],
                    [
                        'state'    => 'failure',
                        'matcher'  => 'pathAny',
                        'argument' => 'SpotInstanceRequests[].Status.Code',
                        'expected' => 'system-error',
                    ]
                ],
            ]
        ];

        if (isset($config[$name])) {
            return $config[$name];
        }
    }
}