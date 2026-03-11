<?php
/**
 * Queue Operator Page
 * Sherwood Adventure Tournament System
 *
 * Single-page interface for managing a walk-up queue tournament.
 * The operator uses this page to check in teams, start/finish games,
 * and reorder the queue. All actions use AJAX (no page reloads).
 * The page auto-polls every 5 seconds to stay in sync with new signups.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ? AND tournament_type = 'queue'");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Queue tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

$pageTitle = 'Queue: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <div>
            <h1><?php echo h($tournament['name']); ?></h1>
            <div class="flex gap-1" style="margin-top: 8px;">
                <span class="badge badge-type">Queue</span>
                <span class="badge badge-in-progress">
                    <?php echo h(ucwords(str_replace('_', ' ', $tournament['status']))); ?>
                </span>
            </div>
        </div>
        <div class="admin-actions">
            <a href="/display/queue.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-small" target="_blank">Public Display</a>
            <a href="/display/queue-results.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-small" target="_blank">Results Display</a>
            <a href="/admin/tournament-manage.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-small">Back to Manage</a>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="stats-grid" id="stats-bar">
        <div class="stat-card"><div class="stat-number" id="stat-total">-</div><div class="stat-label">Total</div></div>
        <div class="stat-card"><div class="stat-number" id="stat-waiting">-</div><div class="stat-label">Waiting</div></div>
        <div class="stat-card"><div class="stat-number" id="stat-checked-in">-</div><div class="stat-label">Checked In</div></div>
        <div class="stat-card"><div class="stat-number" id="stat-playing">-</div><div class="stat-label">Playing</div></div>
        <div class="stat-card"><div class="stat-number" id="stat-done">-</div><div class="stat-label">Done</div></div>
        <div class="stat-card"><div class="stat-number" id="stat-games">-</div><div class="stat-label">Games Played</div></div>
    </div>

    <!-- Current Game -->
    <div class="form-section" id="current-game-section">
        <h3 class="form-section-title">Current Game</h3>
        <div id="current-game-content">
            <p style="opacity: 0.6;">Loading...</p>
        </div>
    </div>

    <!-- Next Up -->
    <div class="form-section" id="next-up-section">
        <h3 class="form-section-title">Next Up</h3>
        <div id="next-up-content">
            <p style="opacity: 0.6;">Loading...</p>
        </div>
    </div>

    <!-- Queue List -->
    <div class="form-section">
        <h3 class="form-section-title">Queue</h3>
        <div id="queue-list">
            <p style="opacity: 0.6;">Loading...</p>
        </div>
    </div>

    <!-- Past Games -->
    <div class="form-section">
        <h3 class="form-section-title">Past Games</h3>
        <div id="past-games">
            <p style="opacity: 0.6;">Loading...</p>
        </div>
    </div>
</div>

<style>
/* Queue Operator Styles */
.queue-game-card {
    background: var(--color-primary-green);
    border: 2px solid var(--color-gold);
    border-radius: var(--border-radius);
    padding: 24px;
    text-align: center;
}
.queue-game-card .team-names {
    font-family: var(--font-subheading);
    font-size: 24px;
    color: var(--color-gold);
    margin-bottom: 16px;
}
.queue-game-card .vs { color: var(--color-light-gray); font-size: 16px; margin: 0 12px; }
.queue-game-card .score-inputs { display: flex; gap: 12px; justify-content: center; align-items: center; margin: 16px 0; }
.queue-game-card .score-inputs input {
    width: 70px; text-align: center; font-size: 24px; font-weight: 700;
    background: rgba(255,255,255,0.1); border: 1px solid var(--color-gold);
    color: #fff; border-radius: 6px; padding: 8px;
}

.queue-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px; border-radius: 6px;
    border-left: 4px solid transparent;
    margin-bottom: 4px; transition: background 0.2s;
}
.queue-row:hover { background: rgba(255,255,255,0.03); }
.queue-row.status-registered { border-left-color: var(--color-light-gray); }
.queue-row.status-checked_in { border-left-color: var(--color-success); }
.queue-row.status-playing { border-left-color: var(--color-gold); background: rgba(254,214,17,0.05); }
.queue-row.status-eliminated { border-left-color: var(--color-light-gray); opacity: 0.5; }
.queue-pos { font-weight: 700; color: var(--color-gold); width: 30px; text-align: center; }
.queue-team { flex: 1; }
.queue-team .team-name { font-weight: 600; }
.queue-team .team-captain { font-size: 12px; opacity: 0.7; }
.queue-status-badge {
    font-size: 11px; padding: 2px 8px; border-radius: 10px;
    text-transform: uppercase; font-weight: 600;
}
.queue-status-badge.registered { color: var(--color-light-gray); border: 1px solid var(--color-light-gray); }
.queue-status-badge.checked_in { color: var(--color-success); border: 1px solid var(--color-success); }
.queue-status-badge.playing { color: var(--color-gold); border: 1px solid var(--color-gold); }
.queue-status-badge.eliminated { color: var(--color-light-gray); border: 1px solid rgba(255,255,255,0.2); }

