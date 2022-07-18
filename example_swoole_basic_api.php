<?php
/**
 * Swoole api example.
 *
 * An example script for a very simple restfulish api backed by a MySql database
 * to demonstrate the following constructs:
 *
 * - The http server
 * - Coroutines for async execution
 * - Channels for communicating between coroutines
 * - Defer() in channels to run code after coroutine ends
 * - A simple router to route endpoints to functions
 * - A basic emitter to deliver responses to the caller
 *
 * DB SETUP
 * This example relys on the following MySql tables and data.
 *
 * CREATE TABLE `things` (
 *   `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 *   `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
 *   PRIMARY KEY (`id`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
 *
 * INSERT INTO `things` VALUES
 * (1, 'first thing'),
 * (2, 'second thing'),
 * (3, 'third thing');
 *
 * CURLS
 * This script exposes endpoints accessible by the curls:
 *
 * curl -v -X GET -H "Accept: application/json" "http://127.0.0.1:9501/things" 
 * curl -v -X GET -H "Accept: application/json" "http://127.0.0.1:9501/things/1" 
 * curl -v -X POST -H "Accept: application/json" -H "Content-Type: application/json" "http://127.0.0.1:9501/things" -d '{"name": "created thing"}'
 * curl -v -X PUT -H "Accept: application/json" -H "Content-Type: application/json" "http://127.0.0.1:9501/things/3" -d '{"name": "update thing 3"}'
 *
 * SWOOL INSTALL
 * Swoole install for ubuntu 20.04+ and similar:
 *
 * sudo apt update
 * sudo apt install php-dev
 * sudo pecl install openswoole
 * <accept defaults>
 *
 * # find php.ini for php cli
 * php -i | grep php.ini
 * # add module to php.ini with
 * extension=openswoole
 *
 * NOTE
 * For readability, this is written as a series of functions rather than as
 * a class. This results in the use of a global variable. Apologies.
 *
 * @author ghorwood
 */

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine;

/**
 * Database credentials
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'fruitbat');
define('DB_PASS', 'examplepassword');

/**
 * Create a new Swoole HTTP server.
 * This server is started on the last line of this script.
 * @see https://openswoole.com/docs/modules/swoole-http-server-doc
 */
$http = new Server("127.0.0.1", 9501);

/**
 * Set configurations on the Swoole HTTP server
 * @see https://openswoole.com/docs/modules/swoole-server/configuration
 */
$http->set([
    'max_coroutine' => 10000,
    'enable_coroutine' => true,
]);

/**
 * Create a database connection
 */
$pdo = new PDO('mysql:host='.DB_HOST, DB_USER, DB_PASS);

/**
 * Assign the 'router' callback to handle the incoming request.
 * When the HTTP server receives a request, it will be delegated to the router()
 * function with Request and Response arguments.
 *
 * @see https://openswoole.com/docs/modules/swoole-http-server-on-request
 */
$http->on('Request', 'router');


/**
 * The router callback function, called on every new incoming request.
 * Implements a very basic router that parses the endpoint and method
 * and calls the function that handles it.
 *
 * @param  Request  $request The swoole request \Swoole\Http\Request
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function router(Request $request, Response $response)
{
    /**
     * Create a convenience array of the data from the Swoole Request
     * that is needed.
     */
    $parsedRequest = parseRequest($request);

    /**
     * The routing table
     */
    $routes = [
        [
            // the endpoint of the route as preg regex
            'uri'  => "/^\/things\/([0-9]+)$/" ,
            // the functions to call for the HTTP methods for this endpoint
            'GET'  => 'getThing',
            'PUT'  => 'putThing',
        ],
        [
            'uri'  => "/^\/things$/" ,
            'GET'  => 'getThingDigest',
            'POST' => 'postThing',
        ],
    ];

    /**
     * Iterate over all the routes until we find one that matches
     * the Request.
     */
    foreach ($routes as $route) {

        /**
         * Test if the Request uri matches this route
         * Use preg_match to get values out of the endpoint in the Request,
         * so ie. the endoint '/user/21/document/3' creates the $matches[]
         * variable as [21, 3]
         */
        if (preg_match($route['uri'], $parsedRequest['uri'], $matches)) {
            // remove the first match as it is the full string
            array_shift($matches);

            /**
             * Get the name of the function for the HTTP method for this endpoint
             * If there is none, set the function name to 'do405', which returns
             * HTTP 405.
             */
            $function = $route[$parsedRequest['method']] ?? 'do405';

            /**
             * Call the function for this endpoint and method.
             * Pass the convenience array $parsedRequest, which contains:
             * the query string and json body, if any; the $matches array which
             * contains values passed in the endpoint; and the Swoole Response
             * $response object.
             */
            $function($parsedRequest, $matches, $response);
        }
    }

    /**
     * If we have finished all the routes and not found one that matches
     * the current uri in the Swoole Request object, we need to send an HTTP 404.
     * We test isWritable() here, since this code will be reached even if
     * we have handled the route. isWritable() returns false if we have already
     * sent a response to the client.
     */
    if ($response->isWritable()) {
        do404($response);
    }
}


