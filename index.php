<?php
declare(strict_types=1);

/* ============================================
   FORZA PRO DETAILING - index.php
   Página principal + API (registro, sesión y turnos)
   Requiere PHP 8.0 o superior
   ============================================ */

date_default_timezone_set('America/Bogota');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

const DATA_FILE = __DIR__ . '/data/forza.json';
const TIPOS = ['Automóvil', 'Camioneta', 'Camión', 'Moto'];

/* ---------- Planes de lavado ---------- */
const SERVICES = [
    [
        'id' => 'basico',
        'nombre' => 'Lavado básico',
        'precio' => 25000,
        'duracion' => '30 min',
        'img' => 'img/basico.svg',
        'descripcion' => 'Lavado exterior rápido para mantener tu auto limpio día a día.',
        'incluye' => ['Lavado exterior con shampoo neutro', 'Secado', 'Limpieza de llantas'],
    ],
    [
        'id' => 'completo',
        'nombre' => 'Lavado completo',
        'precio' => 45000,
        'duracion' => '60 min',
        'img' => 'img/completo.svg',
        'descripcion' => 'Exterior e interior, ideal para dejar tu vehículo impecable.',
        'incluye' => ['Todo lo del lavado básico', 'Aspirado interior', 'Limpieza de tablero y vidrios', 'Aromatizante'],
    ],
    [
        'id' => 'polichado',
        'nombre' => 'Polichado',
        'precio' => 90000,
        'duracion' => '2 h',
        'img' => 'img/polichado.svg',
        'descripcion' => 'Pulido y cera para recuperar el brillo original de la pintura.',
        'incluye' => ['Descontaminación de pintura', 'Pulido de carrocería', 'Cera protectora'],
    ],
    [
        'id' => 'detailing',
        'nombre' => 'Detailing',
        'precio' => 180000,
        'duracion' => '4 h',
        'img' => 'img/detailing.svg',
        'descripcion' => 'Tratamiento premium integral de exterior e interior.',
        'incluye' => ['Lavado completo', 'Polichado', 'Limpieza profunda de tapicería', 'Protección cerámica'],
    ],
];

/* ---------- Utilidades ---------- */
function esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function money(int $n): string
{
    return '$' . number_format($n, 0, ',', '.');
}

function clean(mixed $v): string
{
    return trim(strip_tags((string) ($v ?? '')));
}

function horas(): array
{
    $h = [];
    for ($i = 8; $i <= 17; $i++) {
        $h[] = sprintf('%02d:00', $i);
        $h[] = sprintf('%02d:30', $i);
    }
    return $h;
}

function serviceById(string $id): ?array
{
    foreach (SERVICES as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    return null;
}

function validDate(string $f): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
        return false;
    }
    return checkdate((int) substr($f, 5, 2), (int) substr($f, 8, 2), (int) substr($f, 0, 4));
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function publicUser(array $u): array
{
    unset($u['password']);
    return $u;
}

class ApiError extends RuntimeException
{
    public function __construct(string $message, private int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

/* ---------- Almacenamiento (archivo JSON con bloqueo) ---------- */
function emptyData(): array
{
    return ['users' => [], 'turnos' => []];
}

function readData(): array
{
    if (!is_file(DATA_FILE)) {
        return emptyData();
    }
    $data = json_decode((string) file_get_contents(DATA_FILE), true);
    return is_array($data) ? array_merge(emptyData(), $data) : emptyData();
}

/**
 * Lee y escribe el archivo dentro de un bloqueo exclusivo,
 * evitando que dos peticiones simultáneas se pisen.
 */
function transact(callable $fn): mixed
{
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen(DATA_FILE, 'c+');
    flock($fp, LOCK_EX);

    $data = json_decode((string) stream_get_contents($fp), true);
    $data = is_array($data) ? array_merge(emptyData(), $data) : emptyData();

    try {
        $result = $fn($data);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function currentUser(): ?array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    foreach (readData()['users'] as $u) {
        if ($u['id'] === $uid) {
            return $u;
        }
    }
    return null;
}

function userTurnos(string $uid): array
{
    $turnos = array_values(array_filter(
        readData()['turnos'],
        fn($t) => $t['user_id'] === $uid
    ));
    usort($turnos, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));
    return $turnos;
}

/* ============================================
   API
   ============================================ */
function apiRegister(array $in): array
{
    $u = [
        'nombre'    => clean($in['nombre'] ?? ''),
        'documento' => clean($in['documento'] ?? ''),
        'telefono'  => clean($in['telefono'] ?? ''),
        'email'     => strtolower(clean($in['email'] ?? '')),
        'placa'     => strtoupper(clean($in['placa'] ?? '')),
        'marca'     => clean($in['marca'] ?? ''),
    ];
    $password = (string) ($in['password'] ?? '');

    foreach (['nombre', 'documento', 'telefono', 'placa', 'marca'] as $campo) {
        if ($u[$campo] === '') {
            throw new ApiError('Completa todos los campos obligatorios.', 422);
        }
    }
    if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('El correo no es válido.', 422);
    }
    if (strlen($password) < 6) {
        throw new ApiError('La contraseña debe tener al menos 6 caracteres.', 422);
    }

    $user = transact(function (array &$data) use ($u, $password) {
        foreach ($data['users'] as $x) {
            if ($x['email'] === $u['email']) {
                throw new ApiError('Ese correo ya está registrado. Inicia sesión.', 409);
            }
        }
        $u['id'] = bin2hex(random_bytes(8));
        $u['password'] = password_hash($password, PASSWORD_DEFAULT);
        $u['creado'] = date('c');
        $data['users'][] = $u;
        return $u;
    });

    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];

