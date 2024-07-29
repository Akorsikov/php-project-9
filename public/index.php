<?php

/**
 * Instantiate App
 *
 * In order for the factory to work you need to ensure you have installed
 * a supported PSR-7 implementation of your choice e.g.: Slim PSR-7 and a supported
 * ServerRequest creator (included with Slim PSR-7)
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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

// Define app routes
$app->get('/', function (Request $request, Response $response) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    return $renderer->render($response, 'main.phtml');
})->setName('main');

$app->post('/urls', function (Request $request, Response $response) {
    $url = $request->getParsedBodyParam('url_name');

    // 1 - вариант
    // $connString = "hostt port=5432 dbname=websites_db user=aleksandr password=123456";
    // $connectionDB = pg_connect($=localhosconnString);

    // 2 - вариант
    // postgresql://sites_db_user:vqEoglOCba3yVva54BoB93y1SYMA4q6r@dpg-cq4qdieehbks73bftqkg-a.frankfurt-postgres.render.com/sites_db
    $connString = "postgresql://sites_db_user:vqEoglOCba3yVva54BoB93y1SYMA4q6r@dpg-cq4qdieehbks73bftqkg-a.frankfurt-postgres.render.com/sites_db port=5432 dbname=sites_db user=sites_db_user password=vqEoglOCba3yVva54BoB93y1SYMA4q6r";
    $connectionDB = new \PDO($connString);
    $params = ['name' => $url, 'connectionDB' => $connectionDB];
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    return $renderer->render($response, 'check.phtml', $params);
})->setName('check');

// Run app
$app->run();
