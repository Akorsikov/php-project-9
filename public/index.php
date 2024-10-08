<?php

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
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use DiDom\Document;
use Dotenv\Dotenv;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Php\Project\Connection;
use Slim\Psr7\Request;

session_start();

const DATABASE_SCHEME = 'database.sql';
const TIME_ZONE_NAME = 'MSK';

if (empty($_ENV['DATABASE_URL'])) {
    Dotenv::createImmutable(__DIR__ . '/../')->load();
}

$container = new Container();

$container->set('connectionDB', function () {
    return new Connection($_ENV['DATABASE_URL']);
});

$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Messages();
});

$initFilePath = implode('/', [dirname(__DIR__), DATABASE_SCHEME]);
$initSql = file_get_contents($initFilePath);
// @phpstan-ignore-next-line
$container->get('connectionDB')->getConnect()->exec($initSql);
// Иначе phpstan ругается: Cannot call method getConnect() on mixed.

$app = AppFactory::createFromContainer($container);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$app->get('/', function ($request, Response $response) {

    return $this->get('renderer')->render($response, 'main.phtml');
})->setName('main');

$app->post('/urls', function ($request, Response $response) {
    $url = $request->getParsedBodyParam('url');
    $urlName = htmlspecialchars($url['name']);

    $rules = ['required', 'url', ['lengthMax', 255]];
    $validation = new Validator(['urlname' => $urlName]);
    $validation->mapFieldRules('urlname', $rules);

    if ($validation->validate()) {
        $extractQuery = "SELECT id FROM urls WHERE name=:urlname";
        $stmt = $this->get('connectionDB')->getConnect()->prepare($extractQuery);
        $stmt->bindParam(':urlname', $urlName);
        $stmt->execute();
        $resultQueryDB = $stmt->fetch();

        if (empty($resultQueryDB)) {
            $insertQuery = "INSERT INTO urls (name) VALUES (:name)";
            $stmt1 = $this->get('connectionDB')->getConnect()->prepare($insertQuery);
            $stmt1->bindParam(':name', $urlName);
            $stmt1->execute();
            $id = $this->get('connectionDB')->getConnect()->lastInsertId();
            $messageText = 'Страница успешно добавлена';
        } else {
            $id = is_array($resultQueryDB) ? $resultQueryDB['id'] : null;
            $messageText = 'Страница уже существует';
        }

        $this->get('flash')->addMessage('success', $messageText);
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('checkUrls', ['id' => "$id"]);

        return $response->withStatus(302)->withHeader('Location', $url);
    }
    $errors = $validation->errors();
    $errorMessages = is_bool($errors) ? null : $errors['urlname'];
    $params = [
        'urlName' => $urlName,
        'errors' => $errorMessages
    ];

    return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
})->setName('validateUrls');

$app->get('/urls', function ($request, Response $response) {
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
    $stmt = $this->get('connectionDB')->getConnect()->prepare($extractQuery);
    $stmt->bindValue(':timeZoneName', TIME_ZONE_NAME);
    $stmt->execute();
    $arrayUrls = $stmt->fetchAll();
    $params = ['urls' => $arrayUrls];

    return $this->get('renderer')->render($response, 'view.phtml', $params);
})->setName('viewUrls');

$app->get(
    '/urls/{id:[0-9]+}',
    function ($request, Response $response, array $args) {
        $id = $args['id'];

        $extractQuery1 = "
            SELECT
                id AS url_id,
                name,
                created_at AT TIME ZONE :timeZoneName AS url_created_at
            FROM urls
            WHERE id = :id
        ";
        $stmt1 = $this->get('connectionDB')->getConnect()->prepare($extractQuery1);
        $stmt1->bindParam(':id', $id);
        $stmt1->bindValue(':timeZoneName', TIME_ZONE_NAME);
        $stmt1->execute();
        $param1 = $stmt1->fetch();

        if (empty($param1)) {
            return $this->get('renderer')->render($response->withStatus(404), 'error404.phtml');
        }

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
        $stmt2 = $this->get('connectionDB')->getConnect()->prepare($extractQuery2);
        $stmt2->bindParam(':url_id', $id);
        $stmt2->bindValue(':timeZoneName', TIME_ZONE_NAME);
        $stmt2->execute();
        $param2 = $stmt2->fetchAll();

        $flash = $this->get('flash');
        $param3 = $flash->getMessages();

        $params = [
            'url' => $param1,
            'checks' => $param2,
            'flashMessages' => $param3
        ];

        return $this->get('renderer')->render($response, 'check.phtml', $params);
    }
)->setName('checkUrls');

