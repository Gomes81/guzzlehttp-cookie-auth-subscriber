<?php

namespace gomes81\GuzzleHttp\Tests\CookieAuth;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Cookie\SetCookie;
use gomes81\GuzzleHttp\Subscriber\CookieAuth;
use GuzzleHttp\Cookie\CookieJar;

class CookieAuthTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \stdClass
     */
    private $config;

    /**
     * @var CookieAuth
     */
    private $configuredInstance = null;


    protected function setUp(): void
    {
        $cookieExpiration = new \Datetime('+2 hours');
        $cookieExpiration->setTimezone(new \DateTimeZone('GMT'));
        $this->config = (object) [
            'uri'     => 'http://httpbin.org/get',
            'fields'  => (object) [
                'user'     => 'John Doe',
                'password' => 'pass'
            ],
            'method'  => 'POST',
            'cookie'  => (object) [
                'Name'    => 'sessionToken',
                'Value'   => 'abc123',
                'Expires' => $cookieExpiration,
                'parts' => [
                    // 'sessionToken=abc123',
                    'Domain=.httpbin.org',
                    'Path=/',
                    'Expires=' . $cookieExpiration->format('D, d M Y H:i:s e'),
                ],
                'string' => '',
                'data'   => null,
            ],
        ];

        array_unshift($this->config->cookie->parts, sprintf('%s=%s', $this->config->cookie->Name, $this->config->cookie->Value));
        $this->config->cookie->string = implode('; ', $this->config->cookie->parts);
        $this->config->cookie->data = SetCookie::fromString($this->config->cookie->string);

        $this->configuredInstance = $this->getNewInstance($this->config->cookie->string);
    }

    public function testAcceptsConfiguration()
    {
        $possibilities = [
            new CookieJar(),
            null, // empt
            $this->config->cookie->string, // strin
            [$this->config->cookie->string], // string[
            SetCookie::fromString($this->config->cookie->string), // SetCooki
            [SetCookie::fromString($this->config->cookie->string)], // SetCookie[]
        ];
        $possibilities[0]->setCookie(SetCookie::fromString($this->config->cookie->string));

        foreach ($possibilities as $cookieData) {
            $this->assertConfiguration($cookieData);
        }
    }

    public function testCookieInjection()
    {
        $stack = HandlerStack::create();
        $stack->push($this->configuredInstance);

        $container = [];
        $history   = Middleware::history($container);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack
        ]);

        $client->post(
            'http://httpbin.org/post',
            [
                'auth' => 'cookie',
                'form_params' => [
                    'foo' => [
                        'baz' => ['bar'],
                        'bam' => [null, true, false]
                    ]
                ]
            ]
        );

        /** @var \GuzzleHttp\Psr7\Request */
        $request = $container[0]['request'];

        $this->assertTrue($request->hasHeader('Cookie'));

        $cookieHeader = $request->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(
            $this->getCookieNameValuePair(),
            $cookieHeader
        );
    }

    public function testOnBeforeSend()
    {
        // mock request
        $rq = new Request('GET', $this->config->uri);

        // Access protected fuction
        $class  = new \ReflectionClass($this->configuredInstance);
        $method = $class->getMethod('onBeforeSend');
        $method->setAccessible(true);

        $options = [];
        $result  = $method->invokeArgs($this->configuredInstance, [$rq, &$options]);

        $this->assertInstanceOf(
            \Psr\Http\Message\RequestInterface::class,
            $result
        );
        $this->assertTrue($result->hasHeader('Cookie'));

        $cookieHeader = $result->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(
            $this->getCookieNameValuePair(),
            $cookieHeader
        );
    }

    public function testOnReceive()
    {
        // mock request
        $request = new Request('GET', $this->config->uri);

        // mock response
        $response = new Response(
            200,
            [
                'Cookie' => $this->config->cookie->data->__toString()
            ],
            null
        );

        // middleware
        $m = new CookieAuth(
            $this->config->uri,
            $this->config->fields,
            $this->config->method
        );

        $result = $m->onReceive($request, $response);

        $this->assertInstanceOf(
            \Psr\Http\Message\ResponseInterface::class,
            $result
        );
        $this->assertTrue($result->hasHeader('Cookie'));

        $cookieHeader = $result->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals($this->config->cookie->string, $cookieHeader);

        $cookieJar = $this->getCookieJarProperty();
        $this->assertInstanceOf(CookieJar::class, $cookieJar);

        $cookieArr = $cookieJar->toArray();
        $cookie    = reset($cookieArr);

        $this->assertArrayHasKey('Name', $cookie);
        $this->assertArrayHasKey('Value', $cookie);
        $this->assertEquals($cookie['Name'], $this->config->cookie->Name);
        $this->assertEquals($cookie['Value'], $this->config->cookie->Value);
    }

    public function testInvoke()
    {
        // mock request
        $request = new Request('GET', $this->config->uri);

        // middleware
        $m = $this->configuredInstance;

        /** @var \GuzzleHttp\Psr7\Request */
        $newRequest = null;
        $callable   = $m(function ($request, $options) use (&$newRequest) {
            $newRequest = $request;
            return new Promise();
        });

        $this->assertTrue(is_callable($callable));

        $options = [];
        $callable($request, $options);

        $this->assertFalse($newRequest->hasHeader('Cookie'));


        $options = ['auth' => 'cookie'];
        $callable($request, $options);

        $this->assertTrue($newRequest->hasHeader('Cookie'));

        $cookieHeader = $newRequest->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(
            $this->getCookieNameValuePair(),
            $cookieHeader
        );
    }

    public function testObtainCookies()
    {
        $uri       = 'http://httpbin.org/cookies/set';
        $urlMethod = 'GET';
        $fields    = [
            'username'     => 'user',
            'password'     => 'pass',
            'sessionToken' => 'abc123',
        ];
        $options = [];

        // middleware
        $m = new CookieAuth($uri, $fields, $urlMethod);

        // Access protected fuction
        $class  = new \ReflectionClass($m);
        $method = $class->getMethod('obtainCookies');
        $method->setAccessible(true);

        $method->invokeArgs($m, [&$options]);

        $cookieJar = $this->getCookieJarProperty($m);
        $this->assertInstanceOf(CookieJar::class, $cookieJar);

        $cookieArr = $cookieJar->toArray();

        $cookie     = reset($cookieArr);
        $fieldValue = reset($fields);
        do {
            $this->assertArrayHasKey('Name', $cookie);
            $this->assertArrayHasKey('Value', $cookie);
            $this->assertEquals($fieldValue, $cookie['Value']);
        } while (
            ($fieldValue = next($fields)) &&
            ($cookie     = next($cookieArr))
        );
    }

    /**
     * @param CookieAuth $instance
     * @return CookieJar
     */
    private function getCookieJarProperty($instance = null)
    {
        $instance = !empty($instance) ? $instance : $this->configuredInstance;
        $reflection = new \ReflectionClass($instance);

        $property = $reflection->getProperty('cookieJar');
        $property->setAccessible(true);

        return $property->getValue($instance);
    }

    /**
     * @return string
     */
    private function getCookieNameValuePair()
    {
        return $this->config->cookie->parts[0];
    }

    /**
     * @param \Traversable|string|string[]|SetCookie|SetCookie[]|CookieJarInterface|null $cookies
     * @return CookieAuth
     */
    private function getNewInstance($cookies = null): CookieAuth
    {
        return new CookieAuth(
            $this->config->uri,
            $this->config->fields,
            $this->config->method,
            $cookies
        );
    }

    /**
     * @param \Traversable|string|string[]|SetCookie|SetCookie[]|CookieJarInterface|null $cookieData
     * @return void
     */
    private function assertConfiguration($cookieData)
    {
        $instance = $this->getNewInstance($cookieData);

        // Access the config object
        $class    = new \ReflectionClass($instance);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config   = $property->getValue($instance);

        $cookieJar = $this->getCookieJarProperty($instance);

        $expectedCookie = is_null($cookieData) ? [] : [$this->config->cookie->data->toArray()];

        $this->assertEquals($this->config->uri, $config['uri']);
        $this->assertEquals($this->config->fields, $config['fields']);
        $this->assertEquals($this->config->method, $config['method']);
        $this->assertInstanceOf(CookieJar::class, $cookieJar);
        $this->assertEquals($expectedCookie, $cookieJar->toArray());
    }
}
