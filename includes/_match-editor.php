<div class="match-editor">
    <div class="match-editor-header">
        <span>Match #<?php echo $match['match_number']; ?></span>
        <span class="badge <?php echo $match['status'] === 'completed' ? 'badge-completed' : ($match['status'] === 'in_progress' ? 'badge-in-progress' : 'badge-draft'); ?>">
            <?php echo h(ucwords(str_replace('_', ' ', $match['status']))); ?>
        </span>
    </div>
    <form method="POST" action="/api/match-update.php" class="match-editor-teams">
        <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
        <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
        <div class="match-editor-team">
            <div class="team-name"><?php echo $match['team1_name'] ? teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'xs') : 'TBD'; ?></div>
            <input type="number" name="team1_score" class="form-control" style="width: 80px; margin: 0 auto;"
                   value="<?php echo $match['team1_score'] ?? ''; ?>" placeholder="Score">
        </div>
        <div class="match-editor-vs">vs</div>
        <div class="match-editor-team">
            <div class="team-name"><?php echo $match['team2_name'] ? teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'xs') : 'TBD'; ?></div>
            <input type="number" name="team2_score" class="form-control" style="width: 80px; margin: 0 auto;"
                   value="<?php echo $match['team2_score'] ?? ''; ?>" placeholder="Score">
        </div>
        <?php if ($match['team1_id'] && $match['team2_id']): ?>
        <div style="grid-column: 1 / -1; text-align: center; margin-top: 10px;">
            <select name="winner_id" class="form-control" style="width: 200px; display: inline-block; margin-right: 10px;">
                <option value="">-- Select Winner --</option>
                <option value="<?php echo $match['team1_id']; ?>" <?php echo $match['winner_id'] == $match['team1_id'] ? 'selected' : ''; ?>>
                    <?php echo h($match['team1_name']); ?>
                </option>
                <option value="<?php echo $match['team2_id']; ?>" <?php echo $match['winner_id'] == $match['team2_id'] ? 'selected' : ''; ?>>
                    <?php echo h($match['team2_name']); ?>
                </option>
            </select>
            <button type="submit" class="btn btn-primary btn-small">Save Result</button>
            <?php if ($match['status'] === 'pending'): ?>
                <button type="submit" name="action" value="mark_in_progress" class="btn btn-secondary btn-small" style="margin-left: 6px;">Start Match</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
