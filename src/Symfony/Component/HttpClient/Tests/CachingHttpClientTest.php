<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Test\HttpClientTestCase;

class TestCachingHttpClient extends CachingHttpClient
{
    protected $client;

    public function __construct(StoreInterface $storeInterface, $testCase)
    {
        parent::__construct(new NativeHttpClient(), $storeInterface);

        $headers = [
            'Host: localhost:8057',
            'Content-Type: application/json',
        ];

        $body = '{
            "SERVER_PROTOCOL": "HTTP/1.1",
            "SERVER_NAME": "127.0.0.1",
            "REQUEST_URI": "/",
            "REQUEST_METHOD": "GET",
            "HTTP_FOO": "baR",
            "HTTP_HOST": "localhost:8057"
        }';

        $this->client = new MockHttpClient(function (string $method, string $url, array $options) use ($headers, $body, $testCase) {

            switch ($testCase) {
                default:
                    // force the request to be completed so that we don't test side effects of the transport
                    $response = $this->request($method, $url, $options);
                    $content = $response->getContent(false);

                    return new MockResponse($content, $response->getInfo());

                case 'testGetRequest':
                    array_unshift($headers, 'HTTP/1.1 200 OK');

                    if (preg_match('/length-broken$/', $url)){
                        $headers = [
                            'Host: localhost:8057',
                            'Content-Length: 1000',
                            'Content-Type: application/json',
                        ];

                        return new MockResponse($body, ['raw_headers' => $headers]);
                    }

                    return new MockResponse($body, ['raw_headers' => $headers]);
//
//                case 'testDnsError':
//                    $mock = $this->getMockBuilder(ResponseInterface::class)->getMock();
//                    $mock->expects($this->any())
//                        ->method('getStatusCode')
//                        ->willThrowException(new TransportException('DSN error'));
//                    $mock->expects($this->any())
//                        ->method('getInfo')
//                        ->willReturn([]);
//
//                    $responses[] = $mock;
//                    $responses[] = $mock;
//                    break;
//
//                case 'testBadRequestBody':
//                case 'testOnProgressCancel':
//                case 'testOnProgressError':
//                    $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                    break;
//
//                case 'testTimeoutOnAccess':
//                    $mock = $this->getMockBuilder(ResponseInterface::class)->getMock();
//                    $mock->expects($this->any())
//                        ->method('getHeaders')
//                        ->willThrowException(new TransportException('Timeout'));
//
//                    $responses[] = $mock;
//                    break;
//
//                case 'testResolve':
//                    $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                    $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                    $responses[] = $client->request('GET', 'http://symfony.com:8057/');
//                    break;
//
//                case 'testTimeoutOnStream':
//                case 'testUncheckedTimeoutThrows':
//                    $body = ['<1>', '', '<2>'];
//                    $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                    break;
            }
        });
    }
}

class CachingHttpClientTest extends HttpClientTestCase
{
    protected function getHttpClient(string $testCase): HttpClientInterface
    {
        $headers = [
            'Host: localhost:8057',
            'Content-Type: application/json',
        ];

        $body = '{
            "SERVER_PROTOCOL": "HTTP/1.1",
            "SERVER_NAME": "127.0.0.1",
            "REQUEST_URI": "/",
            "REQUEST_METHOD": "GET",
            "HTTP_FOO": "baR",
            "HTTP_HOST": "localhost:8057"
        }';

//        switch ($testCase) {
//            default:
//                return new MockHttpClient(function(string $method, string $url, array $options) {
//                    // force the request to be completed so that we don't test side effects of the transport
//                    $response = $this->request($method, $url, $options);
//                    $content = $response->getContent(false);
//
//                    return new MockResponse($content, $response->getInfo());
//                });
//
//            case 'testBadRequestBody':
//            case 'testOnProgressCancel':
//            case 'testOnProgressError':
//                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                break;
//
//            case 'testTimeoutOnAccess':
//                $mock = $this->getMockBuilder(ResponseInterface::class)->getMock();
//                $mock->expects($this->any())
//                    ->method('getHeaders')
//                    ->willThrowException(new TransportException('Timeout'));
//
//                $responses[] = $mock;
//                break;
//
//            case 'testResolve':
//                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                $responses[] = $client->request('GET', 'http://symfony.com:8057/');
//                break;
//
//            case 'testTimeoutOnStream':
//            case 'testUncheckedTimeoutThrows':
//                $body = ['<1>', '', '<2>'];
//                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
//                break;
//        }

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($headers, $body, $testCase) {
            switch ($testCase) {
                case 'testGetRequest':
                    if (preg_match('/length-broken$/', $url)){
                        $headers = [
                            'Host: localhost:8057',
                            'Content-Length: 1000',
                            'Content-Type: application/json',
                        ];
                    }

                    array_unshift($headers, 'HTTP/1.1 200 OK');
                    return new MockResponse($body, ['raw_headers' => $headers]);

                case 'testDnsError':
                    $mock = $this->getMockBuilder(ResponseInterface::class)->getMock();
                    $mock->expects($this->any())
                        ->method('getStatusCode')
                        ->willThrowException(new TransportException('DSN error'));
                    $mock->expects($this->any())
                        ->method('getInfo')
                        ->willReturn([]);

                    return $mock;
                    break;

                case 'testBadRequestBody':
                case 'testOnProgressCancel':
                case 'testOnProgressError':
                    return new MockResponse($body, ['raw_headers' => $headers]);

                case 'testTimeoutOnAccess':
                    $mock = $this->getMockBuilder(ResponseInterface::class)->getMock();
                    $mock->expects($this->any())
                        ->method('getHeaders')
                        ->willThrowException(new TransportException('Timeout'));

                    return $mock;

    //            case 'testResolve':
    //                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
    //                $responses[] = new MockResponse($body, ['raw_headers' => $headers]);
    //                $responses[] = $client->request('GET', 'http://symfony.com:8057/');
    //                break;

                case 'testTimeoutOnStream':
                case 'testUncheckedTimeoutThrows':
                    $body = ['<1>', '', '<2>'];
                    return new MockResponse($body, ['raw_headers' => $headers]);
                default:
                    return new MockResponse();
            }
        });

        $storeInterface = $this->createMock(StoreInterface::class);

        return new CachingHttpClient($client, $storeInterface);
    }
}
