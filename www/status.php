<?php

require '../includes/bootstrap.php';

chdir('../');

print "<pre>";

$result = (bool)shell_exec('pgrep -f "crawler.php"');
print "Download process status: " . ($result ? 'RUNNING' : 'STOPPED');
check();

print "</pre>";