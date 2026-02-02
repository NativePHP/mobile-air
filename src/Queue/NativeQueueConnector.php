<?php

namespace Native\Mobile\Queue;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

class NativeQueueConnector implements ConnectorInterface
{
    /**
     * The database connection resolver.
     */
    protected ConnectionResolverInterface $connections;

    /**
     * Create a new connector instance.
     */
    public function __construct(ConnectionResolverInterface $connections)
    {
        $this->connections = $connections;
    }

    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new NativeQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'] ?? 'jobs',
            $config['queue'] ?? 'default',
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? false
        );
    }
}