.queue-actions { display: flex; gap: 6px; }
.queue-actions .btn { padding: 4px 10px; font-size: 12px; }
.reorder-btns { display: flex; flex-direction: column; gap: 2px; }
.reorder-btns button {
    background: none; border: 1px solid rgba(255,255,255,0.2); color: var(--color-light-gray);
    padding: 1px 6px; border-radius: 3px; cursor: pointer; font-size: 11px; line-height: 1;
}
.reorder-btns button:hover { border-color: var(--color-gold); color: var(--color-gold); }

.next-up-card {
    background: var(--color-primary-green);
    border: 2px dashed var(--color-teal);
    border-radius: var(--border-radius);
    padding: 20px; text-align: center;
}
.next-up-card .team-names {
    font-family: var(--font-subheading); font-size: 20px; color: var(--color-teal);
    margin-bottom: 12px;
}

.past-game-row {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 14px; border-bottom: 1px solid rgba(255,255,255,0.05);
    font-size: 14px;
}
.past-game-row .game-num { color: var(--color-gold); font-weight: 600; width: 50px; }
.past-game-row .game-teams { flex: 1; }
.past-game-row .game-score { color: var(--color-teal); font-weight: 600; }
.past-game-row .game-time { font-size: 12px; opacity: 0.5; width: 80px; text-align: right; }

.no-content { text-align: center; padding: 24px; opacity: 0.6; font-style: italic; }
</style>

<script>
var TOURNAMENT_ID = <?php echo $id; ?>;
var API_URL = '/api/queue-action.php';
var pollTimer = null;

// ============================================================
// API CALLS
// ============================================================

function apiCall(action, data, callback) {
    var body = Object.assign({action: action, tournament_id: TOURNAMENT_ID}, data || {});
    fetch(API_URL, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(body)
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success && result.data) {
            renderState(result.data);
        } else if (!result.success) {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
        if (callback) callback(result);
    })
    .catch(function(err) {
        console.error('API error:', err);
    });
}

function refreshQueue() { apiCall('get_queue'); }

// ============================================================
// ACTIONS
// ============================================================

function checkinTeam(teamId) { apiCall('checkin', {team_id: teamId}); }
function undoCheckin(teamId) { apiCall('undo_checkin', {team_id: teamId}); }

function startGame(team1Id, team2Id) {
    apiCall('start_game', {team1_id: team1Id, team2_id: team2Id});
}

function finishGame(matchId) {
    var s1 = document.getElementById('score-team1');
    var s2 = document.getElementById('score-team2');
    var data = {match_id: matchId};
    if (s1 && s1.value !== '') data.team1_score = parseInt(s1.value, 10);
    if (s2 && s2.value !== '') data.team2_score = parseInt(s2.value, 10);
    apiCall('finish_game', data);
}

function moveTeam(teamId, direction) {
    // Get current state from rendered queue rows
    var rows = document.querySelectorAll('.queue-row[data-team-id]');
    var order = [];
    rows.forEach(function(r) {
        if (r.dataset.status !== 'eliminated') {
            order.push(parseInt(r.dataset.teamId, 10));
        }
    });

    var idx = order.indexOf(teamId);
    if (idx < 0) return;

    if (direction === 'up' && idx > 0) {
        var tmp = order[idx - 1]; order[idx - 1] = order[idx]; order[idx] = tmp;
    } else if (direction === 'down' && idx < order.length - 1) {
        var tmp = order[idx + 1]; order[idx + 1] = order[idx]; order[idx] = tmp;
    } else {
        return;
    }

    var positions = order.map(function(tid, i) { return {team_id: tid, position: i + 1}; });
    apiCall('reorder', {positions: positions});
}

// ============================================================
// RENDER
// ============================================================

