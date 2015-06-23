<?php

namespace ShvetsGroup\Service;

class Issuers
{

    /**
     * Whether or not issuers list is empty.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->getIssuers());
    }

    /**
     * Get associate array of all issuers.
     *
     * @return array['name']
     *                     ['issuer_id']  string
     *                  ['name']       string
     *                  ['full_name']  string
     *                  ['group_name'] string
     *                  ['website']    string
     *                  ['url']        string
     */
    public function getIssuers()
    {
        $issuers = array();
        $db_issuers = db('db')->query('SELECT * FROM issuers')->fetchAll();
        foreach ($db_issuers as $issuer) {
            $issuers[$issuer['name']] = $issuer;
        }

        return $issuers;
    }

    /**
     * Assign new list of law issuers.
     *
     * @param array $issuers
     */
    public function setIssuers(array $issuers)
    {
        foreach ($issuers as $issuer) {
            $sql = "INSERT IGNORE INTO issuers (issuer_id, name, full_name, group_name, website, url) VALUES (:issuer_id, :name, :full_name, :group_name, :website, :url)";
            $q = db('db')->prepare($sql);
            $q->execute(array(':issuer_id'  => $issuer['issuer_id'],
                              ':name'       => $issuer['name'],
                              ':full_name'  => $issuer['full_name'],
                              ':group_name' => $issuer['group_name'],
                              ':website'    => $issuer['website'],
                              ':url'        => $issuer['url']
            ));
        }
    }

    /**
     * Parse the issuers list by given HTML code of their listing ( http://zakon.rada.gov.ua/laws/stru/a ).
     *
     * @param string $html
     */
    public function parse($html)
    {
        $list = crawler($html);
        $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[1]/tbody/tr/td/table/tbody/tr';
        $group = null;
        $issuers = array();
        $list->filterXPath($XPATH)->each(
            function ($node) use (&$issuers, &$group) {
                $cells = $node->filterXPath('//td');
                if ($cells->count() == 1) {
                    $text = better_trim($cells->text());
                    if ($text) {
                        $group = $text;
                    }
                } elseif ($cells->count() == 4) {
                    $issuer_link = $node->filterXPath('//td[2]/a');
                    $issuer = array();
                    $issuer['url'] = $issuer_link->attr('href');
                    $issuer['issuer_id'] = str_replace('/laws/main/', '', $issuer['url']);
                    $issuer['group_name'] = $group;
                    $issuer['name'] = better_trim($issuer_link->text());
                    $issuer['full_name'] = null;
                    if (preg_match('|(.*?) \((.*?)\)|', $issuer['name'], $match)) {
                        if (isset($match[2])) {
                            $issuer['name'] = $match[2];

                        }
                        $issuer['full_name'] = $match[1];
                    }
                    $issuer['website'] = $issuer_link->count() == 2 ? $issuer_link->last()->attr('href') : null;
                    if (!isset($issuers[$issuer['name']])) {
                        $issuers[$issuer['name']] = $issuer;
                    }
                }
            }
        );
        $this->setIssuers($issuers);
    }
}