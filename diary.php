<?php
// diary.php - Diario di bordo: sintesi giornaliere dei contatti.
// Pubblico in lettura (solo voci pubblicate). Rigenerazione digest,
// pubblicazione e narrativa assistita da IA: riservate all'admin.
require_once __DIR__ . '/auth.php';
auth_bootstrap();
log_access();
require_once __DIR__ . '/diary_lib.php';
require_once __DIR__ . '/ai_lib.php';

$dbPath = __DIR__ . '/events.db';
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(5000);
diary_ensure_schema($db);

$is_admin = (current_role() === 'admin');
$msg = (string) ($_GET['m'] ?? '');
$err = (string) ($_GET['e'] ?? '');

// --- Azioni admin (POST) ---------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$is_admin) {
        http_response_code(403);
        exit('403');
    }
    require_csrf();
    $day = (string) ($_POST['day'] ?? '');
    $act = (string) ($_POST['action'] ?? '');
    if (!diary_valid_day($day)) {
        $err = 'Data non valida.';
    } else {
        try {
            if ($act === 'refresh') {
                diary_store_digest($db, $day, diary_build_digest($db, $day));
                $msg = "Digest del $day rigenerato.";
            } elseif ($act === 'publish') {
                diary_ensure_day($db, $day);
                $now = gmdate('Y-m-d H:i:s') . ' UTC';
                $s = $db->prepare("UPDATE diary_days SET published = 1, published_at = ?, updated_at = ? WHERE day = ?");
                $s->bindValue(1, $now, SQLITE3_TEXT);
                $s->bindValue(2, $now, SQLITE3_TEXT);
                $s->bindValue(3, $day, SQLITE3_TEXT);
                $s->execute();
                $msg = "Voce del $day pubblicata nel diario.";
            } elseif ($act === 'unpublish') {
                $s = $db->prepare("UPDATE diary_days SET published = 0, updated_at = ? WHERE day = ?");
                $s->bindValue(1, gmdate('Y-m-d H:i:s') . ' UTC', SQLITE3_TEXT);
                $s->bindValue(2, $day, SQLITE3_TEXT);
                $s->execute();
                $msg = "Voce del $day ritirata dal diario pubblico.";
            } elseif ($act === 'narrative') {
                if ((ROLE_RANK[current_role()] ?? 0) < (ROLE_RANK[ai_min_role()] ?? 99)) {
                    $err = 'Permessi insufficienti per la sintesi IA.';
                } elseif (!ai_enabled()) {
                    $err = 'Assistente IA non configurato.';
                } else {
                    @set_time_limit(0);
                    ignore_user_abort(true);
                    $row = diary_ensure_day($db, $day);
                    $digest = json_decode($row['digest_json'], true) ?: [];
                    $res = ai_generate($digest);
                    if (!$res['ok']) {
                        $err = 'Sintesi non generata: ' . $res['error'];
                    } else {
                        $s = $db->prepare(
                            "UPDATE diary_days SET narrative_md = ?, narrative_model = ?,
                                    narrative_generated_at = ?, updated_at = ? WHERE day = ?"
                        );
                        $now = gmdate('Y-m-d H:i:s') . ' UTC';
                        $s->bindValue(1, $res['text'], SQLITE3_TEXT);
                        $s->bindValue(2, $res['model'], SQLITE3_TEXT);
                        $s->bindValue(3, $now, SQLITE3_TEXT);
                        $s->bindValue(4, $now, SQLITE3_TEXT);
                        $s->bindValue(5, $day, SQLITE3_TEXT);
                        $s->execute();
                        $extra = !empty($res['stats']['seconds']) ? " ({$res['stats']['seconds']}s)" : '';
                        $msg = "Sintesi IA generata per il $day{$extra}. Rileggila prima di pubblicare.";
                    }
                }
            } elseif ($act === 'narrative_save') {
                $txt = trim((string) ($_POST['narrative_md'] ?? ''));
                $s = $db->prepare("UPDATE diary_days SET narrative_md = ?, updated_at = ? WHERE day = ?");
                $s->bindValue(1, $txt !== '' ? $txt : null, SQLITE3_TEXT);
                $s->bindValue(2, gmdate('Y-m-d H:i:s') . ' UTC', SQLITE3_TEXT);
                $s->bindValue(3, $day, SQLITE3_TEXT);
                $s->execute();
                $msg = $txt !== '' ? "Testo della sintesi del $day salvato." : "Sintesi del $day svuotata.";
            } elseif ($act === 'narrative_clear') {
                $s = $db->prepare("UPDATE diary_days SET narrative_md = NULL, narrative_model = NULL,
                                    narrative_generated_at = NULL, updated_at = ? WHERE day = ?");
                $s->bindValue(1, gmdate('Y-m-d H:i:s') . ' UTC', SQLITE3_TEXT);
                $s->bindValue(2, $day, SQLITE3_TEXT);
                $s->execute();
                $msg = "Sintesi IA del $day eliminata.";
            } else {
                $err = 'Azione sconosciuta.';
            }
        } catch (Throwable $e) {
            error_log('diary.php action: ' . $e->getMessage());
            $err = 'Operazione non riuscita.';
        }
    }
    $q = 'diary.php';
    if (diary_valid_day($day)) {
        $q .= '?day=' . urlencode($day);
        $q .= ($msg ? '&m=' : '&e=') . urlencode($msg ?: $err);
    } else {
        $q .= '?' . ($msg ? 'm=' : 'e=') . urlencode($msg ?: $err);
    }
    header('Location: ' . $q);
    exit;
}

