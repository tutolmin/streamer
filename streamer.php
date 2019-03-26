<?php

// If one day I need to stream NDJSON (for game prefetch)
// https://github.com/clue/reactphp-ndjson
// curl -H "Accept: application/x-ndjson"  https://lichess.org/api/games/user/lemon79

require_once(dirname(__FILE__) . "/vendor/autoload.php");

use React\Http\Server;
use React\Http\Response;
use React\EventLoop\Factory;
use Psr\Http\Message\ServerRequestInterface;
use React\HttpClient;

$loop = React\EventLoop\Factory::create();

// Log all server requests
$loggingMiddleware = function(ServerRequestInterface $request, callable $next) {
    echo date('Y-m-d H:i:s') . ' ' . $request->getMethod() . ' ' . $request->getUri() . PHP_EOL;
    return $next($request);
};

// Check for query parameters
$queryParamMiddleware = function(ServerRequestInterface $request, callable $next) {
    $params = $request->getQueryParams();

    // Check for required parameters and their format
    $aValid = array('-', '_');
    $sUser = $params['user'];
    if ( (!isset( $params['gid']) || strlen( $params['gid']) != 8 || !ctype_alnum( $params['gid'])) 
	&& (!isset( $params['user']) || !ctype_alnum(str_replace($aValid, '', $sUser)))) {
        return new Response(422, ['Content-Type' => 'text/plain'], "PGN streaming server: check params" . PHP_EOL);
    }
    return $next($request);
};

// Stream the game(s)
$gameStreamingMiddleware = function(ServerRequestInterface $request) use ($loop) {
  $params = $request->getQueryParams();

  // Specifically request for PGN format
  $headers = [ 'Accept' => 'application/x-chess-pgn' ];
//  $headers = [ 'Accept' => 'application/x-ndjson' ];

  // Append auth token if present
  // Adding correct auth token allows download of 50 games/second
  // while the database can only insert 10 games/second
  // No reason to bother with auth atm
//  if( isset( $params['auth']))
//    $headers[ 'Authorization' ] = "Bearer " . $params['auth'];

  // Init an HTTP client
  $client = new React\HttpClient\Client($loop);
  if( isset( $params['gid']))
    $URL = 'https://lichess.org/game/export/' . $params['gid'];
  else
    // Full list of parameters https://lichess.org/api#operation/apiGamesUser
    $URL = 'https://lichess.org/api/games/user/' . $params['user'] . 
	'?max=1000&perfType=blitz';
//  echo $URL;

  $request = $client->request('GET', $URL, $headers);

//  $new_file=true;

  // Stream the response
  $request->on('response', function ($response) {
//    var_dump($response->getHeaders());
    $response->on('data', function ($games) {
//      echo 'Hello ' . $user . PHP_EOL;

//    if( $new_file) {
// Params unavailable here!!!!!!!!!!!!!!!!!!!!!!!!!
//
//$id = ((isset($params['user'])?$params['user']:$params['gid']));
      $tmp_file = tempnam("/root/chess/streamer/tmp/", "");

//      $new_file = false;
//    }

    file_put_contents( $tmp_file, $games);

//    if( filesize( $tmp_file) > 100000) {

      exec( "/root/chess/extractor/extractor --quiet --nofauxep -4 -Wuci " . escapeshellarg( $tmp_file) . " -l " . 
        escapeshellarg( $tmp_file) . ".err 2>&1 >/dev/null", $retArr, $retVal);
//var_dump( $retArr);
//var_dump( $retVal);
      echo date('Y-m-d H:i:s '); 
      readfile($tmp_file.".err");

//      $new_file=true;
//    }

    });
    $response->on('end', function () {
      echo 'Streaming complete' . PHP_EOL;
    });

  });

  $request->on('error', function (\Exception $e) {
    echo $e;
  });
  $request->end();

  // Return with OK code and continue in the background
  return new React\Http\Response(
        200,
        array('Content-Type' => 'text/plain'),
        "Game streaming started.\n"
  );
};

// Server instance
$server = new React\Http\Server([
  $loggingMiddleware,
  $queryParamMiddleware,
  $gameStreamingMiddleware
]);

// Start the server
$socket = new React\Socket\Server(8080, $loop);
$server->listen($socket);

echo "Server running at http://127.0.0.1:8080" . PHP_EOL;

$loop->run();

