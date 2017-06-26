=======================
Guzzle CookieAuth Subscriber
=======================

Signs HTTP requests using cookies. Requests are signed using a login form, containing a username and password (and / or any other fields you wish to include).
The credentials are then send to the provided url using the specified HTTP method (usually POST) and the returned cookies are saved for later use.

This version only works with Guzzle 6.0 and up!

Installing
==========

This project can be installed using Composer. Add the following to your
composer.json:

.. code-block:: javascript

    {
        "require": {
            "gomes81/guzzlehttp-cookie-auth-subscriber": "0.1.*"
        }
    }



Using the Subscriber
====================

Here's an example showing how to send an authenticated request:

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;
    use gomes81\GuzzleHttp\Subscriber\CookieAuth;

    $stack = HandlerStack::create();

    $middleware = new CookieAuth(
        '/login_simple_url_or_path',
        [
            'username'    => 'my_username',
            'password'    => 'my_password',
            'other_field' => 'my_field_value'
        ],
        'POST',// GET, POST or JSON (will send a POST request with fields as a JSON encoded string)
        'you can also pass a cookie string to use in here');
    $stack->push($middleware);

    $client = new Client([
        'base_uri' => 'http://simple_url.com',
        'handler'  => $stack
    ]);

    // Set the "auth" request option to "cookie" to sign the request using a cookie
    // Before calling the given url the subscriber will check if there's a valid cookie
    // to be injected in the current request, if a valid cookie could not be found an
    // additional request is made to obtain it
    $res = $client->get('statuses/home_timeline.json', ['auth' => 'cookie']);

You can set the ``auth`` request option to ``cookie`` for all requests sent by
the client by extending the array you feed to ``new Client`` with auth => cookie.

.. code-block:: php

    use GuzzleHttp\Client;
    use GuzzleHttp\HandlerStack;
    use gomes81\GuzzleHttp\Subscriber\CookieAuth;

    $stack = HandlerStack::create();

    $middleware = new CookieAuth(
        '/login_simple_url_or_path', [
            'username'    => 'my_username',
            'password'    => 'my_password',
            'other_field' => 'my_field_value'],
        'POST',// GET, POST or JSON (will send a POST request with fields as a JSON encoded string)
        'you can also pass a cookie string to use in here');
    $stack->push($middleware);

    $client = new Client([
        'base_uri' => 'http://simple_url.com',
        'handler'  => $stack,
        'auth'     => 'cookie'
    ]);

    // Now you don't need to add the auth parameter
    $res = $client->get('statuses/home_timeline.json');
