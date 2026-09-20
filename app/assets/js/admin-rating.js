(() => {
  'use strict';

  const root = document.querySelector('[data-rating-api]');
  const card = root?.querySelector('[data-rating-admin]');
  if (!root || !card) return;

  const endpoint = String(root.dataset.ratingApi || '');
  const telegram = window.Telegram?.WebApp || null;
  const status = card.querySelector('[data-rating-status]');
  const metrics = card.querySelector('[data-rating-metrics]');
  const seasonInput = card.querySelector('[data-rating-season-id]');
  const mgwIdInput = card.querySelector('[data-rating-mgw-id]');
  const reasonCode = card.querySelector('[data-rating-reason-code]');
  const reviewNote = card.querySelector('[data-rating-review-note]');
  const recalcReason = card.querySelector('[data-rating-recalc-reason]');
  const exclusions = card.querySelector('[data-rating-exclusions]');
  const jobs = card.querySelector('[data-rating-jobs]');
  const rehearsalOutput = card.querySelector('[data-rating-rehearsal-output]');
  const refresh = card.querySelector('[data-rating-refresh]');
  const rehearsal = card.querySelector('[data-rating-rehearsal]');
  const exclude = card.querySelector('[data-rating-exclude]');
  const recalculate = card.querySelector('[data-rating-recalculate]');
  let busy = false;

  const buttons = () => card.querySelectorAll('button');

  const setBusy = value => {
    busy = value;
    buttons().forEach(button => { button.disabled = value; });
  };

  const setStatus = (message, state = '') => {
    status.textContent = message;
    if (state) status.dataset.state = state;
    else delete status.dataset.state;
  };

  const post = async payload => {
    if (!telegram?.initData) throw new Error('Откройте Web Admin из Telegram.');
    const response = await fetch(endpoint, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({...payload, initData:telegram.initData})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok !== true) {
      throw new Error(String(data.error || 'Rating Admin request failed.'));
    }
    return data;
  };

  const metricCard = (label, value, tone = '') => {
    const item = document.createElement('div');
    if (tone) item.dataset.tone = tone;
    const span = document.createElement('span');
    const strong = document.createElement('strong');
    span.textContent = label;
    strong.textContent = String(value ?? '—');
    item.append(span, strong);
    return item;
  };

  const renderMetrics = snapshot => {
    metrics.replaceChildren();
    const competition = snapshot?.competition || {};
    const currentSeason = snapshot?.current_season || {};
    const m = snapshot?.metrics || {};
    const state = String(competition.competition_state || '—');
    const season = String(currentSeason.season_id || competition.current_season_id || '—');
    metrics.append(
      metricCard('Competition', state),
      metricCard('Season', season),
      metricCard('Score rows', Number(m.score_rows || 0).toLocaleString('ru-RU')),
      metricCard('Participation', Number(m.participation_rows || 0).toLocaleString('ru-RU')),
      metricCard('Pending projection', Number(m.pending_projection_count || 0), Number(m.pending_projection_count || 0) > 0 ? 'warn' : 'ok'),
      metricCard('Review exclusions', Number(m.active_exclusions || 0)),
      metricCard('Bot outcomes', Number(m.bot_game_outcomes || 0)),
      metricCard('Bot violations', Number(m.bot_participation_violations || 0), Number(m.bot_participation_violations || 0) > 0 ? 'error' : 'ok')
    );
    if (!seasonInput.value && currentSeason.season_id) seasonInput.value = String(currentSeason.season_id);
  };

  const empty = text => {
    const node = document.createElement('div');
    node.className = 'mgw-admin__history-empty';
    node.textContent = text;
    return node;
  };

  const renderExclusions = snapshot => {
    exclusions.replaceChildren();
    const rows = Array.isArray(snapshot?.active_exclusions) ? snapshot.active_exclusions : [];
    if (!rows.length) {
      exclusions.append(empty('Активных reviewed exclusions нет.'));
      return;
    }

    rows.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';

      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      const details = document.createElement('span');
      title.textContent = `${row.public_mgw_id || row.mgw_id || '—'} · ${row.season_id || '—'}`;
      details.textContent = `${row.nickname || 'без ника'} · ${row.reason_code || 'review'} · ${row.review_note || '—'}`;
      copy.append(title, details);

      const restore = document.createElement('button');
      restore.type = 'button';
      restore.textContent = 'Вернуть';
      restore.addEventListener('click', () => restoreExclusion(row));
      item.append(copy, restore);
      exclusions.append(item);
    });
  };

  const renderJobs = snapshot => {
    jobs.replaceChildren();
    const rows = Array.isArray(snapshot?.recent_jobs) ? snapshot.recent_jobs : [];
    if (!rows.length) {
      jobs.append(empty('Recalculation jobs ещё не запускались.'));
      return;
    }
    rows.forEach(row => {
      const item = document.createElement('div');
      item.className = 'mgw-admin__history-item';
      const copy = document.createElement('div');
      copy.className = 'mgw-admin__history-copy';
      const title = document.createElement('strong');
      const details = document.createElement('span');
      title.textContent = `${row.season_id || '—'} · ${row.job_state || '—'}`;
      const excluded = Array.isArray(row.excluded_mgw_ids) ? row.excluded_mgw_ids.length : 0;
      details.textContent = `${row.job_id || '—'} · exclusions ${excluded} · ${row.reason_text || '—'}`;
      copy.append(title, details);
      item.append(copy);
      jobs.append(item);
    });
  };

  const renderSnapshot = snapshot => {
    renderMetrics(snapshot);
    renderExclusions(snapshot);
    renderJobs(snapshot);
  };

  const withBusy = async (message, action) => {
    if (busy) return;
    setBusy(true);
    setStatus(message);
    try {
      const data = await action();
      if (data.snapshot) renderSnapshot(data.snapshot);
      return data;
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'Rating Admin operation failed.', 'error');
      throw error;
    } finally {
      setBusy(false);
    }
  };

  const load = async () => {
    try {
      const data = await withBusy('Загружаю Rating Admin…', () => post({action:'snapshot'}));
      setStatus('Rating Admin загружен. Изменения выполняются только вручную.', 'ok');
      return data;
    } catch (_) {}
  };

  const addExclusion = async () => {
    const seasonId = seasonInput.value.trim();
    const mgwId = mgwIdInput.value.trim();
    const note = reviewNote.value.trim();
    if (!seasonId || !mgwId || note.length < 3) {
      setStatus('Для exclusion нужны Season ID, MGW-ID и комментарий review.', 'error');
      return;
    }
    if (!window.confirm(`Исключить ${mgwId} из официальных итогов ${seasonId} после review?`)) return;
    try {
      await withBusy('Сохраняю reviewed exclusion…', () => post({
        action:'exclude',
        season_id:seasonId,
        mgw_id:mgwId,
        reason_code:String(reasonCode.value || 'manual_review'),
        review_note:note,
      }));
      mgwIdInput.value = '';
      reviewNote.value = '';
      setStatus('Reviewed exclusion сохранён. Для закрытого сезона запустите отдельный recalculation.', 'ok');
    } catch (_) {}
  };

  const restoreExclusion = async row => {
    const note = window.prompt('Причина возврата игрока в официальные итоги:', 'Review отменён');
    if (!note || note.trim().length < 3) return;
    try {
      await withBusy('Возвращаю игрока после review…', () => post({
        action:'restore',
        season_id:String(row.season_id || ''),
        mgw_id:String(row.public_mgw_id || row.mgw_id || ''),
        review_note:note.trim(),
      }));
      setStatus('Exclusion снят. Для закрытого сезона запустите отдельный recalculation.', 'ok');
    } catch (_) {}
  };

  const runRecalculation = async () => {
    const seasonId = seasonInput.value.trim();
    const reason = recalcReason.value.trim();
    if (!seasonId || reason.length < 3) {
      setStatus('Для recalculation укажите Season ID и причину.', 'error');
      return;
    }
    if (!window.confirm(`Пересчитать официальные награды и годовую медаль сезона ${seasonId}? Match history и rating score не меняются.`)) return;
    try {
      const data = await withBusy('Пересчитываю награды через canonical owners…', () => post({
        action:'recalculate',
        season_id:seasonId,
        reason,
      }));
      const result = data?.recalculation || {};
      recalcReason.value = '';
      setStatus(
        `Recalculation завершён: +${Number(result.granted || 0)}, reissue ${Number(result.reissued || 0)}, revoke ${Number(result.revoked || 0)}.`,
        'ok'
      );
    } catch (_) {}
  };

  const runRehearsal = async () => {
    const seasonId = seasonInput.value.trim();
    if (!seasonId) {
      setStatus('Для репетиции укажите Season ID.', 'error');
      return;
    }
    try {
      const data = await withBusy('Выполняю dry-run закрытия сезона…', () => post({
        action:'rehearsal',
        season_id:seasonId,
      }));
      const result = data?.rehearsal || {};
      rehearsalOutput.textContent = JSON.stringify(result, null, 2);
      setStatus(
        result.ready_to_finalize === true
          ? 'Season-close rehearsal: READY. Записей в rating/awards не создавалось.'
          : 'Season-close rehearsal: есть блокирующие условия. См. dry-run.',
        result.ready_to_finalize === true ? 'ok' : 'error'
      );
    } catch (_) {}
  };

  refresh?.addEventListener('click', load);
  rehearsal?.addEventListener('click', runRehearsal);
  exclude?.addEventListener('click', addExclusion);
  recalculate?.addEventListener('click', runRecalculation);

  if (telegram?.initData) {
    window.setTimeout(load, 120);
  }
})();