/**
 * Emit an HTTP 404 response.
 *
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function do404(Response $response):void
{
    $response->status(404, "Not found");
    $response->end('Not found');
}


/**
 * Emit an HTTP 405 response.
 *
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function do405(array $parsedRequest, array $matches, Response $response):void
{
    $response->status(405, "Method not allowed");
    $response->end("HTTP method not available for this URI");
}


/**
 * Handle the request for
 * GET /things
 *
 * Returns all 'things' from the database.
 * @param  Array $parsedRequest
 * @param  Array $matches
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function getThingDigest(array $parsedRequest, array $matches, Response $response):void
{
    /**
     * Run a coroutine to execute a PDO select.
     * Note that this code is already in an event loop started by $http->on('Request')
     * so the Co\run construct used in the documents is unecessary and will
     * throw an error.
     * @see https://openswoole.com/docs/modules/swoole-coroutine-mysql
     */
    go(function () use ($parsedRequest, $matches, $response) {
        $sql =<<<SQL
        SELECT  id,
                name
        FROM    exampledb.things
        SQL;

        // the doQuery() function executes the PDO query and returns the result as an array
        $result = doQuery($sql, $matches);

        /**
         * Call emit() to return the results to the caller.
         * if there are 0 results, return HTTP 404
         */
        count($result) > 0 ? emit($response, 200, $result) : do404($response);
    });
}


/**
 * Handle the request for
 * GET /things/{id}
 *
 * Returns one thing by it's id
 * @param  Array $parsedRequest
 * @param  Array $matches
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function getThing(array $parsedRequest, array $matches, Response $response):void
{
    /**
     * Channels allow communication between coroutines.
     * Create a channel to pass to the coroutines.
     * The argument references the maxiumum number of values in the channel stack.
     */
    $chan = new Swoole\Coroutine\Channel(1);

    /**
     * Coroutine 1.
     * Run a coroutine that sleeps for 10 seconds.
     * This coroutine will continue running after the function has
     * called emit() and the result has been sent to the calling client.
     */
    go(function () use ($chan) {
        // sleep for 10 seconds
        co::sleep(10);
        echo "after ten seconds".PHP_EOL;
    });

    /**
     * Coroutine 2.
     * Run a coroutine to select one thing and push the results
     * onto the channel for other coroutines to read.
     *
     * Note the channel is passed as $chan into function the
     * coroutine runs.
     */
    go(function () use ($matches, $parsedRequest, $chan, $response) {
        $sql =<<<SQL
        SELECT  id,
                name
        FROM    exampledb.things
        WHERE   id = ?
        LIMIT   1
        SQL;

        // run the query and get the results
        $result = doQuery($sql, $matches);

        /**
         * Push the results of the query into the channel.
         * Other coroutines that have access to the channel can read this
         * value with $chan->pop()
         * @see https://openswoole.com/docs/modules/swoole-coroutine-channel-push
         */
        print "data pushed into the channel:".PHP_EOL;
        print_r($result);
        $chan->push($result);

        /**
         * Call emit() to return the results to the caller.
         */
        emit($response, 200, $result);
    });

    /**
     * Coroutine 3
     * Run a coroutine that sleeps for 5 seconds.
     *
     * This coroutine reads the data pushed into the channel by
     * coroutine 2, using pop().
     * Note that once a value that has been push()ed into the channel has
     * been pop()ped, it is no longer available.
     */
    go(function () use ($chan) {
        // sleep for five seconds
        co::sleep(5);
        echo "after five seconds".PHP_EOL;

        /**
         * Pop most-recent record out of the channel into this scope.
         * Once pop()ped, this value is no longer in the channel and cannot
         * be pop()ped by any other coroutine.
         * @see https://openswoole.com/docs/modules/swoole-coroutine-channel-pop
         */
        $dataFromChannel = $chan->pop();
        print "data popped from the channel:".PHP_EOL;
        print_r($dataFromChannel);
    });
}


