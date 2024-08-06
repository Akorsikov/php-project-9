<?php

/**
 * Instantiate App
 *
 * In order for the factory to work you need to ensure you have installed
 * a supported PSR-7 implementation of your choice e.g.: Slim PSR-7 and a supported
 * ServerRequest creator (included with Slim PSR-7)
 */

use Slim\Http\Request;
use Slim\Http\Response;
// use Psr\Http\Message\ResponseInterface as Response;     // не знает getParsedBodyParam()
// use Psr\Http\Message\ServerRequestInterface as Request; // не знает withRedirect()
use Psr\Http\Message\UriInterface;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// Add Routing Middleware
$app->addRoutingMiddleware();

/**
 * Add Error Handling Middleware
 *
 * @param bool $displayErrorDetails -> Should be set to false in production
 * @param bool $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool $logErrorDetails -> Display error details in error log
 * which can be replaced by a callable of your choice.

 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

//
    // $databaseUrl = parse_url('postgresql://sites_db_user:vqEoglOCba3yVva54BoB93y1SYMA4q6r@dpg-cq4qdieehbks73bftqkg-a.frankfurt-postgres.render.com/sites_db');
    $host = isset($databaseUrl['host']) ? $databaseUrl['host'] : 'localhost';
    $port = isset($databaseUrl['port']) ? $databaseUrl['port'] : 5432;
    $dbname = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : 'websites_db';
    $user = isset($databaseUrl['user']) ? $databaseUrl['user'] : 'aleksandr';
    $password = isset($databaseUrl['pass']) ? $databaseUrl['pass'] : 123456;
    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $host,
        $port,
        $dbname,
        $user,
        $password
    );

    $connectionDB = new \PDO($conStr);
    $connectionDB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Add router
    $router = $app->getRouteCollector()->getRouteParser();

// Define app routes
    $app->get('/', function ($request, Response $response) {
        $renderer = new PhpRenderer(__DIR__ . '/../templates');

        return $renderer->render($response, 'main.phtml');
    })->setName('main');

    $app->get('/urls', function ($request, Response $response) use ($connectionDB) {

        $extractQuery = "SELECT * FROM urls ORDER BY id DESC";
        $stmt = $connectionDB->query($extractQuery);
        $arrayUrls = $stmt->fetchAll(); // phpstan ругается: Cannot call method fetchAll() on PDOStatement|false.

        $params = ['urls' => $arrayUrls];
        $renderer = new PhpRenderer(__DIR__ . '/../templates');

        return $renderer->render($response, 'view.phtml', $params);
    })->setName('viewUrls');

    $app->get('/urls/{id}', function ($request, Response $response, array $args) use ($connectionDB) {
        $id = $args['id'];

        $extractQuery = "SELECT * FROM urls WHERE id=:id";
        $stmt = $connectionDB->prepare($extractQuery);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        [$arrayUrls] = $stmt->fetchAll();

        $renderer = new PhpRenderer(__DIR__ . '/../templates');

        return $renderer->render($response, 'test.phtml', $arrayUrls);
    })->setName('test');

    $app->post('/valid', function ($request, Response $response) use ($connectionDB, $router) {
        $urlName = $request->getParsedBodyParam('url_name');    // phpstan ругался

        $insertQuery = "INSERT INTO urls (name) VALUES (:name)";
        $stmt = $connectionDB->prepare($insertQuery);
        $stmt->bindParam(':name', $urlName);
        $stmt->execute();
        $id = $connectionDB->lastInsertId(); // phpstan ругался

        $url = $router->urlFor('test', ['id' => (String) $id]);
        return $response->withRedirect($url);   // phpstan ругался
    })->setName('valid');

// Run app
    $app->run();
