#!/usr/bin/php
<?php

define('AUXTALKS_NO_SERVER', true);
chdir(DIR);

ob_start();
require_once(DIR . '/testRabbitMQServer.php');
ob_end_clean();

echo "[" . date('Y-m-d H:i:s')."] Price check started" . PHP_EOL;
try {
    $result=handleRunPriceCheck();
    echo "[". date('Y-m-d H:i:s')."] Price check started" . PHP_EOL;
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s'). "] Price check fialed:" . $e->getMessage() . PHP_EOL;
    exit(1);
}
?>