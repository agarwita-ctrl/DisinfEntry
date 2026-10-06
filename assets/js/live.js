/* ============================================================
   Live Monitoring — polls api/live.php and drives the booth view.
   ========================================================== */

(() => {
  let lastId = 0;
  let primed = false;
  let paused = false;
  let timer = null;
  let selectedBooth = '';   // '' = whichever booth the server lists first

  const $ = (id) => document.getElementById(id);
  const entryCode = (id) => `ENT-${String(id).padStart(6, '0')}`;

  /* ---------------- Current entrant ---------------- */

  function renderCurrent(c, booth) {
    const hero = $('liveHero');

    if (!c) {
      hero.className = 'live-hero is-idle h-100';
      $('liveName').textContent = 'Waiting for entry…';
      $('liveEmployee').textContent = 'The booth has not reported anyone yet.';
      $('liveTemp').textContent = '--.-';
      $('liveStatusBadge').className = 'badge bg-light text-dark';
      $('liveStatusBadge').textContent = 'Idle';
      return;
    }

    const denied = c.status === 'denied';
    hero.className = `live-hero h-100 ${denied ? 'is-denied' : ''}`;

    $('liveName').textContent = entryCode(c.id);
    $('liveEmployee').textContent = 'Latest entry';

    $('liveTemp').textContent = c.temperature.toFixed(1);
    $('liveTempNote').innerHTML = c.high_temp
      ? `<span class="text-warning fw-semibold">Above threshold (${App.THRESHOLD}&deg;C)</span>`
      : `Within safe range (&le; ${App.THRESHOLD}&deg;C)`;

    $('liveStatusBadge').className = `badge ${denied ? 'bg-danger' : 'bg-success'}`;
    $('liveStatusBadge').textContent = denied ? 'ACCESS DENIED' : 'ACCESS GRANTED';

    const misting = booth?.misting ? 'on' : c.misting;
    $('liveMisting').className = `value mist-indicator ${misting === 'on' ? 'on' : ''}`;
    $('liveMisting').innerHTML = `<span class="dot"></span><span>${misting === 'on' ? 'Active' : 'Off'}</span>`;

    $('liveDisinfection').textContent =
      c.disinfection.charAt(0).toUpperCase() + c.disinfection.slice(1);
    $('liveAccess').textContent = denied ? 'Denied' : 'Granted';
    $('liveTimestamp').textContent = c.time;
    $('liveRemarks').textContent = c.remarks ? `Remarks: ${c.remarks}` : '';
  }

  /* ---------------- Counters ---------------- */

  function renderCounters(k) {
    $('cntTotal').textContent = App.num(k.total);
    $('cntGranted').textContent = App.num(k.granted);
    $('cntDenied').textContent = App.num(k.denied);
    $('cntHigh').textContent = App.num(k.high_temp);
  }

  /* ---------------- Booth selector ---------------- */

  // Populated from the feed. Stays hidden for a single booth, so the common
  // case looks exactly as it did; with two or more it is the only thing on the
  // page that reveals the others exist.
  function renderPicker(booths) {
    const sel = $('boothPicker');
    if (!sel || !booths) return;

    sel.classList.toggle('d-none', booths.length < 2);
    if (booths.length < 2) return;

    const signature = booths.map((b) => `${b.device_id}:${b.online}`).join('|');
    if (sel.dataset.signature === signature) return;
    sel.dataset.signature = signature;

    const keep = selectedBooth || sel.value;
    sel.innerHTML = booths.map((b) =>
      `<option value="${App.esc(b.device_id)}">${App.esc(b.device_name)}${b.online ? '' : ' (offline)'}</option>`
    ).join('');
    if (keep && booths.some((b) => b.device_id === keep)) sel.value = keep;
  }

  /* ---------------- Booth status ---------------- */

  function renderBooth(b) {
    const badge = $('boothBadge');

    if (!b) {
      badge.className = 'badge bg-secondary';
      badge.textContent = 'No booth registered';
      $('boothMeta').textContent = 'Register the ESP32 by sending its first sync.';
      return;
    }

    badge.className = `badge ${b.online ? 'bg-success' : 'bg-danger'}`;
    badge.textContent = b.online ? 'Online' : 'Offline';

    /* ---- Last reported sensor readings ---- */
    // Only the current firmware sends these; an older sketch reports none and
    // the tiles stay hidden rather than showing empty dashes.
    const hasReadings = b.online && b.reading_age != null;
    $('boothSensors').hidden = !hasReadings;

    if (hasReadings) {
      // A null reading is a sensor that could not measure, not a zero.
      $('boothDistance').textContent = b.distance_cm != null ? `${b.distance_cm.toFixed(1)} cm` : 'out of range';
      $('boothObjTemp').textContent = b.object_temp != null ? `${b.object_temp.toFixed(1)} °C` : 'no read';
      $('boothAmbTemp').textContent = b.ambient_temp != null ? `${b.ambient_temp.toFixed(1)} °C` : 'no read';
      $('boothReadingAge').textContent =
        b.reading_age < 60 ? `${b.reading_age}s ago` : `${Math.round(b.reading_age / 60)}m ago`;
    }

    $('boothCycleRow').hidden = !b.last_cycle;
    if (b.last_cycle) $('boothLastCycle').textContent = App.timeAgo(b.last_cycle);

    // mlx_ok is null for firmware that never reports it — only false is a fault.
    $('boothFault').hidden = b.mlx_ok !== false;

    // null means no sensor has reported a level. Showing a bar at 0% would read
    // as an empty tank, so the meter is emptied and labelled instead.
    const bar = $('boothLevelBar');
    const lvl = b.disinfectant_level;
    bar.style.width = lvl == null ? '0%' : `${lvl}%`;
    bar.className = `progress-bar ${lvl == null ? 'bg-secondary' : b.low_level ? 'bg-danger' : lvl < 50 ? 'bg-warning' : 'bg-info'}`;
    $('boothLevelText').textContent = lvl == null ? 'not reported' : `${lvl}%`;

    const fw = b.firmware ? ` · fw ${b.firmware}` : '';
    $('boothMeta').textContent =
      `${b.device_name} (${b.device_id})${fw} — last seen ${b.last_seen ? App.timeAgo(b.last_seen) : 'never'}`;
  }

  /* ---------------- Feed ---------------- */

  function renderFeed(rows) {
    const body = $('feedBody');

    if (!rows.length) {
      body.innerHTML =
        '<tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No entries yet today.</div></td></tr>';
      return;
    }

    body.innerHTML = rows.map((r) => `
      <tr class="${primed && r.id > lastId ? 'feed-row' : ''}">
        <td class="fw-semibold small">${entryCode(r.id)}</td>
        <td class="text-center">${App.tempBadge(r.temperature)}</td>
        <td><div>${App.esc(r.time)}</div><div class="text-muted small">${App.esc(r.date)}</div></td>
        <td class="text-center">
          <span class="badge-soft ${r.misting === 'on' ? 'completed' : 'skipped'}">${r.misting.toUpperCase()}</span>
        </td>
        <td class="text-center">${App.disinfectionBadge(r.disinfection)}</td>
        <td class="text-center">${App.accessBadge(r.status)}</td>
        <td class="small text-muted">${App.esc(r.remarks || '—')}</td>
      </tr>`).join('');
  }

  /* ---------------- Poll ---------------- */

  async function poll() {
    if (paused) return;

    try {
      const d = await App.api('api/live.php', {
        params: selectedBooth ? { since: lastId, device: selectedBooth } : { since: lastId },
      });

      renderCurrent(d.current, d.booth);
      renderCounters(d.counters);
      renderPicker(d.booths);
      renderBooth(d.booth);
      renderFeed(d.feed);

      // Announce arrivals that landed since the previous poll.
      if (primed && d.new.length) {
        const alert = d.new.find((r) => r.status === 'denied' || r.high_temp);
        App.playSound(alert ? 'alert' : 'info');

        d.new.forEach((r) => {
          if (r.high_temp) {
            App.toast(`${entryCode(r.id)}:${r.temperature.toFixed(1)}°C — access denied`, 'danger', 'High Temperature');
          } else if (r.status === 'denied') {
            App.toast(`${entryCode(r.id)} was denied entry. ${r.remarks || ''}`, 'danger', 'Entry Denied');
          } else {
            App.toast(`${entryCode(r.id)} cleared at ${r.time} (${r.temperature.toFixed(1)}°C)`, 'success', 'Entry Granted');
          }
        });

        if (alert) {
          App.alertPopup({
            title: alert.high_temp ? 'High Temperature Detected' : 'Entry Denied',
            message: `${entryCode(alert.id)} —${alert.remarks || 'Access denied at the disinfection booth.'}`,
            temperature: alert.high_temp ? alert.temperature : null,
          });
        }
      }

      lastId = d.last_id;
      primed = true;

      $('pollBadge').className = 'badge bg-danger';
      $('lastSync').textContent = `Last sync ${new Date().toLocaleTimeString()}`;
    } catch (err) {
      $('pollBadge').className = 'badge bg-secondary';
      $('lastSync').textContent = `Connection problem — retrying (${err.message})`;
    }
  }

  /* ---------------- Boot ---------------- */

  document.addEventListener('DOMContentLoaded', () => {
    const soundToggle = $('soundToggle');
    soundToggle.checked = App.isSoundOn();
    soundToggle.addEventListener('change', () => {
      App.toggleSound(soundToggle.checked);
      if (soundToggle.checked) App.playSound('info');
    });

    $('pauseToggle').addEventListener('change', (ev) => {
      paused = ev.target.checked;
      $('pollBadge').className = `badge ${paused ? 'bg-secondary' : 'bg-danger'}`;
      $('pollBadge').innerHTML = paused
        ? '<i class="bi bi-pause-circle me-1"></i>PAUSED'
        : `<i class="bi bi-broadcast me-1"></i>LIVE — polling every ${App.REFRESH / 1000}s`;
      if (!paused) poll();
    });

    $('boothPicker')?.addEventListener('change', (ev) => {
      selectedBooth = ev.target.value;
      poll();
    });

    $('testSound').addEventListener('click', () => {
      App.playSound('alert');
      App.toast('This is how a high-temperature alert sounds.', 'warning', 'Sound Test');
    });

    poll();
    timer = setInterval(poll, App.REFRESH);

    // Poll less aggressively while the tab is hidden.
    document.addEventListener('visibilitychange', () => {
      clearInterval(timer);
      timer = setInterval(poll, document.hidden ? 30000 : App.REFRESH);
      if (!document.hidden) poll();
    });
  });
})();
