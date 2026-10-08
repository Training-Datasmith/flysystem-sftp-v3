<?php

declare(strict_types=1);

$path = $argv[1] ?? 'SftpAdapter.php';
$contents = file_get_contents($path);
$needle = 'if ($visibility === null && $config->get(Config::OPTION_RETAIN_VISIBILITY, true)) {';
$replacement = 'if ($visibility === null && true) {';
if (substr_count($contents, $needle) !== 1) {
    fwrite(STDERR, "could not locate copy retain-visibility guard for demo\n");
    exit(1);
}
file_put_contents($path, str_replace($needle, $replacement, $contents));
