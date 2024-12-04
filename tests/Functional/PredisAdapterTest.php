<?php

declare(strict_types=1);

namespace LLegaz\Predis\Tests\Functional;

use LLegaz\Predis\PredisAdapter as SUT;
use LLegaz\Predis\PredisClientsPool;

if (!defined('SKIP_FUNCTIONAL_TESTS')) {
    define('SKIP_FUNCTIONAL_TESTS', true);
}

/**
 *
 *
 * @author Laurent LEGAZ <laurent@legaz.eu>
 */
class PredisAdapterTest extends \PHPUnit\Framework\TestCase
{
    /** @var PredisAdapter */
    protected $predisAdapter;

    /** @var array */
    protected const DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'scheme' => 'tcp',
        'database' => 0,
    ];

    /**
     * @inheritdoc
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        if (SKIP_FUNCTIONAL_TESTS) {
            // don't forget that tests are deleoppers' tools (and not only an approval seal)
            $this->markTestSkipped('FUNCTIONAL TESTS are skipped by default for Units');
        }
        $this->predisAdapter = new SUT();
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        unset($this->predisAdapter);
    }

    /**
     * Functional test on the predis adapter
     *
     * do some basics checks on redis communication success
     * for some basic scenarios
     *
     * 1 - single instance
     * 2 - single instance / multi-db
     * 3 - multi-instances
     * 4 - multi-instances / multi-db
     *
     *
     * to run these test you need a redis server and docker installed
     */
    public function testPredisAdapterFunc()
    {
        $this->assertTrue($this->predisAdapter->isConnected());
    }

    public function testPredisClientSwitchDBs()
    {
        for ($i = 0; $i < 16; $i++) {
            $this->assertTrue($this->predisAdapter->selectDatabase($i));
            $this->assertEquals($i, $this->pop_helper($this->predisAdapter)['db']);
        }
    }

    /**
     * in fact this test <b>PredisClientsPool</b>
     */
    public function testSingleClientInvokationConsistency()
    {
        $cfg = self::DEFAULTS;
        $cfg['database'] = 3;
        $un = SUT::createPredisAdapter($cfg)->getPredisClientID();
        $deux = SUT::createPredisAdapter($cfg)->getPredisClientID();
        $cfg['database'] = 4;
        $test = SUT::createPredisAdapter($cfg);
        $trois = $test->getPredisClientID();
        $cfg['database'] = 3;
        $test = SUT::createPredisAdapter($cfg);
        $quatre = $test->getPredisClientID();
        $this->assertEquals($un, $deux);
        $this->assertEquals($un, $trois);
        $this->assertEquals($un, $quatre);
        $this->assertEquals($deux, $trois);
        $this->assertEquals($deux, $quatre);
        $this->assertEquals($trois, $quatre);
        $this->assertEquals(1, count($this->predisAdapter->clientList())); // 1conn
        $this->assertEquals(1, count($test->clientList())); // 1conn
        $this->assertEquals($this->predisAdapter->getPredisClientID(), $test->getPredisClientID()); // 1conn
    }

    /**
     * the same predis client is used when trying to instantiate another adapter to address another database
     */
    public function testPredisSingleClientInvokationConstistency()
    {
        $cfg = self::DEFAULTS;
        $cfg['database'] = 3;
        $test = SUT::createPredisAdapter($cfg);
        $otherClientID = $test->getPredisClientID();
        $this->assertEquals($this->predisAdapter->getPredisClientID(), $otherClientID);
        $this->assertEquals(1, count($this->predisAdapter->clientList()));
    }

    /**
     * multi clients / multi servers
     */
    public function testPredisAdapterDBsWithMultiConnections()
    {
        $cfg = self::DEFAULTS;
        $a = [];
        $a[] = $this->predisAdapter;
        $cfg['port'] = 6375;
        $cfg['password'] = 'RedisAuth1';
        $a[] = SUT::createPredisAdapter($cfg);
        $cfg['port'] = 6376;
        $cfg['password'] = 'RedisAuth2';
        $a[] = SUT::createPredisAdapter($cfg);
        $cfg['port'] = 6377;
        $cfg['password'] = 'RedisAuth3';
        $a[] = SUT::createPredisAdapter($cfg);
        for ($i = 0; $i < 16; $i++) {
            foreach ($a as $pa) {
                $this->assertTrue($pa->isConnected());
                $this->assertTrue($pa->selectDatabase($i));
                // make sure we have 1 client per client/server pair
                $this->assertEquals(1, count($pa->clientList()));
                $this->assertEquals($i, $this->pop_helper($pa)['db']);
            }
        }
        // 4 clients (pairing with 4 servers)
        $this->assertEquals(PredisClientsPool::clientCount(), count($a));
        $cfg['port'] = 6375;
        $cfg['password'] = 'RedisAuth1';
        $cfg['database'] = 3;
        $a[] = SUT::createPredisAdapter($cfg);
        $cfg['port'] = 6376;
        $cfg['password'] = 'RedisAuth2';
        $cfg['database'] = 4;
        $a[] = SUT::createPredisAdapter($cfg);
        $cfg['port'] = 6377;
        $cfg['password'] = 'RedisAuth3';
        $cfg['database'] = 9;
        $a[] = SUT::createPredisAdapter($cfg);
        // here we have dupplicates (3 new instances which weren't really instantiated)
        $this->assertNotEquals(PredisClientsPool::clientCount(), count($a));

        $final = [];
        foreach ($a as $pa) {
            $id = $pa->getPredisClientID();

            // same referenced clients SHOULD have the same final state
            if (isset($final[$id])) {
                // here real predis client are sync
                $this->assertEquals($this->pop_helper($final[$id])['db'], $this->pop_helper($pa)['db']);

                // but predisAdapter instances' contexts are not
                $this->assertNotEquals(($final[$id])->getContext(), $pa->getContext());

                /**
                 * j'avoue là on se tire les cheveux inutilement..
                 */
                $this->assertTrue(($final[$id])->checkDatabase());
                $this->assertEquals(($final[$id])->getContext()['database'], $this->pop_helper($final[$id])['db']);
                $this->assertTrue($pa->checkDatabase());
                $this->assertEquals($pa->getContext()['database'], $this->pop_helper($pa)['db']);

                // all starting connections were made on db0 (default)
                $this->assertEquals(0, ($final[$id])->getRedis()->getConnection()->getParameters()->toArray()['database']);
                $this->assertEquals(0, $pa->getRedis()->getConnection()->getParameters()->toArray()['database']);
            } else {
                $final[$id] = clone $pa;
            }
        }
        // dupplicates were eliminated
        $this->assertEquals(PredisClientsPool::clientCount(), count($final));
    }

    /**
     * in fact this test <b>PredisClientsPool</b>
     */
    public function testMultipleClientsInvokationConsistency()
    {
        $un = $this->predisAdapter->getPredisClientID();

        $test = new SUT('127.0.0.1', 6375, 'RedisAuth1');
        $deux = $test->getPredisClientID();

        $test2 = new SUT('127.0.0.1', 6376, 'RedisAuth2');
        $trois = $test2->getPredisClientID();

        $test3 = new SUT('127.0.0.1', 6377, 'RedisAuth3');
        $quatre = $test3->getPredisClientID();

        $this->assertTrue($test->isConnected());
        $this->assertTrue($test2->isConnected());
        $this->assertTrue($test3->isConnected());


        $this->assertEquals(PredisClientsPool::clientCount(), 4);

        $this->assertNotEquals($un, $deux);
        $this->assertNotEquals($un, $trois);
        $this->assertNotEquals($un, $quatre);
        $this->assertNotEquals($deux, $trois);
        $this->assertNotEquals($deux, $quatre);
        $this->assertNotEquals($trois, $quatre);
        $i = 3;
        while ($i > 0) {
            $testAgain = new SUT('127.0.0.1', 6374 + $i, 'RedisAuth' . $i);
            $this->assertTrue($testAgain->isConnected());
            $this->assertEquals(PredisClientsPool::clientCount(), 4);
            if ($i === 1) {
                $i = '';
            }
            $this->assertEquals(${"test$i"}->getPredisClientID(), $testAgain->getPredisClientID());
            $i--;
        }
    }
    /**
     * to test :
     *
     * - exception on password
     * - exception on server unreachable
     *
     *//*
    public function testClientInvokationAuthException()
    {
        try {
            $test = new SUT('127.0.0.1', 6375, 'wrong password');
        } catch (Exception $ex) {
            dump($ex);

        }

    }*/


    private function pop_helper(SUT $pa)
    {
        $client_list = $pa->clientList();

        return array_pop($client_list);
    }
}
