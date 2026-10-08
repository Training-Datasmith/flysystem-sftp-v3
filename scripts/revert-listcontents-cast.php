<?php

declare(strict_types=1);

$path = $argv[1] ?? 'SftpAdapter.php';
$contents = file_get_contents($path);
$needle = "            // Ensure numeric keys are strings.\n            \$filename = (string) \$filename;\n";
if (substr_count($contents, $needle) !== 1) {
    fwrite(STDERR, "could not locate listContents numeric cast for demo\n");
    exit(1);
}
file_put_contents($path, str_replace($needle, '', $contents));
