<?php

namespace ShvetsGroup\Service;

/**
 * Class Identity
 * @package ShvetsGroup\Service
 *
 * RADA blocks the incoming traffic in case if it detects too many connections from certain Mirror-UserAgent-UserIP
 * combinations. This class is created to switch between variations of mirrors and user agents to allow
 * reliable non stop crawling. If all combinations are run out, the new proxy is picked and everything starts over.
 */

class Identity
{

    /**
     * RADA's traffic IP filter blocks not the whole IP, but the IP-UserAgent combination. Surprisingly iPhone user
     * agent has it's own share of connections.
     *
     * @var array
     */
    private $user_agents = array(
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36',
        'Mozilla/5.0 (iPhone; U; CPU iPhone OS 4_3_2 like Mac OS X; en-us) AppleWebKit/533.17.9 (KHTML, like Gecko) Version/5.0.2 Mobile/8H7 Safari/6533.18.5',
    );

    /**
     * RADA's mirrors.
     *
     * @var array
     */
    private $website = array(
        'mirrors' => array(
            'http://zakon1.rada.gov.ua',
            'http://zakon2.rada.gov.ua',
            'http://zakon3.rada.gov.ua',
            'http://zakon4.rada.gov.ua',
        ),
        'dir'     => 'zakon.rada.gov.ua'
    );

    private $active_user_agent = 0;

    private $active_mirror = 0;

    /**
     * Get current UserAgent string.
     *
     * @return string
     */
    public function getUserAgent()
    {
        return $this->user_agents[$this->active_user_agent];
    }

    /**
     * Get current RADA's mirror domain.
     *
     * @return string
     */
    public function getMirror()
    {
        if (!isset($this->website['shuffled']) || !$this->website['shuffled']) {
            shuffle($this->website['mirrors']);
            $this->website['shuffled'] = 1;
        }

        return $this->website['mirrors'][$this->active_mirror];
    }

    /**
     * Switch Mirror-UserAgent combination.
     *
     * @param bool $cant_change_mirror
     *
     * @return bool
     */
    function switchIdentity($cant_change_mirror = false)
    {
        if ($this->active_user_agent == 0) {
            $this->active_user_agent = 1;

            return true;
        }

        if ($cant_change_mirror) {
            return false;
        }

        if ($this->active_mirror < count($this->website['mirrors']) - 1) {
            $this->active_mirror++;
            $this->active_user_agent = 0;

            return true;
        }

        return false;
    }

}