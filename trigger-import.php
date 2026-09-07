<?php
/**
 * ============================================================
 *  Li.Fe. Radio — TRIGGER GIORNALIERO import notizie
 *  File: tools/trigger-import.php  (da GitHub Actions o cron CLI)
 *  Sito: https://liferadio.rf.gd/
 * ============================================================
 *
 *  PERCHÉ ESISTE QUESTO FILE
 *  -------------------------
 *  InfinityFree protegge i siti gratuiti con una "challenge"
 *  JavaScript anti-bot: le richieste provenienti da servizi
 *  automatici (cron esterni, GitHub Actions, bot) ricevono una
 *  pagina HTML con JavaScript invece dello script PHP.
 *  Risultato: l'import giornaliero NON partiva mai.
 *
 *  Questo tool:
 *   1. fa la prima richiesta al sito;
 *   2. se riceve la challenge, decifra il cookie __test
 *      (AES-128-CBC, come fa il browser) e ripete la richiesta;
 *   3. lancia l'import: api/import-allmusicitalia.php
 *      (che aggiunge le notizie nuove e pulisce quelle più
 *      vecchie di 30 giorni);
 *   4. stampa il report ed esce con codice 0 (ok) o 1 (errore),
 *      così GitHub Actions segnala i fallimenti via email.
 *
 *  USO (CLI):
 *      php tools/trigger-import.php
 *      php tools/trigger-import.php key=TUACHIAVE
 *      php tools/trigger-import.php check      → solo test bypass (GET innocuo)
 *
 *  USO (GitHub Actions): vedi .github/workflows/import-notizie.yml
 * ============================================================
 */

/* ---------- CONFIGURAZIONE ---------- */
$SITE = 'https://liferadio.rf.gd';          // dominio del sito
$KEY  = 'BARTOLO1';                          // stessa key dell'import (cambiala se la cambi nei PHP)
$UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

/* ---------- PARAMETRI CLI ---------- */
$args = [];
foreach (array_slice($GLOBALS['argv'], 1) as $a) {
    if (mb_strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $args[$k] = $v; }
    else { $args[] = $a; }
}
$mode = isset($args[0]) ? $args[0] : 'import';   // 'import' | 'check'
if (isset($args['key']))  $KEY  = $args['key'];
if (isset($args['site'])) $SITE = rtrim($args['site'], '/');

echo "=== Li.Fe. Radio — Trigger import notizie ===\n";
echo "Sito: $SITE\n";
echo "Modalità: " . ($mode === 'check' ? 'CHECK (test bypass, nessuna modifica)' : 'IMPORT (notizie nuove + pulizia 30 giorni)') . "\n\n";

/* ---------- HTTP con gestione challenge InfinityFree ---------- */

function trToNumbers($d) { $e = []; foreach (str_split($d, 2) as $p) $e[] = hexdec($p); return $e; }
function trToHex($d) { $e = ''; foreach ($d as $v) $e .= sprintf('%02x', $v); return $e; }

/** Decifra il cookie __test dalla pagina challenge. Ritorna il cookie o null. */
function trSolveChallenge($html) {
    if (!preg_match('~toNumbers\("([0-9a-f]{32})"\),b=toNumbers\("([0-9a-f]{32})"\),c=toNumbers\("([0-9a-f]{32})"\)~', $html, $m)) return null;
    $key = implode('', array_map('chr', trToNumbers($m[1])));
    $iv  = implode('', array_map('chr', trToNumbers($m[2])));
    $ct  = implode('', array_map('chr', trToNumbers($m[3])));
    $dec = openssl_decrypt($ct, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($dec === false) return null;
    return bin2hex($dec);   // il cookie __test è l'hex dei byte decifrati
}

/** GET con bypass automatico della challenge. Ritorna [ok, body, httpCode]. */
function trGet($url, $ua, $cookie = null) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json;q=0.9,*/*;q=0.8'],
    ];
    if ($cookie) $opts[CURLOPT_COOKIE] = '__test=' . $cookie;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body !== false, $body === false ? '' : $body, $code];
}

/** Esegue la richiesta risolvendo la challenge se serve (max 3 tentativi). */
function trSmartGet($url, $ua, &$cookieOut) {
    $cookieOut = null;
    for ($i = 1; $i <= 3; $i++) {
        [$ok, $body, $code] = trGet($url, $ua, $cookieOut);
        if (!$ok) { echo "  [tentativo $i] errore di rete (curl), riprovo...\n"; sleep(3); continue; }

        // challenge rilevata? → risolvi e ripeti
        if (mb_strpos($body, 'aes.js') !== false || mb_strpos($body, 'toNumbers(') !== false) {
            $cookie = trSolveChallenge($body);
            if ($cookie === null) { echo "  [tentativo $i] challenge non risolvibile (AES fallito), riprovo...\n"; sleep(3); continue; }
            echo "  [tentativo $i] challenge anti-bot risolta, ripeto la richiesta con cookie __test\n";
            $cookieOut = $cookie;
            sleep(1);
            [$ok2, $body2, $code2] = trGet($url, $ua, $cookieOut);
            if ($ok2) return [$body2, $code2];
            continue;
        }
        return [$body, $code];
    }
    return [false, 0];
}

/* ---------- ESECUZIONE ---------- */

if ($mode === 'check') {
    // Test innocuo: leggere una notizia dall'archivio (nessuna scrittura)
    [$body, $code] = trSmartGet($SITE . '/api/get-news.php?limit=1', $UA, $cookie);
    if ($body === false) { echo "FALLITO: impossibile raggiungere il sito.\n"; exit(1); }
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['success']) && $j['success']) {
        echo "BYPASS OK — il sito risponde al client automatico.\n";
        echo "Notizie in archivio: " . (isset($j['count']) ? $j['count'] : '?') . "\n";
        echo "STATO: OK\n";
        exit(0);
    }
    echo "FALLITO: risposta inattesa (HTTP $code):\n" . mb_substr((string)$body, 0, 300) . "\n";
    exit(1);
}

// Modalità IMPORT: richiama l'endpoint con la key
$url = $SITE . '/api/import-allmusicitalia.php?key=' . rawurlencode($KEY);
[$body, $code] = trSmartGet($url, $UA, $cookie);

if ($body === false) {
    echo "FALLITO: impossibile raggiungere l'endpoint di import dopo 3 tentativi.\n";
    exit(1);
}

// Verifica che sia davvero il report dell'import e non una pagina HTML
if (mb_strpos($body, '=== Li.Fe. Radio') === false) {
    echo "FALLITO: risposta inattesa (HTTP $code):\n" . mb_substr((string)$body, 0, 400) . "\n";
    exit(1);
}

echo $body . "\n";
echo "STATO FINALE: IMPORT COMPLETATO\n";
exit(0);
