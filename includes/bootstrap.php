<?php
global $argv;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/variables.php';
require_once __DIR__ . '/PhantomJsOverride.php';


$GLOBALS['start_time'] = time();

function shell_parameters()
{
    global $argv;
    if (!isset($args)) {
        $args = array();
        foreach ($argv as $i => $param) {
            if ($i > 0) {
                list($key, $value) = explode('=', $param . '=');
                $args[$key] = $value;
            }
        }
    }

    return $args;
}

function curl_options()
{
    return array(
        'curl.options' => array(
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_MAXREDIRS => 5,
        )
    );
}

function _log($message, $style = 'default')
{
    date_default_timezone_set('Europe/Kiev');

    $output = $message . "\n";
    if ($style == 'title') {
        $output = "\n\n" . $output;
    }
    $args = shell_parameters();
    $log_file = isset($args['log']) ? $args['log'] : __DIR__ . '/../log.txt';
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' :: ' . $output, FILE_APPEND);


    if ($style == 'red') {
        $output = "\033[0;31m" . $output . "\033[0m";
    } elseif ($style == 'yellow') {
        $output = "\033[1;33m" . $output . "\033[0m";
    } elseif ($style == 'green') {
        $output = "\033[0;32m" . $output . "\033[0m";
    } elseif ($style == 'title') {
        $output = "\033[1m" . $output . "\033[0m";
    }
    echo $output;
}

function aws()
{
    global $conf;

    return $conf['aws'];
}

function instance_parameters()
{
    global $conf;
    static $params;

    if (isset($conf['tags'])) {
        return $conf['tags'];
    }

    if (!isset($params)) {
        $params = array();
        try {
            $instance_id = file_get_contents("http://instance-data/latest/meta-data/instance-id");
            $EC2Client = Ec2Client::factory(aws());

            // Read parameters from tags.
            $tags = $EC2Client->getIterator(
                'DescribeTags',
                array('Filters' => array(array('Name' => 'resource-id', 'Values' => array($instance_id))))
            );
            foreach ($tags as $tag) {
                $params[$tag['Key']] = $tag['Value'];
            }

            // Read rest from user-data and convert it to tags for future.
            $user_data = file_get_contents("http://instance-data/latest/user-data");
            if (!empty($user_data)) {
                parse_str($user_data, $arr);
                $tags = array();
                foreach ($arr as $key => $value) {
                    if (!isset($params[$key])) {
                        $tags[] = array('Key' => $key, 'Value' => $value);
                        $params[$key] = $value;
                    }
                }
                if (!empty($tags)) {
                    $EC2Client->createTags(
                        array(
                            'Resources' => array($instance_id),
                            'Tags' => $tags
                        )
                    );
                }
            }
        } catch (Exception $e) {
        }
        ;
    }

    return $params;
}

function delTree($dir) {
  $files = array_diff(scandir($dir), array('.','..'));
  foreach ($files as $file) {
    (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
  }
  return rmdir($dir);
}