    return ['user' => publicUser($user), 'turnos' => []];
}

function apiLogin(array $in): array
{
    $email = strtolower(clean($in['email'] ?? ''));
    $password = (string) ($in['password'] ?? '');

    $found = null;
    foreach (readData()['users'] as $u) {
        if ($u['email'] === $email) {
            $found = $u;
            break;
        }
    }
    if (!$found || !password_verify($password, $found['password'])) {
        throw new ApiError('Correo o contraseña incorrectos.', 401);
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = $found['id'];

    return ['user' => publicUser($found), 'turnos' => userTurnos($found['id'])];
}

function apiLogout(): array
{
    unset($_SESSION['uid']);
    session_regenerate_id(true);
    return ['ok' => true];
}

function apiState(): array
{
    $user = currentUser();
    return [
        'user'   => $user ? publicUser($user) : null,
        'turnos' => $user ? userTurnos($user['id']) : [],
    ];
}

function apiOcupadas(string $fecha): array
{
    if (!validDate($fecha)) {
        throw new ApiError('Fecha no válida.', 422);
    }
    $ocupadas = [];
    foreach (readData()['turnos'] as $t) {
        if ($t['fecha'] === $fecha && $t['estado'] !== 'Cancelado') {
            $ocupadas[] = $t['hora'];
        }
    }
    return ['ocupadas' => $ocupadas];
}

function apiBook(array $in): array
{
    $user = currentUser();
    if (!$user) {
        throw new ApiError('Debes iniciar sesión para reservar.', 401);
    }

    $servicio = serviceById(clean($in['servicioId'] ?? ''));
    if (!$servicio) {
        throw new ApiError('Lavado no válido.', 422);
    }

    $fecha = clean($in['fecha'] ?? '');
    $hora = clean($in['hora'] ?? '');
    $tipo = clean($in['tipo'] ?? '');
    $placa = strtoupper(clean($in['placa'] ?? ''));
    $vehiculo = clean($in['vehiculo'] ?? '');
    $notas = mb_substr(clean($in['notas'] ?? ''), 0, 300);

    if (!validDate($fecha) || $fecha < date('Y-m-d')) {
        throw new ApiError('Elige una fecha válida (hoy o después).', 422);
    }
    if (!in_array($hora, horas(), true)) {
        throw new ApiError('Elige una hora válida.', 422);
    }
    if (!in_array($tipo, TIPOS, true)) {
        throw new ApiError('Elige un tipo de vehículo válido.', 422);
    }
    if ($placa === '' || $vehiculo === '') {
        throw new ApiError('Completa la placa y la marca del vehículo.', 422);
    }

    $turno = [
        'id'          => 'TR-' . strtoupper(bin2hex(random_bytes(3))),
        'servicio_id' => $servicio['id'],
        'servicio'    => $servicio['nombre'],
        'precio'      => $servicio['precio'],
        'fecha'       => $fecha,
        'hora'        => $hora,
        'placa'       => $placa,
        'vehiculo'    => $vehiculo,
        'tipo'        => $tipo,
        'notas'       => $notas,
        'estado'      => 'Pendiente',
        'user_id'     => $user['id'],
        'creado'      => date('c'),
    ];

    transact(function (array &$data) use ($turno) {
        foreach ($data['turnos'] as $t) {
            if ($t['fecha'] === $turno['fecha'] && $t['hora'] === $turno['hora'] && $t['estado'] !== 'Cancelado') {
                throw new ApiError('Ese horario ya está reservado. Elige otra hora.', 409);
            }
        }
        $data['turnos'][] = $turno;
    });

    return ['turno' => $turno, 'turnos' => userTurnos($user['id'])];
}

function apiCancel(array $in): array
{
    $user = currentUser();
    if (!$user) {
        throw new ApiError('Debes iniciar sesión.', 401);
    }
    $id = clean($in['id'] ?? '');

    transact(function (array &$data) use ($id, $user) {
        foreach ($data['turnos'] as &$t) {
            if ($t['id'] === $id && $t['user_id'] === $user['id']) {
                $t['estado'] = 'Cancelado';
                unset($t);
                return;
            }
        }
        unset($t);
        throw new ApiError('No encontramos ese turno.', 404);
    });

    return ['turnos' => userTurnos($user['id'])];
}

/* ---------- Enrutador de la API ---------- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);

    try {
        $action = (string) $_GET['api'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Protección CSRF: el token debe coincidir con el de la sesión
            if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
                throw new ApiError('La sesión no es válida. Recarga la página.', 403);
            }
            $in = json_decode((string) file_get_contents('php://input'), true);
            $in = is_array($in) ? $in : [];

            $out = match ($action) {
                'register' => apiRegister($in),
                'login'    => apiLogin($in),
                'logout'   => apiLogout(),
                'book'     => apiBook($in),
                'cancel'   => apiCancel($in),
                default    => throw new ApiError('Acción no válida.', 404),
            };
        } else {
            $out = match ($action) {
                'state'    => apiState(),
                'ocupadas' => apiOcupadas((string) ($_GET['fecha'] ?? '')),
                default    => throw new ApiError('Acción no válida.', 404),
            };
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (ApiError $e) {
        http_response_code($e->status());
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error del servidor. Intenta de nuevo.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ============================================
   PÁGINA
   ============================================ */
$user = currentUser();
$turnos = $user ? userTurnos($user['id']) : [];
$primerNombre = $user ? explode(' ', $user['nombre'])[0] : '';

// Datos que JavaScript necesita (sin contraseñas)
$config = [
    'csrf'     => csrfToken(),
    'user'     => $user ? publicUser($user) : null,
    'turnos'   => $turnos,
    'services' => SERVICES,
    'horas'    => horas(),
    'hoy'      => date('Y-m-d'),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Forza Pro Detailing: lavado básico, completo, polichado y detailing para tu vehículo. Reserva tu turno en línea.">
    <meta name="theme-color" content="#0b1220">
    <title>Forza Pro Detailing | Lavadero de autos</title>
    <link rel="icon" href="logo.jpg">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <a href="#planes" class="skip-link">Ir a los planes de lavado</a>

    <!-- ENCABEZADO -->
    <header class="header">
        <div class="container header-inner">
            <a href="#inicio" class="brand" aria-label="Inicio Forza Pro Detailing">
                <img src="logo.jpg" alt="Logo Forza Pro Detailing" width="160" height="90">
            </a>

            <button class="menu-toggle" id="menuToggle" type="button"
                    aria-label="Abrir menú" aria-expanded="false" aria-controls="mainNav">
                <span></span><span></span><span></span>
            </button>

            <nav class="nav" id="mainNav" aria-label="Principal">
                <a href="#inicio">Inicio</a>
                <a href="#planes">Planes</a>
                <a href="#turnos" id="navTurnos" <?= $user ? '' : 'hidden' ?>>Mis turnos</a>
                <a href="#contacto">Contacto</a>
            </nav>

            <div class="user-area">
                <span id="userName" class="user-name" <?= $user ? '' : 'hidden' ?>>
                    <?= $user ? 'Hola, ' . esc($primerNombre) : '' ?>
                </span>
                <button id="btnLogin" class="btn btn-outline btn-sm" type="button" <?= $user ? 'hidden' : '' ?>>Iniciar sesión</button>
                <button id="btnLogout" class="btn btn-outline btn-sm" type="button" <?= $user ? '' : 'hidden' ?>>Salir</button>
            </div>
        </div>
    </header>

    <main>

        <!-- HERO -->
        <section id="inicio" class="hero">
            <div class="container hero-content">
                <span class="badge">Profesional y rápido</span>
                <h1>Tu auto, como nuevo en minutos</h1>
                <p>Escoge el tipo de lavado que necesitas, regístrate o inicia sesión y reserva tu turno en segundos.</p>
                <a href="#planes" class="btn btn-primary">Ver planes de lavado</a>
            </div>
        </section>

        <!-- PLANES DE LAVADO -->
        <section id="planes" class="section">
            <div class="container">
                <h2>Planes de lavado</h2>
                <p class="section-sub">Tenemos diferentes tipos de lavado. Compara, elige el que más te convenga y reserva tu turno.</p>

                <div id="servicesGrid" class="services-grid">
                    <?php foreach (SERVICES as $s): ?>
                        <article class="card">
                            <img src="<?= esc($s['img']) ?>" alt="<?= esc($s['nombre']) ?>" loading="lazy" width="600" height="400">
                            <div class="card-body">
                                <h3><?= esc($s['nombre']) ?></h3>
                                <p class="muted"><?= esc($s['descripcion']) ?></p>
                                <ul>
                                    <?php foreach ($s['incluye'] as $item): ?>
                                        <li><?= esc($item) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="card-meta">
                                    <span class="price"><?= money($s['precio']) ?></span>
                                    <span class="muted">⏱ <?= esc($s['duracion']) ?></span>
                                </div>
                                <button class="btn btn-primary btn-block" type="button" data-service="<?= esc($s['id']) ?>">
                                    Elegir este lavado
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- MIS TURNOS (solo con sesión iniciada) -->
        <section id="turnos" class="section alt" <?= $user ? '' : 'hidden' ?>>
            <div class="container">
                <h2>Mis turnos</h2>
                <div id="turnosList" class="turnos-list"></div>
            </div>
        </section>

        <!-- CONTACTO -->
        <section id="contacto" class="section">
            <div class="container contact-grid">
                <div>
                    <h2>Contacto</h2>
                    <p class="section-sub">Estamos para atenderte. Escríbenos o visítanos.</p>
                    <ul class="contact-list">
                        <li><strong>Teléfono:</strong> 000 000 0000</li>
                        <li><strong>Dirección:</strong> [Dirección del lavadero]</li>
                        <li><strong>Horario:</strong> Lunes a sábado, 8:00 a. m. – 6:00 p. m.</li>
                    </ul>
                </div>
                <div class="contact-card">
                    <h3>Forza Pro Detailing SAS</h3>
                    <p>Lavado profesional, rápido y con los mejores acabados para tu vehículo.</p>
                </div>
            </div>
        </section>

    </main>

    <footer class="footer">
        <p>© <?= date('Y') ?> Forza Pro Detailing SAS. Todos los derechos reservados.</p>
    </footer>

    <!-- MODAL: REGISTRO / INICIO DE SESIÓN -->
    <div id="authModal" class="modal" hidden>
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="authTitle">
            <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
            <h3 id="authTitle" class="sr-only">Acceso</h3>

            <div class="tabs" role="tablist">
                <button class="tab active" type="button" data-tab="register" role="tab" aria-selected="true">Registrarse</button>
                <button class="tab" type="button" data-tab="login" role="tab" aria-selected="false">Iniciar sesión</button>
            </div>

            <form id="registerForm" class="form">
                <label>Nombre completo
                    <input name="nombre" autocomplete="name" required placeholder="Ej. Laura Rodríguez">
                </label>
                <label>Documento
                    <input name="documento" inputmode="numeric" required placeholder="Número de documento">
                </label>
                <label>Teléfono
                    <input name="telefono" type="tel" autocomplete="tel" required placeholder="Número de contacto">
                </label>
                <label>Correo electrónico
                    <input type="email" name="email" autocomplete="email" required placeholder="correo@ejemplo.com">
                </label>
                <label>Contraseña (mínimo 6 caracteres)
                    <input type="password" name="password" autocomplete="new-password" required minlength="6">
                </label>
                <label>Placa de tu vehículo
                    <input name="placa" required placeholder="ABC-123">
                </label>
                <label>Marca y modelo
                    <input name="marca" required placeholder="Ej. Mazda 3">
                </label>
                <button type="submit" class="btn btn-primary btn-block">Crear cuenta y continuar</button>
            </form>

            <form id="loginForm" class="form" hidden>
                <label>Correo electrónico
                    <input type="email" name="email" autocomplete="email" required placeholder="correo@ejemplo.com">
                </label>
                <label>Contraseña
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit" class="btn btn-primary btn-block">Iniciar sesión</button>
            </form>

            <p id="authMsg" class="msg" role="alert"></p>
        </div>
    </div>

    <!-- MODAL: RESERVAR TURNO -->
    <div id="bookingModal" class="modal" hidden>
        <div class="modal-box modal-wide" role="dialog" aria-modal="true" aria-labelledby="bookTitle">
            <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
            <h3 id="bookTitle">Reservar turno</h3>

            <div id="bookingSummary" class="summary"></div>

            <form id="bookingForm" class="form form-grid">
                <label>Fecha
                    <input type="date" name="fecha" required>
                </label>
                <label>Hora
                    <select name="hora" required></select>
                </label>
                <label>Placa
                    <input name="placa" required>
                </label>
                <label>Marca y modelo
                    <input name="vehiculo" required>
                </label>
                <label class="full">Tipo de vehículo
                    <select name="tipo">
                        <?php foreach (TIPOS as $tipo): ?>
                            <option><?= esc($tipo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="full">Notas adicionales
                    <textarea name="notas" rows="2" placeholder="Opcional"></textarea>
                </label>
                <button type="submit" class="btn btn-primary btn-block full">Confirmar turno</button>
            </form>
        </div>
    </div>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <!-- Configuración inicial para JavaScript -->
    <script>
        window.FORZA = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
    </script>
    <script src="script.js" defer></script>
</body>
</html>