<?php

function launch_workers($max_workers, $group, $function = NULL) {
  $max = min($max_workers, count($GLOBALS['proxies']));
  _log('Launching ' . $max . ' workers for "' . $group . '/' . $function . '" operations.', 'title');

  if ($max == 1) {
    while ($job = fetch_job($group, $function)) {
      call_user_func_array($job['function'], $job['parameters']);
      complete_job($job);
    }
  }
  else {
    $child = 0;
    while ($job = fetch_job($group, $function)) {
      $child++;
      close_db('db');
      close_db('misc');


      $pid = pcntl_fork();

      if ($pid == -1) {
        die("could not fork");
      }
      else {
        if ($pid) {
          // we are the parent
          if ($child >= $max) {
            pcntl_wait($status);
            $child--;
          }
        }
        else {
          call_user_func_array($job['function'], $job['parameters']);
          complete_job($job);
          releaseProxy();
          exit;
        }
      }
    }
  }
}

function clear_jobs($group) {
  db('db')->exec("DELETE FROM jobs WHERE `group` = '" . $group . "'");
}

function add_job($func, $parameters, $group) {
  $parameters = serialize($parameters);
  $stmt = db('db')->prepare("INSERT INTO jobs (`function`, `parameters`, `group`) VALUES (:function, :parameters, :group);");
  $stmt->bindParam(':function', $func);
  $stmt->bindParam(':parameters', $parameters);
  $stmt->bindParam(':group', $group);
  return $stmt->execute();
}

function fetch_job($group = null, $function = null) {
  $db = db('db');
  $db->beginTransaction();
  $sql = "SELECT * FROM jobs WHERE `claimed` IS NULL" . ($group ? " AND `group` = :group" : '') . ($function ? " AND `function` = :function" : '') . " ORDER BY id LIMIT 1 FOR UPDATE";
  $query = $db->prepare($sql);
  $query->bindParam(':group', $group);
  $query->bindParam(':function', $function);
  $query->execute();
  $row = $query->fetch();
  $db->prepare("UPDATE jobs SET `claimed` = :claimed WHERE `id` = :id;")->execute(array(':claimed' => time(), ':id' => $row['id']));
  $db->commit();

  if ($row) {
    $job = array(
      'id' => $row['id'],
      'function' => $row['function'],
      'parameters' => unserialize($row['parameters']),
      'group' => $row['group'],
    );
    return $job;
  }
}

function complete_job($job) {
  db('db')->prepare("DELETE FROM jobs WHERE `id` = :id")->execute(array(':id' => $job['id']));
}

function clean_jobs() {
  db('db')->prepare("UPDATE jobs SET `claimed` = NULL WHERE `claimed` IS NOT NULL")->execute(array());
}

