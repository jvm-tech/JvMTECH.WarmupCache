<?php

namespace JvMTECH\WarmupCache\Job;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;

class UrlRequestJob implements JobInterface {
    protected $url;

    /**
     * @Flow\InjectConfiguration(path="basicauth")
     * @var array
     */
    protected $basicauth;

    public function __construct($url) {
        $this->url = $url;
    }

    protected function _getClientConfig():array
    {
        $clientConfig = [];
        if ($this->basicauth && !empty($this->basicauth['login']) && !empty($this->basicauth['pass']))
            $clientConfig['auth'] = [$this->basicauth['login'], $this->basicauth['pass']];
        return $clientConfig;
    }

    /**
     * Execute the indexing of nodes
     *
     * @param QueueInterface $queue
     * @param Message $message The original message
     * @return boolean TRUE if the job was executed successfully and the message should be finished
     * @throws \Exception
     */
    public function execute(QueueInterface $queue, Message $message): bool
    {
        $client = new Client($this->_getClientConfig());

        $response = $client->request('GET', $this->url, [
            'on_stats' => function (TransferStats $stats) {
                $requestTime = $stats->getTransferTime();


                if ($stats->hasResponse()) {
                    if ($stats->getResponse()->getStatusCode() === 200) {
                        $httpCode = $stats->getResponse()->getStatusCode();
                        echo "{$httpCode} {$this->url} - {$requestTime}s\n";
                        return true;
                    } else {
                        $httpCode = $stats->getResponse()->getStatusCode();
                        echo "{$httpCode} {$this->url}\n";
                        return false;
                    }
                } else {
                    var_dump($stats->getHandlerErrorData());
                    return false;
                }
            }
        ]);

//        Not needed as concurrency is handled from CLI execution.

//        $pool = new Pool($client, $requests(), [
//            'concurrency' => 5,
//            'fulfilled' => function ($response, $index) {
////                var_dump('fulfilled: ' . $this->url);
//                // this is delivered each successful response
//            },
//            'rejected' => function ($reason, $index) {
////                var_dump('rejected: ' . $this->url . ' ' . $reason);
//                // this is delivered each failed request
//                return false;
//            },
////            'options' => [
////                // The 'on_stats' option is provided here in the 'options' array
////                'on_stats' => function (TransferStats $stats) use (&$requestDurations) {
////                    $uri = $stats->getRequest()->getUri();
////                    $requestTime = $stats->getTransferTime();
////                    var_dump("${$uri}: " . $requestTime . "s\n");
////                    die('xxx');
////                }
////            ]
//        ]);
//        $promise = $pool->promise();
//        $promise->wait();
        return true;
    }

    public function getLabel(): string {
        return sprintf('UrlRequest (%s)', $this->url);
    }
}
