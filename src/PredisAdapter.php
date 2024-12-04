<?php

declare(strict_types=1);

namespace LLegaz\Predis;

use LLegaz\Predis\Exception\ConnectionLostException;
use LogicException;
use Predis\Client;
use Predis\Response\Status;

/**
 * This class isn't really an adapter but it settles base for other projects based on it
 *
 * @todo refactor this (rename adapter to base maybe ? or Wrapper ?)
 *
 * @author Laurent LEGAZ <laurent@legaz.eu>
 */
class PredisAdapter
{
    /**
     * current redis client in use
     *
     */
    private $predis = null;

    /**
     * current redis client <b>context</b> in use
     *
     */
    private $context = [];

    /**
     *
     * @param string $host
     * @param type $port
     * @param string $pwd
     * @param type $scheme
     * @param int $db
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, ?string $pwd = null, string $scheme = 'tcp', int $db = 0, ?Client $predis = null)
    {
        $this->context = [
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'database' => $db,
        ];
        if ($pwd && strlen($pwd)) {
            $this->context['password'] = $pwd;
        }
        if ($predis instanceof Client) {
            // for the sake of units
            $this->predis = $predis;
        } else {
            $this->predis = PredisClientsPool::getClient($this->context);
            $this->checkDatabase();
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        if ($this->predis instanceof Client) {
            $this->predis->disconnect();
            unset($this->predis);
        }
    }

    /**
     *
     * @param int $db
     * @return bool
     * @throws ConnectionLostException
     */
    public function selectDatabase(int $db): bool
    {
        if (!$this->isConnected()) {
            throw new ConnectionLostException();
        }
        $this->context['database'] = $db;
        $redisResponse = $this->predis->select($db);

        return ($redisResponse instanceof Status && $redisResponse->getPayload() === 'OK') ? true : false;
    }

    /**
     *
     * @return array
     * @throws ConnectionLostException
     */
    public function clientList(): array
    {
        if (!$this->isConnected()) {
            throw new ConnectionLostException();
        }

        return $this->predis->client('list');
    }

    /**
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        $ping = false;

        try {
            $ping = $this->predis->ping()->getPayload();
        } catch (\Exception $e) {
            // do nothing
        } finally {
            return ('PONG' === $ping);
        }
    }

    /**
     * constructor helper
     */
    public static function createPredisAdapter(array $conf): self
    {
        $host = $conf['host'] ?? '127.0.0.1';
        $port = $conf['port'] ?? 6379;
        $pwd = $conf['password'] ?? null;
        $scheme = $conf['scheme'] ?? 'tcp';
        $db = $conf['database'] ?? 0;

        return new self($host, $port, $pwd, $scheme, $db);
    }

    /**
     * Check if database is well synced upon instance context and predis singleton
     * (see <b>PredisClientsPool</b> @class)
     *
     * @return bool
     * @throws LogicException
     */
    public function checkDatabase(): bool
    {
        // watch the currently selected database (from predis)
        $context = $this->clientList();
        if (count($context) !== 1) {
            // we manage only singletons of predis clients
            throw new LogicException('we\'ve got a problem here');
        }
        $context = array_pop($context);
        if (!isset($context['db'])) {
            throw new LogicException('we\'ve got a problem here');
        }
        if ($this->context['database'] !== $context['db']) {
            try {
                return $this->selectDatabase($this->context['database']);
                dump('switch db');
            } catch (/* \Predis\PredisException $pe */ \Exception $e) {
                dump('ici', $e);

                return false;
            }
        }

        return true;
    }

    /**
     * PHPUnit getter
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Predis client getter
     *
     * @return array
     */
    public function getRedis(): Client
    {
        return $this->predis;
    }


    /**
     * PHPUnit DI setter
     */
    public function setPredis(Client $client): self
    {
        $this->predis = $client;

        return $this;
    }

    /**
     *
     * @return string
     */
    public function getPredisClientID(): string
    {
        return spl_object_hash($this->predis);
    }
}
