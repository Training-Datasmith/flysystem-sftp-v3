<?php

declare(strict_types=1);

$path = $argv[1] ?? 'SftpConnectionProvider.php';
$contents = file_get_contents($path);
$needle = "            \$options['disableStatCache'] ?? true,\n";
if (substr_count($contents, $needle) !== 1) {
    fwrite(STDERR, "could not locate disableStatCache fromArray line for demo\n");
    exit(1);
}
file_put_contents($path, str_replace($needle, '', $contents));
