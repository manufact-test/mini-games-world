#!/usr/bin/env python3
from pathlib import Path

p = Path('bot/tests/Mvp156UnifiedZoneCutoverTest.php')
body = p.read_text(encoding='utf-8')

old = "$assertTrue(str_contains($home, 'Обычные матчи'), 'Home must expose the neutral game-zone card');"
new = "\n".join([
    "$assertNotContains('id=\"roomCard\"', $index, 'Redundant room summary card must be removed from Home');",
    "$assertTrue(str_contains($index, 'id=\"weeklyMatchInfo\"') && str_contains($index, '>Еженедельный бонус</button>'), 'Weekly bonus CTA must live in the unified wallet card');",
    "$assertNotContains('Правила Gold-комнаты', $home, 'Active rules must not expose the removed Gold room');",
    "$assertNotContains('10 коинов', $home, 'Active rules must not expose the removed legacy entry price');",
    "$assertTrue(str_contains($weekly, \"button.textContent = 'Еженедельный бонус';\"), 'Weekly runtime owner must preserve the final CTA copy');",
])
assert body.count(old) == 1, f'neutral card assertion count={body.count(old)}'
body = body.replace(old, new, 1)

old = "$assertTrue(str_contains($v110, 'v=1142&mvp15=unified-zone'), 'Accepted /start graph must cache-bust the unified-zone shell');"
new = "$assertTrue(str_contains($v110, 'v=1143&mvp15=weekly-bonus-wallet'), 'Accepted /start graph must cache-bust the weekly-bonus wallet shell');"
assert body.count(old) == 1, f'shell cache assertion count={body.count(old)}'
body = body.replace(old, new, 1)

p.write_text(body, encoding='utf-8')
