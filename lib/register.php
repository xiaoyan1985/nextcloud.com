<?php

require_once realpath(dirname(__FILE__)) . '/../vendor/autoload.php';

add_action('rest_api_init', 'registration_register_routes');

$redis = new Predis\Client();

// Get proper ip in case of reverse proxy
function whatismyip()
{
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else if (isset($_SERVER['HTTP_X_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    } else if (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } else if (isset($_SERVER['HTTP_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    } else if (isset($_SERVER['REMOTE_ADDR'])) {
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    } else {
        $ipaddress = null;
    }

    return $ipaddress;
}

function registration_register_routes()
{
    register_rest_route('register', '/account', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'request_account',
        'args' => array(
            'id' => array(
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
            'email' => array(
                'validate_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_EMAIL);
                },
            ),
        ),
    ));
    register_rest_route('register', '/providers', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'get_providers_list',
    ));
}

function request_account($request)
{

    // redis rate limit
    global $redis;
    $limit = [
        'interval' => 3660, // seconds
        'num_requests' => 5, // number of requests allowed per interval
        'user_ip' => whatismyip(), // getting the user IP.
    ];

    $rateId = "requests_count_{$limit['user_ip']}";
    $rateLimit = (int) $redis->get($rateId);
    if ($rateLimit + 1 > $limit['num_requests']) {
        return new WP_Error('rate_limit_exceeded', 'Too many requests', array('status' => 429));
    }

    $request = json_decode($request->get_body(), true);

    // verify data
    if (!array_key_exists('email', $request) || !array_key_exists('id', $request)) {
        return new WP_Error('rest_invalid_param', 'Invalid parameter(s)', array('status' => 400));
    }

    // init vars
    $email = $request['email'];
    $providerId = intval($request['id']);
    $newsletter = array_key_exists('newsletter', $request) ? true : false;

    // get providers list && check provider id
    $json = json_decode(file_get_contents(realpath(dirname(__FILE__)) . '/../assets/preferred.json'));
    if (!array_key_exists($providerId, $json)) {
        return new WP_Error('rest_invalid_param', 'Invalid parameter(s)', array('status' => 400));
    }

    // init post request
    $provider = $json[$providerId];
    $url = $provider->url . '/ocs/v2.php/account/request/' . $provider->key;
    $data = array(
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ),
        'body' => 'email=' . $email,
        'timeout' => 15,
    );

    // request account && xonsume one rate token
    $post = wp_remote_post($url, $data);
    $ttl = $redis->ttl($rateId);
    $redis->set($rateId, $rateLimit + 1);
    $redis->expire($rateId, $ttl > 0 ? $ttl : $limit['interval']);

    if (!array_key_exists('response', $post)) {
        return $post;
    } else if ($post['response']['code'] !== 201) {
        return new WP_Error($post['response']['message'], json_decode($post['body'])->data->message, array('status' => $post['response']['code']));
    }

    $response = json_decode($post['body'])->data;

    if (!is_string($response->setPassword)) {
        return new WP_Error('rest_invalid_param', 'An unknown error occured', array('status' => 400));
    }

    if (array_key_exists('ocsapi', $request)) {
        return $response->setPassword . '/ocs';
    }
    return $response->setPassword;
}

function get_providers_list()
{
    // get providers list
    $json = json_decode(file_get_contents(realpath(dirname(__FILE__)) . '/../assets/preferred.json'));

    // obfuscate keys
    foreach ($json as $provider) {
        unset($provider->key);
    }

    return $json;
}
