<?php

require '../includes/bootstrap.php';

chdir('../');

print "<pre>";

$result = count(explode("\n", shell_exec('pgrep -f "crawler.php"'))) > 2;
print "Download process status: " . ($result ? 'RUNNING' : 'STOPPED');
check();

print "</pre>";