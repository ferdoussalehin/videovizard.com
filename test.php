// create a quick test.php and run it in browser
<?php
echo shell_exec('php -v');

echo "Web PHP version : " . PHP_VERSION . "\n";
echo "PHP CLI path    : " . shell_exec('which php') . "\n";
echo "PHP CLI version : " . shell_exec('php -v') . "\n";
echo "shell_exec      : enabled\n";