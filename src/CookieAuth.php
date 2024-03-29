<?php

namespace Gomes81\GuzzleHttp\Subscriber;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
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
     * @var CookieJarInterface
     */
    protected $cookieJar;

    /**
     * Flag that tells if the login was successfully made
     *
     * @var boolean
     */
    protected $loginMade = false;


    /**
     * Create a new CookieAuth plugin.
     *
     * @param string $uri Login url
     * @param array|\Traversable $fields Array containing all fields that should
     * be send upon login, where the key is the field name and the value is the
     * field value
     * @param string $method Request method POST or GET
     * @param \Traversable|string|string[]|SetCookie|SetCookie[]|CookieJarInterface|null $cookies
     */
    public function __construct($uri, $fields, $method = 'POST', $cookies = null)
    {
        if ($cookies instanceof CookieJarInterface) {
            $this->cookieJar = $cookies;
        } else {
            $this->cookieJar = new CookieJar();
            $this->parseCookieData($this->cookieJar, $cookies);

            if ($this->cookieJar->count() > 0) {
                $this->loginMade = true;
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
     * Update the cookie jar with the cookie received from the server
     *
     * @param RequestInterface $request Request object
     * @param ResponseInterface $response Response object
     * @return ResponseInterface Response object
     */
    public function onReceive(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->cookieJar->extractCookies($request, $response);
        return $response;
    }

    /**
     * Get cookie jar, hydrates one if it is empty
     *
     * @param string|UriInterface $base_uri Optional base uri
     * @return CookieJar The cookie jar
     */
    public function getCookieJar(array &$options, $base_uri = null)
    {
        if ($this->cookieJar->count() <= 0 || !$this->loginMade) {
            $this->obtainCookies($options, $base_uri);
        }

        if (isset($options['auth-cookie'])) {
            unset($options['auth-cookie']);
        }

        return $this->cookieJar;
    }

    /**
     * Inject the cookie in the request
     *
     * @param RequestInterface $request The request
     * @param array $options Guzzle options array
     * @return RequestInterface The changed request
     */
    protected function onBeforeSend(RequestInterface $request, array &$options)
    {
        $base_uri = null;
        if (!empty($options['base_uri']) && $options['base_uri'] instanceof UriInterface) {
            $base_uri = $options['base_uri'];
        }

        return $this->getCookieJar($options, $base_uri)->withCookieHeader($request);
    }

    /**
     * Hydrates a cookie jar.
     * Do not invoke this function if you don't know what you're doing
     *
     * @param string|UriInterface $base_uri Optional base URI
     * @return void it only changes the class variable
     */
    protected function obtainCookies(array &$options, $base_uri = null)
    {
        $client = new Client();

        $loginOptions = [
            'debug'           => isset($options['debug']) && $options['debug'] ? true : false,
            'allow_redirects' => false,
            'cookies'         => $this->cookieJar
        ];

        if (!is_null($base_uri)) {
            $loginOptions['base_uri'] = $base_uri;
        }

        $method = 'GET';
        if ($this->config['method'] === 'POST') {
            $loginOptions['form_params'] = $this->config['fields'];
            $method                      = 'POST';
        } elseif ($this->config['method'] === 'JSON') {
            $loginOptions['body']                    = json_encode($this->config['fields']);
            $loginOptions['headers']['Content-Type'] = 'application/json';
            $method                                  = 'POST';
        } elseif ($this->config['method'] === 'GET') {
            $loginOptions['query'] = !is_array($this->config['fields']) ? (array) $this->config['fields']
                : $this->config['fields'];
        }

        if (isset($options['auth-cookie']['onBeforeLogin'])) {
            $closer       = $options['auth-cookie']['onBeforeLogin'];
            $loginOptions = $closer($loginOptions);
            if (false === $loginOptions) {
                return;
            }
        }

        $client->request($method, $this->config['uri'], $loginOptions);

        $this->loginMade = true;
    }

    /**
     * Parse cookie data and save in the container
     *
     * @param CookieJarInterface                                 $container  Cookie container
     * @param \Traversable|string|string[]|SetCookie|SetCookie[] $cookies    Mixed cookie data
     * @return CookieJarInterface
     */
    protected function parseCookieData(CookieJarInterface $container, $cookies): CookieJarInterface
    {
        if ($cookies instanceof SetCookie) {
            $container->setCookie($cookies);
        } elseif (is_string($cookies)) {
            $container->setCookie(
                SetCookie::fromString($cookies)
            );
        } elseif (is_array($cookies) || $cookies instanceof \Traversable) {
            foreach ($cookies as $cookie) {
                $this->parseCookieData($container, $cookie);
            }
        }

        return $container;
    }
}