$app->post(
    '/urls/{url_id:[0-9]+}/checks',
    function ($request, Response $response, array $args) {
        $urlId = $args['url_id'];

        $extractQuery = "SELECT name FROM urls WHERE id = :id";
        $stmt1 = $this->get('connectionDB')->getConnect()->prepare($extractQuery);
        $stmt1->bindParam(':id', $urlId);
        $stmt1->execute();
        $result = $stmt1->fetch();

        if (empty($result)) {
            return $this->get('renderer')->render($response->withStatus(404), 'error404.phtml');
        }

        $urlName = is_array($result) && array_key_exists('name', $result) ? $result['name'] : null;

        try {
            $client = new Client();
            $response = $client->request('GET', $urlName);
        } catch (RequestException) {
            $messageStatus = 'warning';
            $messageText = 'Проверка была выполнена успешно, но сервер ответил с ошибкой';
            $flash = [$messageStatus => [$messageText]];

            return $this
                ->get('renderer')
                ->render($response->withStatus(500), 'error500.phtml', ['flashMessages' => $flash]);
        } catch (ConnectException) {
            $messageStatus = 'danger';
            $messageText = 'Произошла ошибка при проверке, не удалось подключиться';
            $this->get('flash')->addMessage($messageStatus, $messageText);
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('checkUrls', ['id' => "$urlId"]);

            return $response->withStatus(302)->withHeader('Location', $url);
        }
            $statusCode = $response->getStatusCode();

            $document = new Document($urlName, true);
            // @phpstan-ignore-next-line
            $rawH1 = $document->first('h1') ? substr($document->first('h1')->text(), 0, 255) : '';
            // @phpstan-ignore-next-line
            $rawTitle = $document->first('title') ? substr($document->first('title')->text(), 0, 255) : '';
            $metaElement = $document->first('meta[name="description"]');
            $rawDescription = $metaElement ? $metaElement->getAttribute('content') : '';

            $h1 = empty($rawH1) ? '' : mb_convert_encoding($rawH1, "UTF-8");
            $title = empty($metaElement) ? '' : mb_convert_encoding($rawTitle, "UTF-8");
            $description = empty($rawDescription) ? '' : mb_convert_encoding($rawDescription, "UTF-8");

            $insertQuery = "
                INSERT INTO url_checks (url_id, status_code, h1, title, description) 
                VALUES (:urlId, :statusCode, :h1, :title, :description)
            ";
            $stmt = $this->get('connectionDB')->getConnect()->prepare($insertQuery);
            $stmt->bindParam(':urlId', $urlId);
            $stmt->bindParam(':statusCode', $statusCode);
            $stmt->bindParam(':h1', $h1);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);

        try {
            $stmt->execute();
            $messageStatus = 'success';
            $messageText = 'Страница успешно проверена';
            $this->get('flash')->addMessage($messageStatus, $messageText);
        } catch (PDOException) {
            $messageStatus = 'danger';
            $messageText = 'Произошла ошибка при записи в базу данных';
            $this->get('flash')->addMessage($messageStatus, $messageText);
        } finally {
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('checkUrls', ['id' => "$urlId"]);

            return $response->withStatus(302)->withHeader('Location', $url);
        }
    }
)->setName('checkUrls');

$app->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
    return $this->get('renderer')->render($response, 'error404.phtml');
})->setName('not-found');

$app->run();