/**
 * Handle the request for
 * POST /things
 *
 * This function demonstrates things:
 *
 * - Two coroutines connected by a channel. Coroutine 1 does the database insert
 *   then uses the channel to push data to coroutine 2 that writes the log
 *
 * - Emit() happening outside of the coroutines. Since the function falls through
 *   to the bottom before starting the coroutines, this endpoint returns immediately
 *   and the coroutines that do the insert and log writing happen later. This is
 *   for fire-and-forget endpoints.
 *
 * @param  Array $parsedRequest
 * @param  Array $matches
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function postThing(array $parsedRequest, array $matches, Response $response):void
{
    /**
     * Get the json body of the requrest from the convenience array
     */
    $bodyObject = json_decode($parsedRequest['json']);

    /**
     * Create a channel to pass to the coroutines.
     */
    $chan = new Swoole\Coroutine\Channel(1);

    /**
     * Coroutine 1.
     * Run a coroutine to insert one thing
     * This coroutine does not call emit()
     */
    go(function () use ($bodyObject, $chan, $response) {
        $sql =<<<SQL
        INSERT
        INTO    exampledb.things
                (name)
        VALUES  (?)
        SQL;

        // create PDO parameters array from request body passed as bodyObject
        $parameters = [
            strval($bodyObject->name),
        ];

        // run the query and get the results
        $result = doQuery($sql, $parameters);

        /**
         * Push the results of the query into the channel.
         */
        print "data pushed into the channel:".PHP_EOL;
        print_r($bodyObject);
        $chan->push($bodyObject);
    });

    /**
     * Coroutine 2.
     * Run a coroutine to pop() from the channel and write to file.
     */
    go(function () use ($chan) {
        // sleep for five seconds
        co::sleep(5);

        /**
         * Pop() most recent record from the channel
         * and write a line to a log file
         */
        $dataFromChannel = $chan->pop();
        print "data popped from the channel:".PHP_EOL;
        print_r($dataFromChannel);
        $fp = fopen("/tmp/log", "a");
        fwrite($fp, "created thing ".$dataFromChannel->name.PHP_EOL);
        fclose($fp);
    });

    /**
     * Call emit() outside of the coroutines.
     * The emit happens immediately, but all the coroutines still run.
     */
    emit($response, 201, null);
}


/**
 * Handle the request for
 * PUT /things/{id}
 *
 * This function demonstrates the defer() function inside of a coroutine.
 * Defer takes a callable as an argument and runs that callable after the
 * coroutine ends.
 *
 * @param  Array $parsedRequest
 * @param  Array $matches
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @return void
 */
function putThing(array $parsedRequest, array $matches, Response $response):void
{
    /**
     * Get the json body of the requrest from the convenience array
     */
    $bodyObject = json_decode($parsedRequest['json']);

    /**
     * Coroutine 1.
     * Run a coroutine to update the record and select it back
     * Defer() log writing to happen after emit
     */
    go(function () use ($bodyObject, $matches, $response) {
        // update the record
        $sql =<<<SQL
        UPDATE  exampledb.things t
        SET     t.name = ?
        WHERE   t.id = ?
        SQL;

        // create PDO parameters array from request body passed as bodyObject
        $parameters = [
            strval($bodyObject->name),
            intval($matches[0]),
        ];

        // run the query and get the results
        $result = doQuery($sql, $parameters);

        /**
         * The defer() function causes the callable passed as
         * and argument to run after termination of this coroutine
         * @see https://openswoole.com/docs/modules/swoole-coroutine-defer
         */
        defer(function () use ($bodyObject) {
            $fp = fopen("/tmp/log", "a");
            fwrite($fp, "updated thing ".$bodyObject->name.PHP_EOL);
        });

        // select the record back
        $sql =<<<SQL
        SELECT  id,
                name
        FROM    exampledb.things
        WHERE   id = ?
        LIMIT   1
        SQL;

        // create PDO parameters array from request body passed as bodyObject
        $parameters = [
            intval($matches[0]),
        ];

        // run the query and get the results
        $result = doQuery($sql, $parameters);

        /**
         * Call emit() to return the results to the caller.
         */
        emit($response, 201, $result);
    });
}


/**
 * Performs a query on the database using PDO and returns the results.
 *
 * Note: if there is only one record in the result, an associative array
 * of that record is returned. If more than one row, an array of associative
 * arrays is returned.
 *
 * @param  String  $sql        The sql statement
 * @param  Array   $parameters The array of parameters to bind to the sql statement, if any
 * @return Array
 */
function doQuery(String $sql, array $parameters = []):array
{
    // seel globals apology in top comment
    global $pdo;

    // prepare, execute and fetch results
    $st = $pdo->prepare($sql);
    $st->execute($parameters);
    $result = $st->fetchall(PDO::FETCH_ASSOC);

    // clear the statement
    $st = null;

    /**
     * Enforce array return
     * If array has only element, return the associative array of that
     * one record.
     */
    switch (count($result)) {
        case 0:
            return [];
            break;
        case 1:
            return $result[0];
            break;
        default:
            return $result;
            break;
    }
}


/**
 * Parses data from the Swoole Request object into a convenience array.
 *
 * @param  Request $request The swoole request \Swoole\Http\Request
 * @return Array
 */
function parseRequest(Request $request):array
{
    return [
        'method' => $request->getMethod(),
        'uri' => $request->server['request_uri'] ?? '/',
        'query_string' => $request->get ?? [],
        'headers' => $request->header,
        'json' => @$request->header['content-type'] == "application/json" ? $request->rawContent() : null,
    ];
}


/**
 * Creates and sends the response to the caller.
 *
 * @param  Response $response The swoole response \Swoole\Http\Response
 * @param  Int      $code     The HTTP code to send, ie 200
 * @param  Mixed    $data     The optional data to return as json
 * @return void
 */
function emit(Response $response, Int $code, $data=null):void
{
    $response->status($code);
    $response->header("Content-Type", "application/json");
    $response->end(json_encode($data));
}

/**
 * Start the Swoole event loop
 */
$http->start();
