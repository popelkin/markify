<?php
#!/usr/bin/php

set_time_limit(0);
//ini_set('memory_limit', '2048M');

require "vendor/autoload.php";

use Classes\BaseParser;

if (PHP_SAPI !== 'cli') {
    die('Run from CLI only!');
}

if ($_SERVER['argc'] < 2) {
    exit('Use ' . PHP_EOL . 'php ' . dirname(__FILE__) . '/index.php WORD');
}

$parser = new BaseParser($_SERVER['argv'][1]);
$parser->process();

