<?php
/**
 * Compatibility shim: index.php expects panels.php at the bot root.
 * The panel drivers now live in panels/bootstrap.php.
 */
require_once __DIR__ . '/panels/bootstrap.php';
