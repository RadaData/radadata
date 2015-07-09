<?php

namespace ShvetsGroup\Service\Proxy;

use Aws\Ec2\Ec2Client;
use GuzzleHttp\Promise;
use Symfony\Component\Config\Definition\Exception\Exception;

class AWSProxy implements IProxyProvider
{

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

    public function __construct($aws_config)
    {
        $this->aws_config = $aws_config;
        $this->ec2Client = new Ec2Client($aws_config);
        $this->serverKeysPath = BASE_PATH . $aws_config['server_keys_dir'];
    }

    /**
     * @param int  $count
     * @param bool $reset
     *
     * @return mixed
     * @throws \Exception
     */
    public function get($count = null, $reset = false)
    {
        exec('pkill -f "ssh -o UserKnownHostsFile"');

        if ($reset) {
            $this->reset();
        }

        if ($count) {
            $this->makeProxiesOrDie($count);
        }

        $ips = $this->getProxyInstances('PublicIpAddress');

        _log('Connecting to ' . ($count != null ? $count : count($ips)) . ' proxy instances.');

        $i = 0;
        $proxy_port = 7999; // Starting proxy port.
        $proxies = [];
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
                $proxies['127.0.0.1:' . $proxy_port] = $ip;
                $proxy_port--;
                $i++;
                continue;
            } // This will be executed by forks.
            else {
                exec('ssh -o UserKnownHostsFile=/dev/null -o "StrictHostKeyChecking no" -i ' . $this->serverKeysPath . '/AMI.pem -L ' . $proxy_port . ':localhost:8888 -N ubuntu@' . $ip);
                die();
            }
        }

        if (!count($proxies)) {
            throw new \Exception('There are no available IP addresses of proxies.');
        }

        return $proxies;
    }

    /**
     * Kill all existing instances.
     */
    public function reset()
    {
        $this->terminateInstances();
        $this->cancelSpotRequests();

        _log('Old proxies have been terminated.', 'green');
    }

    /**
     * Terminate banned proxy instance.
     */
    public function ban($ip)
    {
        $instances = $this->getProxyInstances();
        foreach ($instances as $instance) {
            if ($instance['PublicIpAddress'] == $ip) {
                $this->ec2Client->terminateInstances(['InstanceIds' => [$instance['InstanceId']]]);
                $this->waitUntil('InstanceTerminated', ['InstanceIds' => [$instance['InstanceId']]]);

                return;
            }
        }
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
    public function makeProxiesOrDie($count)
    {
        if ($count <= 0) {
            throw new Exception('Count must be positive number.');
        }

        try {
            $real_proxy_count = count($this->getProxyInstances());
            $proxy_to_create = $count - $real_proxy_count;

            if ($real_proxy_count > 0 && $proxy_to_create <= 0) {
                _log('There are ' . $real_proxy_count . ' existing proxies, no need to create new.', 'green');

                return;
            }

            _log('There are ' . $real_proxy_count . ' existing proxies, I am going to create ' . $proxy_to_create . ' more.', 'green');

            $instanceIds = $this->makeSpotRequests($proxy_to_create);

            _log('Waiting for ' . $proxy_to_create . ' instances to launch.');
            $this->waitUntil('InstanceRunning', [
                'InstanceIds' => $instanceIds,
            ]);
            $this->waitUntil('InstanceStatusOk', [
                'InstanceIds' => $instanceIds,
            ]);
            _log($proxy_to_create . ' new proxy instances have been launched.', 'green');
        } catch (\Exception $e) {
            _log('makeProxiesOrDie: ' . $e->getMessage(), 'red');
            $this->reset();
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