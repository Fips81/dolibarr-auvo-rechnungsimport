<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Background worker: analyses all queued supplier invoice imports.
 * Started detached by the upload (auvorechnungsimport_queue_start()); can also be
 * run by hand or from cron:  php process_queue.php [entity]
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('CLI only');
}

define('NOLOGIN', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$entity = isset($argv[1]) ? (int) $argv[1] : 1;
if ($entity > 1) {
	define('DOLENTITY', $entity);
}

$res = 0;
foreach (array(__DIR__.'/../../../master.inc.php', __DIR__.'/../../../../master.inc.php', __DIR__.'/../../../../htdocs/master.inc.php') as $candidate) {
	if (!$res && file_exists($candidate)) {
		$res = @include $candidate;
	}
}
if (!$res) {
	fwrite(STDERR, "master.inc.php not found\n");
	exit(1);
}

require_once __DIR__.'/../core/lib/auvorechnungsimport.lib.php';

// OCR of multi-page scans can take minutes and some PDFs need memory.
set_time_limit(0);
@ini_set('memory_limit', '512M');

$done = auvorechnungsimport_process_queue($db);
if ($done < 0) {
	echo "Another worker is running.\n";
	exit(0);
}
echo "Analysed: ".$done."\n";
exit(0);
