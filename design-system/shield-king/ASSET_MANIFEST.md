# Shield King — Asset Manifest

## Status

`DS-5 MANIFEST READY`

This manifest lists persistent files owned by the isolated Shield King design-system branch.

Only paths under `design-system/shield-king/**` are owned by this workstream.

---

# 1. Foundations

- `README.md`
- `FOUNDATIONS.md`
- `TOKENS.md`
- `tokens.json`

# 2. Components

- `COMPONENTS.md`
- `COMPONENT_STATES.md`

# 3. Icon system

- `ICONS.md`
- `ICON_MANIFEST.md`
- `GAME_ART_EXPORT_MANIFEST.json` — historical/export metadata only; written icon acceptance rules in `ICONS.md` take precedence.

Core semantic SVG sprites:

- `icons/navigation/navigation-icons.svg`
- `icons/actions/action-icons.svg`
- `icons/status/status-icons.svg`
- `icons/economy/economy-icons.svg`

Eight game semantic/geometry SVG assets:

- `icons/games/game-tic-tac-toe.svg`
- `icons/games/game-four-in-a-row.svg`
- `icons/games/game-battleship.svg`
- `icons/games/game-checkers.svg`
- `icons/games/game-reversi.svg`
- `icons/games/game-chess.svg`
- `icons/games/game-go.svg`
- `icons/games/game-domino.svg`

Important accepted visual rule:

- the rich metallic Variant 1 game-icon family is the manually accepted art direction;
- all eight use one equal external crowned royal frame concept;
- ordinary app icons do not use the large royal frame;
- rejected/broken crop previews and rejected DS-4 board-family mockups are not persistent design references and must not be recreated.

# 4. Existing-screen migration

- `SCREENS.md`
- `SCREEN_STATE_MATRIX.md`
- `CURRENT_UI_MIGRATION.md`
- `CURRENT_UI_STYLE_MAP.md`
- `EXISTING_SCREEN_MIGRATION.md`
- `EXISTING_AUX_SURFACES_MIGRATION.md`

Authoritative conflict rule:

`CURRENT_UI_MIGRATION.md` overrides any older wording that could be interpreted as permission to rebuild existing screens.

# 5. Eight-game system

- `GAMES.md`
- `GAME_COMPONENTS.md`

Authoritative DS-4 rule:

- preserve current accepted gameplay boards;
- safe color-only adaptation where it genuinely improves fit;
- otherwise keep game visual unchanged;
- no gameplay-board redesign.

# 6. Loading/system states

- `LOADING_AND_SYSTEM_STATES.md`
- `PHASE_B_VISUAL_CONTRACT.md`

These documents are visual-only and do not own lifecycle, readiness, polling, clocks or gameplay reveal.

# 7. Machine-readable tokens

- `tokens.json`

Future implementation must map these tokens onto the then-current accepted shared UI rather than blindly merging this branch into runtime.

# 8. Explicitly excluded/rejected artifacts

Do not use as implementation sources:

- rejected replacement Home mockups;
- rejected invented bottom navigation;
- rejected 8-board DS-4 gameplay redesign preview;
- broken game-icon crop/contact sheets;
- any concept with invented games/features;
- any generated visual that conflicts with written accepted rules.

# 9. Future integration asset rule

At integration time:

1. use written Shield King tokens/components/migration rules;
2. use the accepted icon visual family;
3. verify exact icon export quality in the real card slots;
4. preserve live game boards unless a simple safe color substitution is accepted;
5. do not add runtime assets merely because they existed in a rejected concept.

# END
