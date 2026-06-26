<?php
$out = shell_exec('echo "test" >> /var/www/html/videovizard.com/exec_test.txt 2>&1');
echo "shell_exec result: " . var_export($out, true);
echo "\nFile exists: " . (file_exists('/var/www/html/videovizard.com/exec_test.txt') ? 'YES' : 'NO');
