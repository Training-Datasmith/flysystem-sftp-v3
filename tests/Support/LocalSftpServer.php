<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Support;

use RuntimeException;

final class LocalSftpServer
{
    public const PORT = 48222;

    private static ?self $instance = null;

    private string $baseDir;

    private string $homeDir;

    private string $hostKeyPath;

    private string $hostPubPath;

    private string $configPath;

    private string $logPath;

    private string $userPrivateKeyPath;

    private string $userEncryptedKeyPath;

    private string $userUnauthorizedKeyPath;

    private function __construct()
    {
        // ChrootDirectory requires every path component to be root-owned (not world-writable /tmp).
        $this->baseDir = '/srv/flysystem-sftp-v3-' . bin2hex(random_bytes(4));
        if (! is_dir('/srv')) {
            mkdir('/srv', 0755, true);
        }
        if (! mkdir($this->baseDir, 0755, true) && ! is_dir($this->baseDir)) {
            throw new RuntimeException('Unable to create sshd base directory');
        }
        chmod($this->baseDir, 0755);

        $this->homeDir = $this->baseDir . '/home/sftptester';
        $this->hostKeyPath = $this->baseDir . '/ssh_host_ed25519_key';
        $this->hostPubPath = $this->hostKeyPath . '.pub';
        $this->configPath = $this->baseDir . '/sshd_config';
        $this->logPath = $this->baseDir . '/sshd.log';
        $this->userPrivateKeyPath = $this->baseDir . '/client_ed25519';
        $this->userEncryptedKeyPath = $this->baseDir . '/client_ed25519_enc';
        $this->userUnauthorizedKeyPath = $this->baseDir . '/client_unauthorized_ed25519';

        $this->provision();
    }

    public static function start(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function stop(): void
    {
        if (! self::$instance instanceof self) {
            return;
        }

        self::$instance->shutdown();
        self::$instance = null;
    }

    public function port(): int
    {
        return self::PORT;
    }

    public function hostPublicKeyPath(): string
    {
        return $this->hostPubPath;
    }

    public function uploadDirectory(): string
    {
        return $this->homeDir . '/upload';
    }

    public function adapterRoot(): string
    {
        return '/upload';
    }

    public function userPrivateKeyPath(): string
    {
        return $this->userPrivateKeyPath;
    }

    public function userEncryptedKeyPath(): string
    {
        return $this->userEncryptedKeyPath;
    }

    public function userUnauthorizedKeyPath(): string
    {
        return $this->userUnauthorizedKeyPath;
    }

    private function provision(): void
    {
        $this->runCommand(sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -q',
            escapeshellarg($this->hostKeyPath)
        ));

        $upload = $this->homeDir . '/upload';
        $authorizedKeysDir = $this->baseDir . '/authorized_keys';
        $authorizedKeysPath = $authorizedKeysDir . '/sftptester';
        mkdir($upload, 0755, true);
        mkdir($authorizedKeysDir, 0755, true);

        $this->runCommand(sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -q',
            escapeshellarg($this->userPrivateKeyPath)
        ));
        $this->runCommand(sprintf(
            'ssh-keygen -t ed25519 -f %s -N "test-passphrase" -q',
            escapeshellarg($this->userEncryptedKeyPath)
        ));
        $this->runCommand(sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -q',
            escapeshellarg($this->userUnauthorizedKeyPath)
        ));

        file_put_contents(
            $authorizedKeysPath,
            trim((string) file_get_contents($this->userPrivateKeyPath . '.pub')) . "\n"
            . trim((string) file_get_contents($this->userEncryptedKeyPath . '.pub')) . "\n"
        );
        chmod($authorizedKeysPath, 0644);
        chown($authorizedKeysPath, 0);
        chgrp($authorizedKeysPath, 0);
        chown($authorizedKeysDir, 0);
        chgrp($authorizedKeysDir, 0);

        $this->runCommand('useradd -M -d ' . escapeshellarg($this->homeDir) . ' -s /bin/bash sftptester 2>/dev/null || true');
        $this->runCommand('echo ' . escapeshellarg('sftptester:secret-pass') . ' | chpasswd');

        $account = function_exists('posix_getpwnam') ? posix_getpwnam('sftptester') : null;
        if (is_array($account)) {
            chown($upload, $account['uid']);
            chgrp($upload, $account['gid']);
        }

        chmod($this->homeDir, 0755);
        chown($this->homeDir, 0);
        chgrp($this->homeDir, 0);

        file_put_contents($this->configPath, implode("\n", [
            'Port ' . self::PORT,
            'ListenAddress 127.0.0.1',
            'HostKey ' . $this->hostKeyPath,
            'PasswordAuthentication yes',
            'PubkeyAuthentication yes',
            'UsePAM no',
            'PidFile ' . $this->baseDir . '/sshd.pid',
            'AuthorizedKeysFile ' . $authorizedKeysDir . '/%u',
            'Subsystem sftp internal-sftp',
            'Match User sftptester',
            '    ChrootDirectory ' . $this->homeDir,
            '    ForceCommand internal-sftp -d /upload',
            '    AllowTcpForwarding no',
            '    X11Forwarding no',
        ]) . "\n");

        if (! is_dir('/run/sshd')) {
            mkdir('/run/sshd', 0755, true);
        }

        $this->runCommand('sshd -t -f ' . escapeshellarg($this->configPath));

        $command = sprintf(
            'nohup /usr/sbin/sshd -D -f %s -e > %s 2>&1 & echo $!',
            escapeshellarg($this->configPath),
            escapeshellarg($this->logPath)
        );
        $pid = trim((string) shell_exec($command));
        if ($pid === '' || ! ctype_digit($pid)) {
            throw new RuntimeException('Failed to start sshd: ' . file_get_contents($this->logPath));
        }
        $this->pid = (int) $pid;

        $this->waitUntilReady();
    }

    private ?int $pid = null;

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            if ($this->pid !== null && function_exists('posix_kill') && ! posix_kill($this->pid, 0)) {
                throw new RuntimeException('sshd exited early: ' . file_get_contents($this->logPath));
            }

            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('sshd not ready: ' . file_get_contents($this->logPath));
    }

    private function shutdown(): void
    {
        if ($this->pid !== null && function_exists('posix_kill')) {
            posix_kill($this->pid, 15);
        }
    }

    private function runCommand(string $command): void
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('Command failed (' . $command . '): ' . implode("\n", $output));
        }
    }
}
