# Mini Games World — CANONICAL DELTA

Дата: 2026-09-04
Область: MVP-19.3 Profile cosmetics / Reactions
Основание: фактический staging + ручная Telegram-проверка пользователя

## Добавить в канонику как устойчивые факты

### Profile cosmetics

- Универсальный контракт карточек косметики:
  - selected: зелёная рамка + `Выбрано` + единственное действие `Снять`, если предмет снимаемый;
  - owned inactive: `В коллекции` + `Выбрать`;
  - unowned: цена/редкость + `Купить`;
  - без дублирующих действий.
- Покупка косметики не экипирует её автоматически.
- Store остаётся владельцем discovery/purchase; Profile — collection/equip; permanent ownership/equip — `ProductInventoryService`.
- Снятие платной аватарки возвращает канонический starter fallback `starter-default-01`.
- Фон профиля владеет всей поверхностью `#screen-profile`, а не отдельной карточкой личности.
- Финальные premium background tiers после ручной приёмки:
  - `profile-background-03`, 7 500 — `Бездна`;
  - `profile-background-04`, 12 500 — `Квантовый шторм`.
- Фоновые эффекты — presentation only; они не владеют правилами игры, действиями, ходами, таймерами или settlement.

### Reactions — вручную принято 2026-09-04

- Slice Reactions считается вручную принятым на desktop и mobile после PR #1176 и staging SHA `61d202f52714a69640452217b16847be7c5d0b7d`.
- Цены остаются каноническими: 500 за одиночную реакцию, 1 500 за pack-4, 3 500 за большой pack.
- Единственный equip slot: `profile_reaction_set`.
- Ownership постоянный; покупка не auto-equip; выбор/снятие явные.
- Reactions доступны из Store и из коллекции Profile.
- Доставка в PvP — ephemeral presentation event через существующий read-only game watcher, не `game_action`.
- `GameReactionService` сохраняет bounded transport: cooldown 900 ms, TTL 5 000 ms.
- Реакция визуально стартует от аватара отправителя.
- Мобильная первая реакция стабилизирована переносом live-bubble в стабильный `#screen-game`, поэтому remount карточки игрока не удаляет первый показ.
- `bot/games/**`, правила, actions, turns, timers, economy, Friends/invites/reconnect этим slice не изменяются.

## Не добавлять в канонику как принятое до ручной проверки

- Текущий corrective перехода в Profile из ветки `agent/mvp19-3-profile-route-transition-final-corrective` пока является кандидатом.
- Его цель: вернуть Profile тот же 240 ms opacity + translateY shell-transition, убрать мобильный phased/double-rAF navigation hack, мобильное мигание и тяжёлые live blur-перерисовки.
- Закрывать этот пункт и фиксировать итоговый staging SHA в канонике только после ручной проверки пользователя на desktop + mobile.

## Следующая последовательность после принятия Profile transition

`Entry effect → Victory effect → final MVP-19.3 reconciliation/closure → MVP-19.4 reconciliation/closure → MVP-19.5 Chess cosmetics`.
