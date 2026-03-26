<?php

$input = getenv('EPV2_WORKER_INPUT');
if (! is_string($input) || $input === '' || ! is_file($input)) {
	fwrite(STDERR, "Missing EPV2_WORKER_INPUT\n");
	exit(1);
}

try {
	$request = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
	$request = is_array($request) ? $request : [];
	$result = EPV2_Worker_Bridge::execute_request($request);
	$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	if (! is_string($json) || $json === '') {
		throw new RuntimeException('Worker bridge produced empty JSON output');
	}
	echo $json;
	exit(0);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
