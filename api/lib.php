<?php
/* Funções comuns do Dive Manager (servidor). */

function dc_json($dados, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function dc_erro($msg, $codigo = 400) { dc_json(['erro' => $msg], $codigo); }

function dc_agora_ms() { return (int) floor(microtime(true) * 1000); }

function dc_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function dc_norm($s) {
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'
    ]);
}

function dc_pdo($cfg) {
    $dsn = 'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_nome'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $cfg['db_usuario'], $cfg['db_senha'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function dc_criar_tabelas($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dc_docs (
        col VARCHAR(40) NOT NULL, id VARCHAR(64) NOT NULL, data MEDIUMTEXT NOT NULL, upd BIGINT NOT NULL,
        PRIMARY KEY (col, id), KEY idx_upd (upd)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dc_del (
        col VARCHAR(40) NOT NULL, id VARCHAR(64) NOT NULL, upd BIGINT NOT NULL,
        PRIMARY KEY (col, id), KEY idx_upd (upd)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dc_senhas (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY, hash VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dc_sessoes (
        token CHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(64) NOT NULL, expira BIGINT NOT NULL, KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dc_falhas (
        chave VARCHAR(120) NOT NULL PRIMARY KEY, n INT NOT NULL, ate BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ---------- permissões (espelham o que a tela mostra) ---------- */
function dc_modulos() {
    return ['painel','agenda','clientes','cursos','saidas','passeios','locacoes','os','vendas','estoque','equipamentos','embarcacoes','precos','termos','usuarios','config'];
}
function dc_colecao_modulo() {
    return [
        'clientes'=>'clientes','certificacoes'=>'clientes','cursos'=>'cursos','turmas'=>'cursos','matriculas'=>'cursos',
        'saidas'=>'saidas','participantes'=>'saidas','passeios'=>'passeios','roteiros'=>'passeios','reservas'=>'passeios',
        'locacoes'=>'locacoes','ordens'=>'os','vendas'=>'vendas','produtos'=>'estoque','movimentacoes'=>'estoque',
        'equipamentos'=>'equipamentos','embarcacoes'=>'embarcacoes','precos'=>'precos','termos'=>'termos','tarefas'=>'agenda',
        'usuarios'=>'usuarios','config'=>'config'
    ];
}
function dc_papel_base($papel) {
    $todos = function ($n) { $o = []; foreach (dc_modulos() as $m) $o[$m] = $n; return $o; };
    switch ($papel) {
        case 'Administrador': return $todos('editar');
        case 'Gerente': $p = $todos('editar'); $p['usuarios'] = 'ver'; return $p;
        case 'Instrutor / Guia':
            $p = $todos('nenhum');
            foreach (['painel'=>'ver','agenda'=>'editar','clientes'=>'ver','cursos'=>'editar','saidas'=>'editar','passeios'=>'ver',
                'locacoes'=>'editar','equipamentos'=>'ver','embarcacoes'=>'ver','os'=>'ver','termos'=>'ver'] as $k=>$v) $p[$k] = $v;
            return $p;
        case 'Atendimento / Recepção':
            $p = $todos('nenhum');
            foreach (['painel'=>'ver','agenda'=>'editar','clientes'=>'editar','cursos'=>'editar','saidas'=>'editar','passeios'=>'editar',
                'locacoes'=>'editar','vendas'=>'editar','estoque'=>'ver','equipamentos'=>'ver','embarcacoes'=>'ver','termos'=>'ver'] as $k=>$v) $p[$k] = $v;
            return $p;
        case 'Financeiro':
            $p = $todos('ver'); $p['vendas'] = 'editar'; $p['estoque'] = 'editar'; $p['os'] = 'editar'; $p['usuarios'] = 'nenhum'; return $p;
        case 'Somente leitura':
            $p = $todos('ver'); $p['usuarios'] = 'nenhum'; $p['config'] = 'nenhum'; return $p;
        default: return $todos('nenhum');
    }
}
function dc_nivel($user, $modulo) {
    if (!$user || (isset($user['ativo']) && $user['ativo'] === false) || ($user['papel'] ?? '') === 'Pendente') return 'nenhum';
    if (!empty($user['permissoes'][$modulo])) return $user['permissoes'][$modulo];
    $base = dc_papel_base($user['papel'] ?? '');
    return $base[$modulo] ?? 'nenhum';
}
function dc_pode_ler($user, $col) {
    if ($col === 'config' || $col === 'usuarios') return true;      /* nome, logo e lista de nomes servem a todos */
    $map = dc_colecao_modulo();
    if (!isset($map[$col])) return false;
    $n = dc_nivel($user, $map[$col]);
    return $n === 'ver' || $n === 'editar';
}
function dc_pode_editar($user, $col) {
    $map = dc_colecao_modulo();
    return isset($map[$col]) && dc_nivel($user, $map[$col]) === 'editar';
}

/* ---------- usuários e sessão ---------- */
function dc_usuario_publico($u) {
    unset($u['senhaHash'], $u['salt']);
    return $u;
}
function dc_doc($pdo, $col, $id) {
    $st = $pdo->prepare('SELECT data FROM dc_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    $r = $st->fetch();
    return $r ? json_decode($r['data'], true) : null;
}
function dc_gravar_doc($pdo, $col, $doc) {
    $upd = dc_agora_ms();
    $st = $pdo->prepare('SELECT MAX(upd) m FROM dc_docs');
    $st->execute();
    $m = (int) ($st->fetch()['m'] ?? 0);
    if ($upd <= $m) $upd = $m + 1;
    $pdo->prepare('INSERT INTO dc_docs (col, id, data, upd) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), upd = VALUES(upd)')
        ->execute([$col, $doc['id'], json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $upd]);
    $pdo->prepare('DELETE FROM dc_del WHERE col = ? AND id = ?')->execute([$col, $doc['id']]);
    return $upd;
}
function dc_usuario_da_sessao($pdo) {
    $tok = $_COOKIE['dcs'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $h = hash('sha256', $tok);
    $st = $pdo->prepare('SELECT user_id, expira FROM dc_sessoes WHERE token = ?');
    $st->execute([$h]);
    $s = $st->fetch();
    if (!$s || (int) $s['expira'] < dc_agora_ms()) return null;
    $u = dc_doc($pdo, 'usuarios', $s['user_id']);
    if (!$u || (isset($u['ativo']) && $u['ativo'] === false)) return null;
    return $u;
}
function dc_abrir_sessao($pdo, $userId) {
    $tok = bin2hex(random_bytes(32));
    $dias = 14;
    $exp = dc_agora_ms() + $dias * 86400000;
    $pdo->prepare('DELETE FROM dc_sessoes WHERE expira < ?')->execute([dc_agora_ms()]);
    $pdo->prepare('INSERT INTO dc_sessoes (token, user_id, expira) VALUES (?,?,?)')->execute([hash('sha256', $tok), $userId, $exp]);
    setcookie('dcs', $tok, [
        'expires' => time() + $dias * 86400, 'path' => '/', 'secure' => dc_https(),
        'httponly' => true, 'samesite' => 'Lax'
    ]);
}
function dc_fechar_sessao($pdo) {
    $tok = $_COOKIE['dcs'] ?? '';
    if (preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $pdo->prepare('DELETE FROM dc_sessoes WHERE token = ?')->execute([hash('sha256', $tok)]);
    }
    setcookie('dcs', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => dc_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
