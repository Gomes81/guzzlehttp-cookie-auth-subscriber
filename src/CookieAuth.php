<?php

namespace gomes81\GuzzleHttp\Subscriber;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

/**
 * Cookie auth signature plugin.
 * This version only works with Guzzle 6.0 and up!
 *
 * Inspiration for this code comes from guzzle/oauth-subscriber plugin by Michael Dowling
 * @author João Gomes <x3mcode@gmail.com>
 * @link https://github.com/guzzle/guzzle Guzzle
 */
class CookieAuth
{
    /**
     * Configuration settings
     *
     * @var array
     */
    protected $config;

    /**
     * Jar that holds the collected cookies
     *
     * @var CookieJar
     */
    protected $coockieJar;

    /**
     * Create a new CookieAuth plugin.
     *
     * @param string $uri Login url
     * @param array|\Traversable $fields Array containing all fields that should
     * be send upon login, where the key is the field name and the value is the
     * field value
     * @param string $method Resquest method POST or GET
     * @param string|array|\Traversable|CookieJar $cookies
     */
    public function __construct($uri, $fields, $method = 'POST', $cookies = null)
    {
        if ($cookies instanceof CookieJar) {
            $this->coockieJar = $cookies;
        } else {
            $this->coockieJar = new CookieJar();

            if (!is_null($cookies)) {
                if (is_string($cookies)) {
                    $this->coockieJar->setCookie(
                        SetCookie::fromString($cookies));
                } else if (is_array($cookies) || $cookies instanceof \Traversable) {
                    foreach ($cookies as $cookie) {
                        if (is_string($cookie)) {
                            $this->coockieJar->setCookie(SetCookie::fromString($cookie));
                        } else if (is_array($cookie)) {
                            $this->coockieJar->setCookie(new SetCookie($cookie));
                        }
                    }
                }
            }
        }

        $this->config = [
            'uri' => $uri,
            'method' => $method,
            'fields' => $fields
        ];
    }

    /**
     * Called when the middleware is handled.
     *
     * @param callable $handler
     *
     * @return \Closure
     */
    public function __invoke(callable $handler)
    {
        return function ($request, array $options) use ($handler) {
            if (isset($options['auth']) && $options['auth'] == 'cookie') {
                // do some magic :)
                $request = $this->onBeforeSend($request, $options);
                return $handler($request, $options)
                        ->then(function ($response) use ($request) {
                            return $this->onReceive($request, $response);
                        });
            }

            return $handler($request, $options);
        };
    }

    /**
     * Inject the cookie in the request
     * 
     * @param RequestInterface $request The request
     * @param array $options Guzzle options array
     * @return RequestInterface The changed request
     */
    private function onBeforeSend(RequestInterface $request, array &$options)
    {
        $base_uri = null;
        if (isset($options['base_uri']) && $options['base_uri']) {
            if ($options['base_uri'] instanceof UriInterface) {
                $base_uri = $options['base_uri'];
            }
        }

        $cookieJar = $this->getCookieJar($base_uri);
        return $cookieJar->withCookieHeader($request);
    }

    /**
     * Update the cookie jar with the cookie received from the server
     * 
     * @param RequestInterface $request Request object
     * @param ResponseInterface $response Response object
     * @return ResponseInterface Response object
     */
    public function onReceive(RequestInterface $request,
                              ResponseInterface $response)
    {
        $this->coockieJar->extractCookies($request, $response);
        return $response;
    }

    /**
     * Get cookie jar, hidratates one if it is empty
     *
     * @param string|UriInterface $base_uri Optional base uri
     * @return CookieJar The cookie jar
     */
    public function getCookieJar($base_uri = null)
    {
        if ($this->coockieJar->count() <= 0) {
            $this->obtainCookies($base_uri);
        }

        return $this->coockieJar;
    }

    /**
     * Hydrates a cookie jar.
     * Do not invoke this function if youd don't know what you're doing
     *
     * @param string|UriInterface $base_uri Optional base uri
     * @return void it only chnages the class var
     */
    protected function obtainCookies($base_uri = null)
    {
        $client = new Client();

        $options = [
            'allow_redirects' => false,
            'cookies' => $this->coockieJar
        ];

        if (!is_null($base_uri)) {
            $options['base_uri'] = $base_uri;
        }

        if ($this->config['method'] === 'POST') {
            $options['form_params'] = $this->config['fields'];
        } else if ($this->config['method'] === 'GET') {
            $options['query'] = $this->config['fields'];
        }

        $client->request($this->config['method'], $this->config['uri'], $options);
    }
}
