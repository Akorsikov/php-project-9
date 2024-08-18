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
// use Psr\Container\ContainerInterface;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Valitron\Validator;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use GuzzleHttp\Client;

// use function DI\string;

require __DIR__ . '/../vendor/autoload.php';

$LOCAL_DATABASE_URL = 'postgresql://postgres:123456@localhost:5432/websites_db';
$TIME_ZONE_NAME = 'MSK';

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions(
    [
        'flash' => function () {
            $storage = [];
            return new Messages($storage);
        }
    ]
);

AppFactory::setContainer($containerBuilder->build());

$app = AppFactory::create();

// Add session start middleware
$app->add(
    function ($request, $next) {
        // Start PHP session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Change flash message storage
        $this->get('flash')->__construct($_SESSION);

        return $next->handle($request);
    }
);

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

// add DB-connection
// $databaseUrl = parse_url(getenv('DATABASE_URL')); // Обратить внимание наставника
$databaseUrl = parse_url($_ENV['DATABASE_URL'] ?? $LOCAL_DATABASE_URL);

$host = $databaseUrl['host'] ?? null;
$port = $databaseUrl['port'] ?? 5432;
$dbname = ltrim($databaseUrl['path'] ?? "", '/');
$user = $databaseUrl['user'] ?? null;
$password = $databaseUrl['pass'] ?? null;

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
// Add renderer
$renderer = new PhpRenderer(__DIR__ . '/../templates');

// $timezoneOffsetMinutes = $_GET['timezone_offset_minutes'];
// $timeZoneName = timezone_name_from_abbr("", $timezoneOffsetMinutes * 60, 1);
//
// Warning: Undefined array key "timezone_offset_minutes" in /app/public/index.php on line 104
// Warning: session_start(): Session cannot be started after headers
//                           have already been sent in /app/public/index.php on line 51


// Define app routes
$app->get('/', function ($request, Response $response) use ($renderer) {

    return $renderer->render($response, 'main.phtml');
})->setName('main');

$app->post('/urls', function ($request, Response $response) use ($connectionDB, $renderer) {
    $urlName = $request->getParsedBodyParam('url_name');

    $rules = ['required', 'url', ['lengthMax', 255]];
    $validation = new Validator(['urlname' => $urlName]);
    $validation->mapFieldRules('urlname', $rules);

    if ($validation->validate()) {
        $extractQuery = "SELECT id FROM urls WHERE name=:urlname";
        $stmt = $connectionDB->prepare($extractQuery);
        $stmt->bindParam(':urlname', $urlName);
        $stmt->execute();
        $resultQueryDB = $stmt->fetch();

        if (empty($resultQueryDB)) {
            $insertQuery = "INSERT INTO urls (name) VALUES (:name)";
            $stmt1 = $connectionDB->prepare($insertQuery);
            $stmt1->bindParam(':name', $urlName);
            $stmt1->execute();
            $id = $connectionDB->lastInsertId();
            $messageText = 'Страница успешно добавлена';
        } else {
            $id = is_array($resultQueryDB) ? $resultQueryDB['id'] : null;
            $messageText = 'Страница уже существует';
        }

        // Set flash message for next request
        $this->get('flash')->addMessage('success', $messageText);
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('testUrls', ['id' => "$id"]);

        return $response->withStatus(302)->withHeader('Location', $url);
    }
    $errors = $validation->errors();
    $errorMessages = is_bool($errors) ? null : $errors['urlname'];
    $params = [
        'urlName' => $urlName,
        'errors' => $errorMessages
    ];

    return $renderer->render($response, 'main.phtml', $params);
})->setName('validateUrls');

