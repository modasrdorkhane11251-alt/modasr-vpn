<?php
/**
 * Compatibility shim: the installer expects table.php at the bot root.
 * The schema builder now lives in db/bootstrap.php.
 */
require_once __DIR__ . '/db/bootstrap.php';
echo "tables initialized\n";
