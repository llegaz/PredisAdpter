<?php

declare(strict_types=1);

namespace LLegaz\Predis;

use LLegaz\Predis\Exception\ConnectionLostException;
use Predis\Client;

/**
 * This class is used to manage all predis clients for the PredisAdapter class
 * thus ensuring no duplication of resources
 *
 *
 * @author Laurent LEGAZ <laurent@legaz.eu>
 */
class PredisClientsPool
{
    /**
     * store all redis clients (multiple clients)
     *
     * @var map
     */
    private static $clients = [];

    public function __destruct()
    {
        foreach (self::$clients as $client) {
            if ($client instanceof Client) {
                $client->disconnect();
                unset($client);
            }
        }
    }

    /**
     * Multiple clients handler
     *
     * @param array $conf
     * @return Client
     */
    public static function getClient(array $conf): Client
    {
        $arrKey = $conf;
        unset($arrKey['database']);
        $md5 = md5(serialize($arrKey));
        if (in_array($md5, array_keys(self::$clients))) {
            // get the client back
            $predis = self::$clients[$md5];
        } else {
            try {
                self::$clients[$md5] = [];
                $predis = new Client($conf);
                $predis->connect();
                self::$clients[$md5] = $predis;
            } catch (/* \Predis\PredisException $pe */ \Exception $e) {
                throw new ConnectionLostException('Connection to redis server is lost or not responding' . PHP_EOL, 500, $e);
            }
        }

        if ($predis instanceof Client) {

            return $predis;
        }

        throw new ConnectionLostException('Predis client was not instanciated correctly' . PHP_EOL, 500);
    }

    public static function clientCount(): int
    {
        return count(self::$clients);
    }
}