$day = (string) ($_GET['day'] ?? '');
$detail = diary_valid_day($day);

// --- Recupero dati -----------------------------------------------------------
$row = null;
$digest = null;
$forbidden = false;

if ($detail) {
    if ($is_admin) {
        $refresh = isset($_GET['refresh']);
        try {
            $row = diary_ensure_day($db, $day, $refresh);
        } catch (Throwable $e) {
            error_log('diary.php ensure: ' . $e->getMessage());
            $err = $err ?: 'Impossibile calcolare la sintesi del giorno.';
        }
    } else {
        $row = diary_get($db, $day);
        if (!$row || (int) $row['published'] !== 1) {
            $forbidden = true;
            $row = null;
            http_response_code(404);
        }
    }
    if ($row) {
        $digest = json_decode($row['digest_json'], true) ?: [];
    }
}

$list = $detail ? [] : diary_recent($db, !$is_admin);

// --- Helper di rendering -------------------------------------------------------
function d_h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function d_bar($v, $max, int $w = 120): string {
    $px = $max > 0 ? max(2, (int) round($w * $v / max(1, $max))) : 2;
    return '<span class="bar" style="width:' . $px . 'px"></span>';
}

/** "d/m/Y" ita da "YYYY-MM-DD". */
function d_day_it(string $ymd): string {
    $t = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $t ? $t->format('d/m/Y') : $ymd;
}

