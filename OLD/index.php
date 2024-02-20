<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

$secretKey = 'secret_key';
$secretKey2 = 'XXX';

$app = AppFactory::create();

$app->addRoutingMiddleware();


// Encryption
function encrypt($text, $secretKey)
{
    return base64_encode(openssl_encrypt($text, 'AES-256-CBC', hash('sha256', $secretKey), OPENSSL_RAW_DATA, substr(hash('sha256', $secretKey), 0, 16)));
}

// Decryption
function decrypt($data, $secretKey)
{
    return openssl_decrypt(base64_decode($data), 'AES-256-CBC', hash('sha256', $secretKey), OPENSSL_RAW_DATA, substr(hash('sha256', $secretKey), 0, 16));
}

function removePolishLetters($string)
{
    $polishLetters = array('ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż', 'Ą', 'Ć', 'Ę', 'Ł', 'Ń', 'Ó', 'Ś', 'Ź', 'Ż');
    $asciiLetters = array('a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z', 'A', 'C', 'E', 'L', 'N', 'O', 'S', 'Z', 'Z');
    return str_replace($polishLetters, $asciiLetters, $string);
}

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Send mail
$app->post('/sendmail', function (Request $request, Response $response, $args) use ($secretKey, $secretKey2) {
    $body = $request->getParsedBody();

    $email = $body['email'] ?? '';
    $tresc = $body['tresc'] ?? '';
    $imieINazwisko = $body['imieINazwisko'];
    $telefon = $body['telefon'];
    $checkbox1 = $body['checkbox1'];
    $checkbox2 = $body['checkbox2'];
    $decryptedEmail = decrypt($body['encryptedEmail'] ?? '', $secretKey);
    $decryptedAttachment = decrypt($body['encryptedAttachment'] ?? '', $secretKey);

    if (empty($email) || empty($tresc) || empty($decryptedEmail) || empty($decryptedAttachment)) {
        $response->getBody()->write(json_encode([
            'error' => 'Błędny link.',
        ]));
        return $response->withStatus(400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($decryptedEmail, FILTER_VALIDATE_EMAIL)) {
        $response->getBody()->write(json_encode([
            'error' => 'Nieprawidłowy format e-maila.',
        ]));
        return $response->withStatus(400);
    }
    $attachmentPath = __DIR__ . "/attachments/" . $decryptedAttachment . ".docx";

    // Sprawdź czy plik załącznika istnieje
    if (!file_exists($attachmentPath)) {
        $response->getBody()->write(json_encode([
            'error' => 'Nie znaleziono załącznika.',
        ]));
        return $response->withStatus(400);
    }
    $captchaToken = $body['recaptcha'] ?? '';
    $secretKey2 = "XXX;
    $verifyResponse = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secretKey2) . '&response=' . urlencode($captchaToken));
    $responseData = json_decode($verifyResponse);

    if (!$responseData->success) {
        $response->getBody()->write(json_encode([
            'error' => 'Token captcha nieprawidłowy!',
        ]));
        return $response->withStatus(400);
    }
    $message = "
    <h1>Dziękujemy za kontakt, {$imieINazwisko}!</h1>
    <p>Treść twojej wiadomości: {$tresc}</p>
    <p>Telefon: {$telefon}</p>
    <p>Zaznaczono pola:</p>
    <ul>
    <li>Checkbox 1: " . ($checkbox1 == 'on' ? 'Tak' : 'Nie') . "</li>
    <li>Checkbox 2: " . ($checkbox2 == 'on' ? 'Tak' : 'Nie') . "</li>
    </ul>
";


    $mail = new PHPMailer;
    $mail->IsSMTP();
    $mail->Debugoutput = function ($str, $level) {
        error_log($str . "\n", 3, "my-script.log"); };
    $mail->SMTPDebug = 2;
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );



    if (!$mail->send()) {
        $response->getBody()->write(json_encode([
            'error' => 'Nie udało się wysłać wiadomości',
        ]));
        return $response->withStatus(500);
    } else {
        $response->getBody()->write(json_encode([
            'message' => 'Wiadomość wysłana',
        ]));
        return $response;
    }
});



$app->post('/getEncryptedEmail', function (Request $request, Response $response, $args) use ($secretKey) {
    $body = $request->getParsedBody();

    error_log(print_r($body, true));

    $email = $body['email'];
    $attachment = $body['attachment'];

    $encryptedEmail = encrypt($email, $secretKey);
    $encryptedAttachment = encrypt($attachment, $secretKey);

    $payload = json_encode(['encryptedEmail' => "$encryptedEmail", 'encryptedAttachment' => $encryptedAttachment]);

    $response->getBody()->write($payload);
    return $response->withHeader('Content-Type', 'application/json');
});


$app->run();