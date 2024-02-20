<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();



$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response;
});

// Send mail


$app->post('/verifyPin', function (Request $request, Response $response, $args) {
    $body = $request->getParsedBody();
    $receivedPin = $body['pin'] ?? '';


    $host = 'localhost';
    $db = 'baza74815_3';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);

    // Zapytanie do bazy danych o PIN
    $stmt = $pdo->prepare("SELECT pin,uid FROM booking_uzytkownicy WHERE pin = ?");
    $stmt->execute([$receivedPin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $storedPin = $user['pin'];

    $uid = $user['uid'];

    // Porównanie PIN-ów
    if ((string) $receivedPin === (string) $storedPin) {
        $response->getBody()->write(json_encode(['isPinValid' => true, 'uid' => $uid]));
        return $response
            ->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['isPinValid' => false]));
        return $response
            ->withHeader('Content-Type', 'application/json');
    }




});

$app->post('/getDesks', function (Request $request, Response $response, $args) {
    $body = $request->getParsedBody();
    $date = $body['date'] ?? '';

    $host = 'localhost';
    $db = 'baza74815_3';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);

    $stmt = $pdo->prepare('
    SELECT m.rodzaj, m.did, m.nazwa, m.lokalizacja
    FROM booking_miejsce m 
    LEFT JOIN booking_historia_rezerwacji h 
    ON m.id = h.numer_miejsca AND h.data_rezerwacji = ?
    WHERE h.id IS NULL
');
    $stmt->execute([$date]);
    $desks = $stmt->fetchAll();

    $response->getBody()->write(json_encode($desks));
    //$response->getBody()->write(json_encode(['desks' => $date2]));

    return $response
        ->withHeader('Content-Type', 'application/json');
});


$app->post('/getReservedDesks', function (Request $request, Response $response, $args) {
    $body = $request->getParsedBody();
    $uid = $body['userId'] ?? '';

    $host = 'localhost';
    $db = 'baza74815_3';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);

    $stmt = $pdo->prepare('
    SELECT m.nazwa,m.lokalizacja,m.rodzaj
    FROM booking_miejsce m 
    INNER JOIN booking_historia_rezerwacji h 
    ON m.id = h.numer_miejsca 
    INNER JOIN booking_uzytkownicy u
    ON h.uzytkownik = u.id
    WHERE u.uid = ?
');

    $stmt->execute([$uid]);
    $desks = $stmt->fetchAll();

    $response->getBody()->write(json_encode($desks));
    //$response->getBody()->write(json_encode(['desks' => $date2]));

    return $response
        ->withHeader('Content-Type', 'application/json');
});


$app->post('/reservation', function (Request $request, Response $response, $args) {
    $body = $request->getParsedBody();
    $pin = $body['pin'] ?? '';
    $did = $body['did'] ?? '';
    $uid = $body['uid'] ?? '';
    // Twój kod do połączenia z bazą danych tutaj. Dla przykładu:
    $host = 'localhost';
    $db = 'baza74815_3';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);

    // Zapytanie do bazy danych o PIN
    $stmt = $pdo->prepare("SELECT id, pin FROM booking_uzytkownicy WHERE uid = ?");
$stmt->execute([$uid]); 
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    $storedPin = $user['pin'];
    $userid = $user['id'];
}


    // Porównanie PIN-ów
    if ((string) $storedPin === (string) $pin) {
        // Zapytanie do bazy danych o PIN
        $stmt = $pdo->prepare("SELECT id FROM booking_miejsce WHERE did = ?");
        $stmt->execute([$did]);
        $miejsceid = $stmt->fetchColumn();

        $currentDateTime = date('Y-m-d');
        $stmt = $pdo->prepare("INSERT INTO booking_historia_rezerwacji (numer_miejsca, data_rezerwacji, uzytkownik, potwierdzenie) VALUES (?, ?, ?, ?)");
        $stmt->execute([$miejsceid, $currentDateTime, $userid, 0]);

        $response->getBody()->write(json_encode(['isPinValid' => true]));
        return $response
            ->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['isPinValid' => false]));
        return $response
            ->withHeader('Content-Type', 'application/json');
    }
});





$app->run();