function renderState(data) {
    // Stats
    var s = data.stats;
    document.getElementById('stat-total').textContent = s.total;
    document.getElementById('stat-waiting').textContent = s.waiting;
    document.getElementById('stat-checked-in').textContent = s.checked_in;
    document.getElementById('stat-playing').textContent = s.playing;
    document.getElementById('stat-done').textContent = s.done;
    document.getElementById('stat-games').textContent = s.games_played;

    // Current Game
    var cgEl = document.getElementById('current-game-content');
    if (data.active_match) {
        var m = data.active_match;
        cgEl.innerHTML =
            '<div class="queue-game-card">' +
                '<div class="team-names">' +
                    esc(m.team1_name) + '<span class="vs"> vs </span>' + esc(m.team2_name) +
                '</div>' +
                '<div class="score-inputs">' +
                    '<input type="number" id="score-team1" min="0" placeholder="0" value="' + (m.team1_score || '') + '">' +
                    '<span style="font-size:20px; opacity:0.5;">-</span>' +
                    '<input type="number" id="score-team2" min="0" placeholder="0" value="' + (m.team2_score || '') + '">' +
                '</div>' +
                '<p style="font-size:12px; opacity:0.5; margin-bottom:12px;">Scores are optional</p>' +
                '<button class="btn btn-primary" onclick="finishGame(' + m.id + ')">Finish Game</button>' +
            '</div>';
    } else {
        cgEl.innerHTML = '<p class="no-content">No game in progress</p>';
    }

    // Next Up
    var nuEl = document.getElementById('next-up-content');
    if (data.active_match) {
        // A game is active — can't start another
        nuEl.innerHTML = '<p class="no-content">Finish the current game first</p>';
    } else if (data.next_suggested) {
        var ns = data.next_suggested;
        nuEl.innerHTML =
            '<div class="next-up-card">' +
                '<div class="team-names">' +
                    esc(ns.team1.team_name) + '<span class="vs" style="color:var(--color-light-gray);"> vs </span>' + esc(ns.team2.team_name) +
                '</div>' +
                '<button class="btn btn-primary btn-large" onclick="startGame(' + ns.team1.id + ',' + ns.team2.id + ')">Start Game</button>' +
            '</div>';
    } else {
        var checkedCount = data.waiting_checked_in ? data.waiting_checked_in.length : 0;
        nuEl.innerHTML = '<p class="no-content">Waiting for teams to check in (' + checkedCount + ' of 2 needed)</p>';
    }

    // Queue List
    var qlEl = document.getElementById('queue-list');
    if (data.teams.length === 0) {
        qlEl.innerHTML = '<p class="no-content">No teams signed up yet</p>';
    } else {
        var html = '';
        data.teams.forEach(function(t) {
            var displayStatus = t.active_match_id ? 'playing' : t.status;
            var statusLabel = displayStatus === 'checked_in' ? 'checked in' : displayStatus;

            html += '<div class="queue-row status-' + displayStatus + '" data-team-id="' + t.id + '" data-status="' + displayStatus + '">';
            html += '<span class="queue-pos">#' + (t.queue_position || '-') + '</span>';
            html += '<div class="queue-team"><div class="team-name">' + esc(t.team_name) + '</div>';
            html += '<div class="team-captain">' + esc(t.captain_name || '') + (t.captain_phone ? ' &middot; ' + esc(t.captain_phone) : '') + '</div></div>';
            html += '<span class="queue-status-badge ' + displayStatus + '">' + statusLabel + '</span>';
            html += '<div class="queue-actions">';

            if (displayStatus === 'registered') {
                html += '<button class="btn btn-primary" onclick="checkinTeam(' + t.id + ')">Check In</button>';
            } else if (displayStatus === 'checked_in') {
                html += '<button class="btn btn-secondary" onclick="undoCheckin(' + t.id + ')">Undo</button>';
            }

            html += '</div>';

            // Reorder buttons (only for non-eliminated teams)
            if (displayStatus !== 'eliminated') {
                html += '<div class="reorder-btns">';
                html += '<button onclick="moveTeam(' + t.id + ',\'up\')" title="Move up">&#9650;</button>';
                html += '<button onclick="moveTeam(' + t.id + ',\'down\')" title="Move down">&#9660;</button>';
                html += '</div>';
            }

            html += '</div>';
        });
        qlEl.innerHTML = html;
    }

    // Past Games
    var pgEl = document.getElementById('past-games');
    if (data.completed_matches.length === 0) {
        pgEl.innerHTML = '<p class="no-content">No games completed yet</p>';
    } else {
        var html = '';
        data.completed_matches.forEach(function(m) {
            var score = '';
            if (m.team1_score !== null && m.team2_score !== null) {
                score = m.team1_score + ' - ' + m.team2_score;
            }
            var time = m.updated_at ? new Date(m.updated_at).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '';
            html += '<div class="past-game-row">';
            html += '<span class="game-num">Game ' + m.match_number + '</span>';
            html += '<span class="game-teams">' + esc(m.team1_name) + ' vs ' + esc(m.team2_name);
            if (m.winner_name) html += ' &mdash; <strong>' + esc(m.winner_name) + ' wins</strong>';
            html += '</span>';
            if (score) html += '<span class="game-score">' + score + '</span>';
            html += '<span class="game-time">' + time + '</span>';
            html += '</div>';
        });
        pgEl.innerHTML = html;
    }
}

/** HTML-escape helper */
function esc(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ============================================================
// INIT
// ============================================================

refreshQueue();
pollTimer = setInterval(refreshQueue, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
