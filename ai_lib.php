<?php
// ai_lib.php - Assistente di analisi opzionale tramite server LLM locale (Ollama).
//
// Disattivato di default: si abilita creando ai_secrets.php (vedi
// ai_secrets.php.example) con AI_BASE_URL valorizzato. La chiamata avviene
// SOLO lato server (rete locale); il browser non contatta mai il modello.
// L'URL è preso dalla configurazione, mai da input utente.
//
// L'output è una BOZZA: va riletta da un operatore prima di pubblicarla nel
// diario. Usata da diary.php (azione admin "genera sintesi").

if (!defined('AI_BASE_URL') && file_exists(__DIR__ . '/ai_secrets.php')) {
    require_once __DIR__ . '/ai_secrets.php';
}

/** Parametri effettivi dell'assistente. */
function ai_cfg(): array {
    return [
        'base_url'  => rtrim((string) (defined('AI_BASE_URL') ? AI_BASE_URL : ''), '/'),
        'model'     => (string) (defined('AI_MODEL') ? AI_MODEL : 'qwen2.5:14b'),
        'num_ctx'   => max(2048, (int) (defined('AI_NUM_CTX') ? AI_NUM_CTX : 16384)),
        'timeout_s' => max(30, (int) (defined('AI_TIMEOUT_S') ? AI_TIMEOUT_S : 600)),
        'min_role'  => (string) (defined('AI_MIN_ROLE') ? AI_MIN_ROLE : 'admin'),
    ];
}

/** true se la funzione è configurata (base_url non vuoto) e cURL disponibile. */
function ai_enabled(): bool {
    return ai_cfg()['base_url'] !== '' && function_exists('curl_init');
}

/** Ruolo minimo richiesto per generare la sintesi. */
function ai_min_role(): string {
    $r = ai_cfg()['min_role'];
    return $r !== '' ? $r : 'admin';
}

const AI_SYSTEM_PROMPT = <<<'TXT'
Sei un analista OSINT di traffico aereo militare. Ricevi dati AGGREGATI di
contatti radar (ADS-B) rilevati sopra l'Italia per una singola giornata.
Redigi una sintesi in italiano, in Markdown, di massimo ~400 parole, con
esattamente queste sezioni:

## Highlights
## Contatti insoliti
## Ricorrenze e correlazioni
## Distribuzione geografica e temporale
## Da approfondire

Regole:
- Usa ESCLUSIVAMENTE i dati forniti. Non aggiungere contesto esterno, non
  ipotizzare identità, intenzioni o scenari non desumibili dai numeri.
- Quando citi un contatto usa il suo codice hex reale tra backtick, nella
  forma `HEX123` (es. `33FC98`). Non inventare hex: se un elemento non ha
  un hex nei dati, descrivilo senza backtick.
- In "Ricorrenze e correlazioni" segnala lo stesso mezzo/operatore/nazione
  che compare più volte nella giornata o rispetto ai 14 giorni precedenti.
- Se i dati sono scarsi o assenti, dichiaralo in una riga e fermati.
- Niente preamboli o chiuse: solo le cinque sezioni.
TXT;

/** Messaggio utente: la giornata + il digest (sfoltito) in JSON. */
function ai_user_message(array $digest): string {
    $slim = $digest;
    foreach (['repeat_aircraft', 'rare_contacts', 'by_operator', 'by_country',
              'by_model', 'recurring_callsigns'] as $k) {
        if (!empty($slim[$k]) && is_array($slim[$k])) {
            $slim[$k] = array_slice($slim[$k], 0, 15);
        }
    }
    unset($slim['by_hour']); // poco utile alla narrativa, occupa spazio

    $json = json_encode(
        $slim,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $day = (string) ($digest['day'] ?? '');

    return "Giornata analizzata: {$day} (ora locale Europe/Rome).\n\n"
         . "Dati aggregati (JSON):\n```json\n{$json}\n```\n\n"
         . "Redigi la sintesi seguendo le istruzioni di sistema.";
}

/**
 * Genera la sintesi discorsiva dal digest.
 * Ritorna ['ok'=>bool, 'text'?, 'model'?, 'stats'?, 'error'?].
 */
function ai_generate(array $digest): array {
    $cfg = ai_cfg();
    if ($cfg['base_url'] === '') {
        return ['ok' => false, 'error' => 'Assistente IA non configurato.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Estensione cURL non disponibile.'];
    }

    $payload = [
        'model'    => $cfg['model'],
        'stream'   => false,
        'options'  => [
            'num_ctx'     => $cfg['num_ctx'],
            'temperature' => 0.3,
            'top_p'       => 0.9,
        ],
        'messages' => [
            ['role' => 'system', 'content' => AI_SYSTEM_PROMPT],
            ['role' => 'user',   'content' => ai_user_message($digest)],
        ],
    ];

    $ch = curl_init($cfg['base_url'] . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $cfg['timeout_s'],
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $errs  = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return ['ok' => false, 'error' => "Timeout: il modello non ha risposto entro {$cfg['timeout_s']}s. La workstation è accesa?"];
    }
    if ($errno) {
        return ['ok' => false, 'error' => "Connessione al server modello fallita ({$errs})."];
    }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "Il server modello ha risposto HTTP {$code}."];
    }

    $j = json_decode((string) $raw, true);
    $text = is_array($j) ? trim((string) ($j['message']['content'] ?? '')) : '';
    if ($text === '') {
        return ['ok' => false, 'error' => 'Risposta del modello vuota o non interpretabile.'];
    }

    $stats = [];
    if (isset($j['eval_count'], $j['eval_duration']) && $j['eval_duration'] > 0) {
        $stats['tok_s']  = round($j['eval_count'] / ($j['eval_duration'] / 1e9), 1);
        $stats['tokens'] = (int) $j['eval_count'];
    }
    if (isset($j['total_duration'])) {
        $stats['seconds'] = round($j['total_duration'] / 1e9, 1);
    }

    return ['ok' => true, 'text' => $text, 'model' => $cfg['model'], 'stats' => $stats];
}
