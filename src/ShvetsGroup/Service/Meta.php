<?php

namespace ShvetsGroup\Service;

use Illuminate\Database\Capsule\Manager as DB;

class Meta
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
     * @return object['name']
     *              ['id']  string
     *              ['name']       string
     *              ['full_name']  string
     *              ['group_name'] string
     *              ['website']    string
     *              ['url']        string
     */
    public function getIssuers()
    {
        $issuers = [];

        $db_issuers = DB::table('issuers')->get();
        foreach ($db_issuers as $issuer) {
            $issuers[$issuer->name] = $issuer;
        }

        return $issuers;
    }

    /**
     * Assign new list of law states.
     *
     * @param array $issuers
     */
    public function setIssuers(array $issuers)
    {
        DB::table('issuers')->delete();
        foreach ($issuers as $issuer) {
            DB::table('issuers')->insert(
                ['id'         => $issuer->id,
                 'name'       => $issuer->name,
                 'full_name'  => $issuer->full_name,
                 'group_name' => $issuer->group_name,
                 'website'    => $issuer->website,
                 'url'        => $issuer->url]
            );
        }
    }

    /**
     * Assign new list of law types.
     *
     * @param array $types
     */
    public function setTypes(array $types)
    {
        DB::table('types')->delete();
        foreach ($types as $type) {
            DB::table('types')->insert(
                ['id'  => $type->id, 'name' => $type->name]
            );
        }
    }

    /**
     * Assign new list of law states.
     *
     * @param array $states
     */
    public function setStates(array $states)
    {
        DB::table('states')->delete();
        foreach ($states as $state) {
            DB::table('states')->insert(
                ['id'  => $state->id, 'name' => $state->name]
            );
        }
    }

    /**
     * Parse the issuers, states and law types lists from their listing ( http://zakon.rada.gov.ua/laws/stru/a ).
     *
     * @param bool $re_download
     */
    public function parse($re_download)
    {
        $html = download('/laws/stru/a', $re_download);
        $list = crawler($html);

        // The loop here is to parse both domestic and international issuers.
        for ($i = 1; $i <= 2; $i++) {
            $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . $i . ']/tbody/tr/td/table/tbody/tr';
            $group = null;
            $issuers = [];
            $list->filterXPath($XPATH)->each(
                function ($node) use (&$issuers, &$group, $i) {
                    $cells = $node->filterXPath('//td');
                    if ($cells->count() == 1) {
                        $text = better_trim($cells->text());
                        if ($text) {
                            $group = $text;
                        }
                    } elseif ($cells->count() == 4) {
                        $issuer_link = $node->filterXPath('//td[2]/a');
                        $issuer = new \stdClass();
                        $issuer->url = $issuer_link->attr('href');
                        $issuer->id = str_replace('/laws/main/', '', $issuer->url);
                        $issuer->group_name = $group;
                        $issuer->name = better_trim($issuer_link->text());
                        $issuer->full_name = null;
                        if (preg_match('|(.*?)(:? \((.*?)\))?$|', $issuer->name, $match)) {
                            if (isset($match[2])) {
                                $issuer->name = $match[2];
                                $issuer->full_name = $match[1];
                            }
                        }
                        $issuer->website = $issuer_link->count() == 2 ? $issuer_link->last()->attr('href') : null;
                        $issuer->international = $i;
                        $issuers[$issuer->name] = $issuer;
                    }
                }
            );
        }
        $this->setIssuers($issuers);

        $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . 3 . ']/tbody/tr/td/table/tbody/tr';
        $types = [];
        $list->filterXPath($XPATH)->each(
            function ($node) use (&$types) {
                $cells = $node->filterXPath('//td');
                if ($cells->count() == 4) {
                    $type_link = $node->filterXPath('//td[2]/a');
                    $type = new \stdClass();
                    $type->url = $type_link->attr('href');
                    $type->id = str_replace('/laws/main/', '', $type->url);
                    $type->name = better_trim($type_link->text());
                    $types[$type->name] = $type;
                }
            }
        );
        $this->setTypes($types);

        $XPATH = '//*[@id="page"]/div[2]/table/tbody/tr[1]/td[3]/div/div[2]/table[' . 5 . ']/tbody/tr/td/table/tbody/tr';
        $states = [];
        $list->filterXPath($XPATH)->each(
            function ($node) use (&$states) {
                $cells = $node->filterXPath('//td');
                if ($cells->count() == 4) {
                    $state_link = $node->filterXPath('//td[2]/a');
                    $state = new \stdClass();
                    $state->url = $state_link->attr('href');
                    $state->id = str_replace('/laws/main/', '', $state->url);
                    $state->name = better_trim($state_link->text());
                    $states[$state->name] = $state;
                }
            }
        );
        $this->setStates($states);

    }
}