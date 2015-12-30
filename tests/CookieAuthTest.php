<?php

namespace x3mcode\GuzzleHttp\Tests\CookieAuth;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Cookie\SetCookie;
use x3mcode\GuzzleHttp\Subscriber\CookieAuth;

class CookieAuthTest extends \PHPUnit_Framework_TestCase
{
    private $config = [
        'uri' => 'http://httpbin.org/get',
        'fields' => array('user' => 'John Doe', 'password' => 'pass'),
        'method' => 'POST',
        'cookies' => 'sessionToken=abc123; Domain=.httpbin.org; Path=/; Expires=Wed, 09 Jun 2021 10:18:14 GMT'
    ];

    public function testAcceptsConfigurationData()
    {
        $p = new CookieAuth($this->config['uri'], $this->config['fields'],
            $this->config['method'], $this->config['cookies']);

        // Access the config object
        $class    = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config   = $property->getValue($p);

        $property2      = $class->getProperty('coockieJar');
        $property2->setAccessible(true);
        $coockieJar     = $property2->getValue($p);
        $expectedCookie = array(SetCookie::fromString($this->config['cookies'])->toArray());

        $this->assertEquals($this->config['uri'], $config['uri']);
        $this->assertEquals($this->config['fields'], $config['fields']);
        $this->assertEquals($this->config['method'], $config['method']);
        $this->assertInstanceOf('\\GuzzleHttp\\Cookie\\CookieJar', $coockieJar);
        $this->assertEquals($expectedCookie, $coockieJar->toArray());
    }

    public function testCookieInjection()
    {
        $stack = HandlerStack::create();

        $middleware = new CookieAuth($this->config['uri'],
            $this->config['fields'], $this->config['method'],
            $this->config['cookies']);
        $stack->push($middleware);

        $container = [];
        $history   = Middleware::history($container);
        $stack->push($history);

        $client = new Client([
            'handler' => $stack
        ]);

        $client->post('http://httpbin.org/post',
            [
            'auth' => 'cookie',
            'form_params' => [
                'foo' => [
                    'baz' => ['bar'],
                    'bam' => [null, true, false]
                ]
            ]
        ]);

        /* @var \GuzzleHttp\Psr7\Request $request */
        $request = $container[0]['request'];
        //$request instanceof \GuzzleHttp\Psr7\Request;

        $this->assertTrue($request->hasHeader('Cookie'));

        $cookieHeader = $request->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(substr($this->config['cookies'], 0, 19),
            $cookieHeader);
    }

    public function testOnBeforeSend()
    {
        // mock request
        $rq = new Request('GET', $this->config['uri']);

        // middleware
        $m = new CookieAuth($this->config['uri'], $this->config['fields'],
            $this->config['method'], $this->config['cookies']);

        // Access protected fuction
        $class  = new \ReflectionClass($m);
        $method = $class->getMethod('onBeforeSend');
        $method->setAccessible(true);

        $options = array();
        $result  = $method->invokeArgs($m, array($rq, &$options));

        $this->assertInstanceOf('\\Psr\\Http\\Message\\RequestInterface',
            $result);
        $this->assertTrue($result->hasHeader('Cookie'));

        $cookieHeader = $result->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(substr($this->config['cookies'], 0, 19),
            $cookieHeader);
    }

    public function testOnReceive()
    {
        // mock request
        $request = new Request('GET', $this->config['uri']);

        // mock response
        $response = new Response(200,
            [
            'Cookie' => SetCookie::fromString($this->config['cookies'])
            ], null);

        // middleware
        $m = new CookieAuth($this->config['uri'], $this->config['fields'],
            $this->config['method']);

        $result = $m->onReceive($request, $response);

        $this->assertInstanceOf('\\Psr\\Http\\Message\\ResponseInterface',
            $result);
        $this->assertTrue($result->hasHeader('Cookie'));

        $cookieHeader = $result->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals($this->config['cookies'], $cookieHeader);
    }

    public function testInvoke()
    {
        // mock request
        $request = new Request('GET', $this->config['uri']);

        // middleware
        $m = new CookieAuth($this->config['uri'], $this->config['fields'],
            $this->config['method'], $this->config['cookies']);

        $newRequest = null;
        $callable   = $m(function($request, $options) use (&$newRequest) {
            $newRequest = $request;
            return new Promise();
        });

        $this->assertTrue(is_callable($callable));

        $options = array();
        $result  = $callable($request, $options);

        $this->assertFalse($newRequest->hasHeader('Cookie'));


        $options = array('auth' => 'cookie');
        $result  = $callable($request, $options);

        $this->assertTrue($newRequest->hasHeader('Cookie'));

        $cookieHeader = $newRequest->getHeader('Cookie');
        $cookieHeader = $cookieHeader[0];

        $this->assertEquals(substr($this->config['cookies'], 0, 19),
            $cookieHeader);
    }

    public function testObtainCookies()
    {
        $uri       = 'http://httpbin.org/cookies/set';
        $urlMethod = 'GET';
        $fields    = array(
            'username' => 'user',
            'password' => 'pass',
            'sessionToken' => 'abc123',
        );

        // middleware
        $m = new CookieAuth($uri, $fields, $urlMethod);

        // Access protected fuction
        $class  = new \ReflectionClass($m);
        $method = $class->getMethod('obtainCookies');
        $method->setAccessible(true);

        $method->invokeArgs($m, array());

        $property  = $class->getProperty('coockieJar');
        $property->setAccessible(true);
        $cookieJar = $property->getValue($m);

        $cookieArr = $cookieJar->toArray();

        $cookie     = reset($cookieArr);
        $fieldValue = reset($fields);
        do {
            $this->assertArrayHasKey('Name', $cookie);
            $this->assertArrayHasKey('Value', $cookie);
            $this->assertEquals($fieldValue, $cookie['Value']);
        } while (($fieldValue = next($fields)) && ($cookie     = next($cookieArr)));
    }
}
