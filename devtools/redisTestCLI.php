<?php
require_once '../vendor/autoload.php';

function benchmark(callable $callback, array $params = []): string
{
    $startTime = microtime(true);
    call_user_func_array($callback, $params);
    $endTime = microtime(true);

    return sprintf('%.9f seconds', $endTime - $startTime);
}

$pa = new \LLegaz\Predis\PredisAdapter();
dump($pa->getRedis()->getConnection()->getParameters());
dump($pa->getRedis()->ping());
dump(new \Predis\Response\Status('PONG'));

dump(benchmark([$pa, 'selectDatabase'], [3]));
dump(benchmark([$pa, 'selectDatabase'], [1]));

