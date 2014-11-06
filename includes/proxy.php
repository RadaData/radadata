<?php

function init_proxies()
{
    static $initialized;
    if ($initialized) {
        return;
    }

    global $proxies;
    $proxies = array();
    $proxy_port = 7999;

    $ips = array();
    $ec2Client = \Aws\Ec2\Ec2Client::factory(aws());
    $result = $ec2Client->DescribeInstances(array(
        'Filters' => array(
            array('Name' => 'instance-type', 'Values' => array('t1.micro')),
        )
    ));
    $reservations = $result['Reservations'];
    foreach ($reservations as $reservation) {
        $instances = $reservation['Instances'];
        foreach ($instances as $instance) {
            if ($instance['State']['Name'] == 'running' && isset($instance['PublicIpAddress'])) {
                $ips[] = $instance['PublicIpAddress'];
            }
        }
    }

    exec('pkill -f "ssh -o UserKnownHostsFile"');
    db('misc')->exec('DELETE FROM proxies');

    if (!count($ips)) {
        _log('Error: There is no t1.micro instances for proxies in eu-west-1 region.', 'red');
        die();
    } else {
        _log(count($ips) . ' proxy servers found', 'green');
    }

    foreach ($ips as $ip) {
        $pid = pcntl_fork();

        if ($pid) {
            $proxies[] = '127.0.0.1:' . $proxy_port;
            $proxy_port--;
            continue;
        }

        exec('ssh -o UserKnownHostsFile=/dev/null -o "StrictHostKeyChecking no" -i servers/AMI.pem -L ' . $proxy_port . ':localhost:8888 -N ubuntu@' . $ip);
        die();
    }

    foreach ($proxies as $pr) {
        db('misc')->prepare('INSERT INTO proxies (proxy, last_used) VALUES (:proxy, 0)')
            ->execute(array(':proxy' => $pr));
    }
    $initialized = true;
}

function getProxy()
{
    if (!variable_get('use_proxy')) {
        return 'localhost';
    }
    init_proxies();
    
    global $proxy;
    if ($proxy) {
        return $proxy;
    }

    $db = db('misc');
    $db->beginTransaction();
    $sql = "SELECT proxy FROM proxies ORDER BY last_used ASC LIMIT 1";
    $query = $db->prepare($sql);
    $query->execute();
    $proxy = $query->fetchColumn();
    $db->prepare("UPDATE proxies SET last_used = :time WHERE `proxy` = :proxy;")->execute(array(
        ':time' => time(),
        ':proxy' => $proxy
    ));
    $db->commit();

    if (!$proxy) {
        throw new Exception('No available proxy.');
    }

    return $proxy;
}

function releaseProxy()
{
    global $proxy;
    db('misc')->prepare("UPDATE proxies SET last_used = :time WHERE `proxy` = :proxy;")->execute(array(
        ':time' => 0,
        ':proxy' => $proxy
    ));
    $proxy = null;
}