$app->get('/urls', function ($request, Response $response) use ($connectionDB, $renderer, $TIME_ZONE_NAME) {
    $extractQuery = "
        SELECT
            u.id,
            u.name,
            uc.created_at AT TIME ZONE :timeZoneName AS last_check,
            uc.status_code
        FROM urls u
        LEFT JOIN url_checks uc
        ON uc.url_id = u.id
        WHERE uc.created_at is NULL or uc.created_at IN (
            select MAX(created_at)
            from url_checks
            group by url_id 
            )
        ORDER BY u.created_at DESC
    ";
    $stmt = $connectionDB->prepare($extractQuery);
    $stmt->bindParam(':timeZoneName', $TIME_ZONE_NAME);
    $stmt->execute();
    $arrayUrls = $stmt->fetchAll(); // phpstan ругается: Cannot call method fetchAll() on PDOStatement|false.
    $params = ['urls' => $arrayUrls];

    return $renderer->render($response, 'view.phtml', $params);
})->setName('viewUrls');

$app->get(
    '/urls/{id}',
    function ($request, Response $response, array $args) use ($connectionDB, $renderer, $TIME_ZONE_NAME) {
        $id = $args['id'];

        $extractQuery1 = "
            SELECT
                id AS url_id,
                name,
                created_at AT TIME ZONE :timeZoneName AS url_created_at
            FROM urls
            WHERE id = :id
        ";
        $stmt1 = $connectionDB->prepare($extractQuery1);
        $stmt1->bindParam(':id', $id);
        $stmt1->bindParam(':timeZoneName', $TIME_ZONE_NAME);
        $stmt1->execute();
        $params = $stmt1->fetch();

        $extractQuery2 = "
            SELECT
                id AS check_id,
                status_code,
                created_at AT TIME ZONE :timeZoneName AS check_created_at
            FROM url_checks 
            WHERE url_id=:url_id
            ORDER BY check_created_at DESC
        ";
        $stmt2 = $connectionDB->prepare($extractQuery2);
        $stmt2->bindParam(':url_id', $id);
        $stmt2->bindParam(':timeZoneName', $TIME_ZONE_NAME);
        $stmt2->execute();
        $params['checks'] = $stmt2->fetchAll();

        // Get flash messages from previous request
        $flash = $this->get('flash');
        // Get messages from a specific key
        $flashMessages = $flash->getMessages();
        $params['flashMessages'] = $flashMessages;

        return $renderer->render($response, 'test.phtml', $params);
    }
)->setName('testUrls');

$app->post(
    '/urls/{url_id}/checks',
    function ($request, Response $response, array $args) use ($connectionDB) {
        $urlId = $args['url_id'];

        $extractQuery = "SELECT name FROM urls WHERE id = :id";
        $stmt1 = $connectionDB->prepare($extractQuery);
        $stmt1->bindParam(':id', $urlId); // urls.id = url_checks.url_id
        $stmt1->execute();
        $extract = $stmt1->fetch();

        try {
            // Создаем новый экземпляр клиента Guzzle
            $client = new Client();
            // Выполняем GET-запрос
            $response = $client->request('GET', $extract['name']);
            // Выводим статус-код ответа, заголовок 'content-type' и тело ответа.
            $statusCode = $response->getStatusCode();
            // $title = $response->getHeaderLine('content-type');
            // $body = $response->getBody();
            $insertQuery = "INSERT INTO url_checks (url_id, status_code) VALUES (:urlId, :statusCode)";
            $stmt = $connectionDB->prepare($insertQuery);
            $stmt->bindParam(':urlId', $urlId);
            $stmt->bindParam(':statusCode', $statusCode);
            $stmt->execute();
            $messageStatus = 'success';
            $messageText = 'Страница успешно проверена';
        } catch (GuzzleHttp\Exception\TransferException) {
            $messageStatus = 'danger';
            $messageText = 'Произошла ошибка при проверке, не удалось подключиться';
        } finally
        {
            // Set flash message for next request
            $this->get('flash')->addMessage($messageStatus, $messageText);
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('testUrls', ['id' => "$urlId"]);

            return $response->withStatus(302)->withHeader('Location', $url);
        }
    }
)->setName('checkUrls');

// Run app
$app->run();
