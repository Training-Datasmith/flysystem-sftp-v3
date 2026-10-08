<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Double;

use League\Flysystem\PhpseclibV3\ConnectionProvider;
use phpseclib3\Net\SFTP;

class CallbackConnectionProvider implements ConnectionProvider
{
    private ?SFTP $connection = null;

    public function __construct(
        private \Closure $factory,
        private ?\Closure $onDisconnect = null,
    ) {
    }

    public function provideConnection(): SFTP
    {
        if ($this->connection instanceof SFTP) {
            return $this->connection;
        }

        $this->connection = ($this->factory)();

        return $this->connection;
    }

    public function disconnect(): void
    {
        if ($this->connection && $this->onDisconnect) {
            ($this->onDisconnect)($this->connection);
        }
        if ($this->connection && method_exists($this->connection, 'disconnect')) {
            $this->connection->disconnect();
        }
        $this->connection = null;
    }
}
