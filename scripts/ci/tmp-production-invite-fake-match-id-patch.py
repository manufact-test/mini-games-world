from pathlib import Path

path = Path('bot/tests/ProductionAvatarInviteRegressionHotfixTest.php')
text = path.read_text(encoding='utf-8')
text = text.replace(
    """        foreach ($inviteIds as $inviteId) {
            $this->invites[(string)$inviteId] = ['invite_id' => (string)$inviteId];
        }
""",
    """        foreach ($inviteIds as $inviteId) {
            $this->invites[(string)$inviteId] = [
                'invite_id' => (string)$inviteId,
                'match_id' => null,
            ];
        }
""",
    1,
)
text = text.replace(
    """        if (str_contains($sql, 'SELECT invite_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
""",
    """        if (str_contains($sql, 'SELECT invite_id FROM mgw_invites')
            || str_contains($sql, 'SELECT invite_id, match_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
""",
    1,
)
path.write_text(text, encoding='utf-8')
