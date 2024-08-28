<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Http\Response;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Flash\Messages;
use Slim\Routing\RouteContext;
use Slim\Views\PhpRenderer;
use Slim\Middleware\MethodOverrideMiddleware;
use Valitron\Validator;
use GuzzleHttp\Client;
use DiDom\Document;
use Dotenv\Dotenv;

// Старт PHP сессии
session_start();

$TIME_ZONE_NAME = 'MSK';

// $LOCAL_DATABASE_URL = 'postgresql://postgres:123456@localhost:5432/websites_db';
if (empty($_ENV['DATABASE_URL'])) {
    Dotenv::createImmutable(__DIR__ . '/../')->load();
}

// add DB-connection
$databaseUrl = parse_url($_ENV['DATABASE_URL']);

$host = $databaseUrl['host'] ?? null;
$port = $databaseUrl['port'] ?? 5432;
$dbname = ltrim($databaseUrl['path'] ?? '', '/');
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

$connectionDB = new PDO($conStr);
$connectionDB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$connectionDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$container = new Container();

$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);

// Add router
$router = $app->getRouteCollector()->getRouteParser();
// Add renderer
$renderer = new PhpRenderer(__DIR__ . '/../templates');

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

// Define app routes
$app->get('/', function ($request, Response $response) use ($renderer) {
    $params = ['choice' => 'main'];

    return $renderer->render($response, 'main.phtml', $params);
})->setName('main');

$app->post('/urls', function ($request, Response $response) use ($connectionDB, $renderer) {
    $url = $request->getParsedBodyParam('url');
    $urlName = $url['name'];

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
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('checkUrls', ['id' => "$id"]);

        return $response->withStatus(302)->withHeader('Location', $url);
    }
    $errors = $validation->errors();
    $errorMessages = is_bool($errors) ? null : $errors['urlname'];
    $params = [
        'urlName' => $urlName,
        'errors' => $errorMessages,
        'choice' => 'main'
    ];

    return $renderer->render($response->withStatus(422), 'main.phtml', $params);
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
    $arrayUrls = $stmt->fetchAll();
    $params = ['urls' => $arrayUrls, 'choice' => 'view'];

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
        $param1 = $stmt1->fetch();

        $extractQuery2 = "
            SELECT
                id AS check_id,
                status_code,
                h1,
                title,
                description,
                created_at AT TIME ZONE :timeZoneName AS check_created_at
            FROM url_checks 
            WHERE url_id=:url_id
            ORDER BY check_created_at DESC
        ";
        $stmt2 = $connectionDB->prepare($extractQuery2);
        $stmt2->bindParam(':url_id', $id);
        $stmt2->bindParam(':timeZoneName', $TIME_ZONE_NAME);
        $stmt2->execute();
        $param2 = $stmt2->fetchAll();

        // Get flash messages from previous request
        $flash = $this->get('flash');
        // Get messages from a specific key
        $param3 = $flash->getMessages();

        $params = [
            'url' => $param1,
            'checks' => $param2,
            'flashMessages' => $param3
        ];


        return $renderer->render($response, 'check.phtml', $params);
    }
)->setName('checkUrls');

$app->post(
    '/urls/{url_id}/checks',
    function ($request, Response $response, array $args) use ($connectionDB) {
        $urlId = $args['url_id'];

        $extractQuery = "SELECT name FROM urls WHERE id = :id";
        $stmt1 = $connectionDB->prepare($extractQuery);
        $stmt1->bindParam(':id', $urlId); // urls.id = url_checks.url_id
        $stmt1->execute();
        $result = $stmt1->fetch();
        $urlName = is_array($result) && array_key_exists('name', $result) ? $result['name'] : null;

        try {
            // Создаем новый экземпляр клиента Guzzle
            $client = new Client();
            // Выполняем GET-запрос
            $response = $client->request('GET', $urlName);
            // Выводим статус-код ответа, заголовок 'content-type' и тело ответа.
            $statusCode = $response->getStatusCode();
            // Создать новый экземпляр Document
            $document = new Document($urlName, true);
            // Получить h1, title, description
            // @phpstan-ignore-next-line
            $rawH1 = $document->first('h1') ? substr($document->first('h1')->text(), 0, 255) : '';
            // @phpstan-ignore-next-line
            $rawTitle = $document->first('title') ? substr($document->first('title')->text(), 0, 255) : '';
            $metaElement = $document->first('meta[name="description"]');
            $rawDescription = $metaElement ? $metaElement->getAttribute('content') : '';

            if (!empty($rawH1)) {
                $h1 = mb_convert_encoding($rawH1, "UTF-8");
            }
            if (!empty($metaElement)) {
                $title = mb_convert_encoding($rawTitle, "UTF-8");
            }
            if (!empty($rawDescription)) {
                $description = mb_convert_encoding($rawDescription, "UTF-8");
            }
            $insertQuery = "
                INSERT INTO url_checks (url_id, status_code, h1, title, description) 
                VALUES (:urlId, :statusCode, :h1, :title, :description)
            ";
            $stmt = $connectionDB->prepare($insertQuery);
            $stmt->bindParam(':urlId', $urlId);
            $stmt->bindParam(':statusCode', $statusCode);
            $stmt->bindParam(':h1', $h1);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);

            $stmt->execute();
            $messageStatus = 'success';
            $messageText = 'Страница успешно проверена';
        } catch (GuzzleHttp\Exception\TransferException) {
            $messageStatus = 'danger';
            $messageText = 'Произошла ошибка при проверке, не удалось подключиться';
        } catch (PDOException $Exception) {
            $messageStatus = 'danger';
            $messageText = 'Произошла ошибка при записи в базу данных';
            // $messageText = $Exception->getMessage();
        } finally {
            // Set flash message for next request
            $this->get('flash')->addMessage($messageStatus, $messageText);
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('checkUrls', ['id' => "$urlId"]);

            return $response->withStatus(302)->withHeader('Location', $url);
        }
    }
)->setName('checkUrls');

// Run app
$app->run();
