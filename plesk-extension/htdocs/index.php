<?php

/**
 * Plesk extension entry point.
 *
 * Routes between the extension UI and install action handlers.
 */

require_once __DIR__ . '/../plib/library/IndexhibitInstallerController.php';

$controller = new IndexhibitInstallerController();
$controller->run();