$page_title = $detail ? ('Diario · ' . d_day_it($day)) : 'Diario di bordo';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= d_h($page_title) ?> — MILAIR ITA</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; align-items: start; margin-bottom: 30px; }
        .stats-card { border: 1px solid #dee2e6; border-radius: 8px; padding: 12px 14px; overflow-x: auto; background: #fff; }
        .stats-card h2 { margin: 0 0 8px; font-size: 1rem; }
        .stats-card table { font-size: 0.86rem; width: 100%; }
        .stats-card th, .stats-card td { padding: 4px 6px; white-space: nowrap; }
        .stats-card td:first-child { white-space: normal; }
        .stats-card td .bar { max-width: 90px; }
        .bar { background: #007bff; height: 10px; border-radius: 3px; display: inline-block; vertical-align: middle; max-width: 100%; }
        .op-logo { height: 15px; width: auto; vertical-align: middle; margin-right: 4px; }
        .flag-icon { height: 14px; width: auto; vertical-align: middle; margin-right: 4px; }
        .muted { color: #6c757d; font-size: 0.9em; }
        .kpi-row { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 20px; }
        .kpi { border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px 14px; min-width: 120px; }
        .kpi .n { font-size: 1.5rem; font-weight: bold; }
        .kpi .l { color: #6c757d; font-size: 0.82rem; }
        .diary-jump { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 10px 0; }
        .diary-admin { border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 14px; margin: 12px 0 18px; background: #fafbfc; }
        .diary-admin form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .diary-admin hr { width: 100%; border: 0; border-top: 1px solid #e0e0e0; margin: 12px 0; }
        .diary-admin textarea { width: 100%; box-sizing: border-box; font: 0.86rem/1.4 ui-monospace, Menlo, Consolas, monospace; padding: 8px; margin: 4px 0 8px; }
        .diary-admin .ai-edit > div { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .diary-admin .btn-primary { background: #007BFF; border-color: #0069d9; color: #fff; font-weight: 600; }
        .diary-admin .btn-warn { background: #e0a800; border-color: #c69500; color: #fff; font-weight: 600; }
        .diary-narrative { border: 1px solid #d5e3f0; background: #f5f9fd; border-radius: 8px; padding: 4px 18px 12px; margin: 14px 0 20px; }
        .diary-narrative h3 { font-size: 1.02rem; margin: 14px 0 6px; }
        .ai-note { font-size: 0.8rem; color: #6c757d; border-top: 1px solid #d5e3f0; padding-top: 8px; margin-top: 12px; }
        .badge-pub { color: #155724; font-weight: bold; font-size: 0.82rem; }
        .badge-draft { color: #856404; font-weight: bold; font-size: 0.82rem; }
        table.diary-list td, table.diary-list th { padding: 5px 9px; }
        .msg { padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; }
        .msg.ok { background: #d4edda; color: #155724; }
        .msg.err { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <?php render_nav('diary.php'); ?>

    <?php if ($msg !== ''): ?><div class="msg ok"><?= d_h($msg) ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="msg err"><?= d_h($err) ?></div><?php endif; ?>

<?php if (!$detail): /* ================= LISTA ================= */ ?>

    <h1>📓 Diario di bordo</h1>
    <p class="muted">Sintesi giornaliera dei contatti rilevati: aggregati, ricorrenze e
        segnalazioni. Una voce per giorno (fuso orario Europe/Rome).</p>

    <?php if ($is_admin): ?>
    <form method="get" action="diary.php" class="diary-jump">
        <label>Apri un giorno:
            <input type="date" name="day" value="<?= d_h(diary_today()) ?>" max="<?= d_h(diary_today()) ?>">
        </label>
        <button type="submit" class="btn">Vai</button>
        <a class="btn" href="diary.php?day=<?= d_h(diary_today()) ?>">Oggi</a>
        <a class="btn" href="diary.php?day=<?= d_h((new DateTime('yesterday', new DateTimeZone('Europe/Rome')))->format('Y-m-d')) ?>">Ieri</a>
    </form>
    <p class="muted">Come admin vedi anche le bozze non pubblicate.</p>
    <?php endif; ?>

    <?php if (!$list): ?>
        <p>Nessuna voce<?= $is_admin ? '' : ' pubblicata' ?> nel diario.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="diary-list">
        <thead><tr>
            <th>Giorno</th><th>Contatti</th><th>Principali</th><th>Sintesi</th>
            <?php if ($is_admin): ?><th>Stato</th><?php endif; ?>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $r):
            $d  = $r['day'];
            $dg = json_decode($r['digest_json'], true) ?: [];
            $tps = [];
            foreach (($dg['by_model'] ?? []) as $t) { $tps[] = $t['model_t'] . ' ' . $t['n']; }
        ?>
            <tr>
                <td><a href="diary.php?day=<?= d_h($d) ?>"><?= d_h(d_day_it($d)) ?></a></td>
                <td><?= number_format((int) $r['event_count']) ?></td>
                <td class="muted"><?= d_h(implode(' · ', array_slice($tps, 0, 4))) ?></td>
                <td><?= ((int) $r['has_narrative']) ? '📝' : '—' ?></td>
                <?php if ($is_admin): ?>
                <td><?= ((int) $r['published']) ? '<span class="badge-pub">pubblicata</span>' : '<span class="badge-draft">bozza</span>' ?></td>
                <?php endif; ?>
                <td><a href="diary.php?day=<?= d_h($d) ?>">Apri →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

<?php elseif ($forbidden): /* ============ 404 voce non pubblica ============ */ ?>

    <h1>Voce non disponibile</h1>
    <p>La voce di diario del <strong><?= d_h(d_day_it($day)) ?></strong> non è ancora
       stata pubblicata.</p>
    <p><a href="diary.php">← Torna al diario</a></p>

<?php elseif (!$row || !$digest): /* ============ errore ============ */ ?>

    <h1>Diario · <?= d_h(d_day_it($day)) ?></h1>
    <p>Impossibile mostrare la sintesi di questo giorno.</p>
    <p><a href="diary.php">← Torna al diario</a></p>

<?php else: /* ================= DETTAGLIO GIORNO ================= */
    $t = $digest['totals'] ?? [];
    $hours = $digest['by_hour'] ?? array_fill(0, 24, 0);
    $hmax = max(1, max($hours ?: [1]));
?>

    <p style="margin-bottom:6px;"><a href="diary.php">← Tutte le voci</a></p>
    <h1>📓 <?= d_h(d_day_it($day)) ?></h1>
    <p class="muted">
        Finestra: <?= d_h($digest['window_utc'][0] ?? '') ?> → <?= d_h($digest['window_utc'][1] ?? '') ?> UTC.
        Digest generato: <?= d_h(format_date_it(str_replace('T', ' ', substr((string) ($digest['generated_at'] ?? ''), 0, 19)))) ?>.
        <?php if ($is_admin): ?>
            Stato: <?= ((int) $row['published']) ? '<strong>pubblicata</strong>' : '<strong>bozza</strong>' ?>.
        <?php endif; ?>
    </p>

    <?php if ($is_admin): $has_narr = !empty($row['narrative_md']); ?>
    <div class="diary-admin">
        <form method="post" action="diary.php">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <button type="submit" name="action" value="refresh" class="btn">↻ Rigenera digest</button>
            <?php if ((int) $row['published']): ?>
                <button type="submit" name="action" value="unpublish" class="btn btn-warn"
                        onclick="return confirm('Ritirare la voce del <?= d_h($day) ?> dal diario pubblico?')">Ritira dal diario</button>
            <?php else: ?>
                <button type="submit" name="action" value="publish" class="btn btn-primary"
                        onclick="return confirm('Pubblicare la voce del <?= d_h($day) ?> nel diario pubblico?')">Pubblica nel diario</button>
            <?php endif; ?>
        </form>

        <?php if (ai_enabled()): ?>
        <hr>
        <form method="post" action="diary.php" class="ai-gen"
              onsubmit="var b=this.querySelector('button');b.textContent='Generazione in corso… (può richiedere qualche minuto)';b.disabled=true;">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <input type="hidden" name="action" value="narrative">
            <button type="submit" class="btn">
                <?= $has_narr ? '🧠 Rigenera sintesi IA' : '🧠 Genera sintesi IA' ?>
            </button>
            <span class="muted">Modello locale · la bozza va riletta prima della pubblicazione.</span>
        </form>

        <?php if ($has_narr): ?>
        <form method="post" action="diary.php" class="ai-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="day" value="<?= d_h($day) ?>">
            <label class="muted" for="nmd">Testo della sintesi (Markdown) — modificabile:</label>
            <textarea id="nmd" name="narrative_md" rows="12"><?= d_h($row['narrative_md']) ?></textarea>
            <div>
                <button type="submit" name="action" value="narrative_save" class="btn btn-primary">Salva modifiche</button>
                <button type="submit" name="action" value="narrative_clear" class="btn btn-warn"
                        onclick="return confirm('Eliminare la sintesi del <?= d_h($day) ?>?')">Elimina sintesi</button>
                <span class="muted">
                    <?= $row['narrative_model'] ? d_h($row['narrative_model']) . ' · ' : '' ?>
                    generata <?= d_h(format_date_it($row['narrative_generated_at'])) ?>
                </span>
            </div>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <p class="muted">Assistente IA non configurato (<code>ai_secrets.php</code>).</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $show_narr = !empty($row['narrative_md']) && ((int) $row['published'] === 1 || $is_admin);
    if ($show_narr):
        $pub = (int) $row['published'] === 1;
    ?>
    <div class="diary-narrative">
        <?php if ($is_admin && !$pub): ?><p class="ai-note" style="border:0;margin:0 0 8px;padding:0;"><strong>Anteprima bozza</strong> — non ancora visibile al pubblico.</p><?php endif; ?>
        <?= diary_md_to_html($row['narrative_md']) ?>
        <p class="ai-note">Sintesi redatta con assistenza IA locale<?= $row['narrative_model'] ? ' (' . d_h($row['narrative_model']) . ')' : '' ?> e rivista da un operatore. I dati di riferimento sono il digest qui sotto: verificare sempre sui contatti citati.</p>
    </div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="kpi"><div class="n"><?= number_format((int) ($t['events'] ?? 0)) ?></div><div class="l">contatti</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['distinct_hex'] ?? 0)) ?></div><div class="l">velivoli distinti</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['rare'] ?? 0)) ?></div><div class="l">rari (Mythic/Legendary)</div></div>
        <div class="kpi"><div class="n"><?= number_format((int) ($t['emergencies'] ?? 0)) ?></div><div class="l">squawk emergenza</div></div>
    </div>

    <?php if ((int) ($t['events'] ?? 0) === 0): ?>
        <p>Nessun contatto registrato in questo giorno.</p>
    <?php else: ?>

    <div class="stats-grid">

        <div class="stats-card">
            <h2>Attività per ora (<?= ($digest['hours_tz'] ?? '') === 'Europe/Rome' ? 'ora italiana' : 'UTC' ?>)</h2>
            <table><?php for ($h = 0; $h < 24; $h++): if ($hours[$h] === 0) continue; ?>
                <tr><td><?= sprintf('%02d', $h) ?></td><td><?= number_format($hours[$h]) ?></td><td><?= d_bar($hours[$h], $hmax, 110) ?></td></tr>
            <?php endfor; ?></table>
        </div>

        <?php if ($digest['by_operator']): ?>
        <div class="stats-card">
            <h2>Forze aeree / compagnie</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_operator']));
            foreach ($digest['by_operator'] as $r): $ol = getOperatorLogo($r['operator']); ?>
                <tr><td><?php if ($ol): ?><img src="<?= d_h($ol) ?>" class="op-logo" alt=""><?php endif; ?><a href="index.php?operator=<?= urlencode($r['operator']) ?>"><?= d_h($r['operator']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>
        <?php endif; ?>

        <?php if ($digest['by_country']): ?>
        <div class="stats-card">
            <h2>Nazionalità</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_country']));
            foreach ($digest['by_country'] as $r): ?>
                <tr><td><a href="index.php?country=<?= urlencode($r['country']) ?>"><?= getFlagHtml($r['country']) ?> <?= d_h($r['country']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>
        <?php endif; ?>

        <?php if ($digest['by_model']): ?>
        <div class="stats-card">
            <h2>Modelli</h2>
            <table><?php $mx = max(array_map(fn($r) => (int) $r['n'], $digest['by_model']));
            foreach ($digest['by_model'] as $r): ?>
                <tr><td><a href="index.php?model=<?= urlencode($r['model_t']) ?>"><?= d_h($r['model_t']) ?></a></td><td><?= number_format($r['n']) ?></td><td><?= d_bar($r['n'], $mx, 90) ?></td></tr>
            <?php endforeach; ?></table>
        </div>
        <?php endif; ?>

        <?php if ($digest['repeat_aircraft']): ?>
        <div class="stats-card">
            <h2>Mezzi ricorrenti nella giornata</h2>
            <table>
                <tr><th>HEX</th><th>Callsign</th><th>Modello</th><th>Naz.</th><th>Avvistamenti</th></tr>
                <?php foreach ($digest['repeat_aircraft'] as $r): ?>
                <tr>
                    <td><a href="index.php?hex=<?= urlencode($r['hex']) ?>"><?= d_h($r['hex']) ?></a></td>
                    <td><?= d_h($r['callsign']) ?></td>
                    <td><?= d_h($r['model_t']) ?></td>
                    <td><?= getFlagHtml($r['country'] ?? '') ?></td>
                    <td><?= (int) $r['n'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['rare_contacts']): ?>
        <div class="stats-card">
            <h2>Contatti rari</h2>
            <table>
                <tr><th>HEX</th><th>Callsign</th><th>Modello</th><th>Naz.</th><th>Rarità</th></tr>
                <?php foreach ($digest['rare_contacts'] as $r): ?>
                <tr>
                    <td><a href="index.php?hex=<?= urlencode($r['hex']) ?>"><?= d_h($r['hex']) ?></a></td>
                    <td><?= d_h($r['callsign'] ?? '') ?></td>
                    <td><?= d_h($r['model_t'] ?? '') ?></td>
                    <td><?= getFlagHtml($r['country'] ?? '') ?></td>
                    <td class="rarity-<?= d_h($r['rarity']) ?>"><?= d_h($r['rarity']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($digest['emergencies']): ?>
        <div class="stats-card">
            <h2>⚠ Squawk di emergenza</h2>
            <table>
                <tr><th>Ora</th><th>HEX</th><th>Callsign</th><th>Squawk</th></tr>
                <?php foreach ($digest['emergencies'] as $r): ?>
                <tr>
                    <td><?= d_h(substr(format_date_it((string) $r['first_seen_utc']), 11, 5)) ?></td>
                    <td><a href="index.php?hex=<?= urlencode($r['hex']) ?>"><?= d_h($r['hex']) ?></a></td>
                    <td><?= d_h($r['callsign']) ?></td>
                    <td><strong title="<?= d_h($r['label']) ?>"><?= d_h($r['squawk']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php
        $has_novelty = $digest['new_operators'] || $digest['new_countries'] || $digest['recurring_callsigns'];
        if ($has_novelty): ?>
        <div class="stats-card">
            <h2>Novità e ricorrenze (vs 14 giorni)</h2>
            <table>
                <?php if ($digest['new_operators']): ?>
                <tr><td>Operatori nuovi</td><td class="muted"><?= d_h(implode(', ', $digest['new_operators'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($digest['new_countries']): ?>
                <tr><td>Nazioni nuove</td><td class="muted"><?= d_h(implode(', ', $digest['new_countries'])) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($digest['recurring_callsigns'] as $r): ?>
                <tr><td><a href="index.php?callsign=<?= urlencode($r['callsign']) ?>"><?= d_h($r['callsign']) ?></a></td><td class="muted"><?= (int) $r['days'] ?> giorni · <?= (int) $r['n'] ?> avvistamenti oggi</td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; /* eventi > 0 */ ?>

<?php endif; /* dettaglio */ ?>

</div>
</body>
</html>
