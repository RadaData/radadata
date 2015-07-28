<?php

namespace ShvetsGroup\Service\Proxy;

use Illuminate\Database\Capsule\Manager as DB;

class ProxyManager
{

    /**
     * @var object Current proxy.
     */
    public $proxy;

    /**
     * @var string Whether or not to use proxies.
     */
    private $useProxy;

    /**
     * @var IProxyProvider
     */
    private $proxyProvider;

    /**
     * @param $useProxy
     * @param $proxyProvider
     */
    public function __construct($useProxy, $proxyProvider)
    {
        $this->useProxy = $useProxy;
        $this->proxyProvider = $proxyProvider;
    }

    /**
     * Whether or not the proxy should be used.
     *
     * @param null $forceUseProxy If passed, proxy will be used.
     *
     * @return bool|string
     */
    public function useProxy($forceUseProxy = null)
    {
        if ($forceUseProxy) {
            $this->useProxy = true;
        }
        return $this->useProxy;
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
    public function connect($count = null, $reset = false)
    {
        static $initialized;
        if ($initialized && !$reset) {
            return;
        }

        $this->disconnect();

        $ips = $this->proxyProvider->get($count, $reset);

        foreach ($ips as $address => $ip) {
            DB::table('proxy')->insert(['address' => $address, 'ip' => $ip, 'last_used' => 0]);
        }

        _log(count($ips) . ' fresh proxies connected.');
        $initialized = true;
    }

    /**
     * Drop connections with proxy servers.
     */
    private function disconnect()
    {
        _log('Dropping old connections.');
        DB::table('proxy')->truncate();
    }

    /**
     * Get a proxy address from the pool. If proxy config is disabled, 'localhost' will be returned.
     *
     * @return object Proxy object (for example {'address': '127.0.0.1:2345', 'ip': '34.23.12.1'}).
     */
    public function getProxy()
    {
        if (!$this->useProxy()) {
            return json_decode(json_encode(['address' => 'localhost', 'ip' => 'localhost']), FALSE);
        }

        try {
            // Init proxies if needed.
            $this->connect();

            if (!$this->proxy) {
                return $this->selectProxy();
            }
            else {
                return $this->proxy;
            }
        } catch (\Exception $e) {
            _log($e->getMessage(), 'red');
            die();
        }
    }

    /**
     * Get proxy connection address.
     * @return string
     */
    public function getProxyAddress()
    {
        return $this->getProxy()->address;
    }

    /**
     * Get proxy real IP.
     * @return string
     */
    public function getProxyIp()
    {
        return $this->getProxy()->ip;
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
        _log('Proxy claimed: ' . $this->proxy->ip);

        return $this->proxy;
    }

    /**
     * Return active proxy to the pool.
     */
    public function releaseProxy()
    {
        if (!$this->useProxy()) {
            return;
        }
        if (!isset($this->proxy)) {
            return;
        }

        _log('Proxy released: ' . $this->proxy->ip);
        DB::table('proxy')->where('address', $this->getProxyAddress())->update(['in_use' => 0, 'last_used' => round(microtime(true) * 100)]);
        $this->proxy = null;
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
        $this->connect();

        return DB::table('proxy')->count();
    }

    /**
     * Terminate banned proxy instance.
     */
    public function banProxy()
    {
        if (!$this->useProxy()) {
            return;
        }

        DB::table('proxy')->where('address', $this->proxy->address)->delete();
        $this->proxyProvider->ban($this->proxy->ip);
    }

    /**
     * Terminate banned proxy instance.
     */
    public function reset()
    {
        $this->proxyProvider->reset();
    }
}