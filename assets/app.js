(() => {
  'use strict';

  const API = 'api/';
  const STORAGE_KEY = 'lc_session_v1';
  const SCAN_COOLDOWN_MS = 1800;

  const TAGS = [
    { code: 'quente', label: '🔥 Quente' },
    { code: 'morno', label: '🙂 Morno' },
    { code: 'frio', label: '❄️ Frio / sem interesse' },
    { code: 'cliente', label: '💰 Já é cliente' },
    { code: 'parceiro', label: '🤝 Parceiro em potencial' },
    { code: 'followup', label: '📞 Follow-up urgente' },
    { code: 'decisor', label: '🏆 Decisor / C-level' },
  ];

  const el = (id) => document.getElementById(id);
  const screens = {
    loading: el('screen-loading'),
    welcome: el('screen-welcome'),
    onboardScan: el('screen-onboard-scan'),
    onboardProfile: el('screen-onboard-profile'),
    dashboard: el('screen-dashboard'),
    scan: el('screen-scan'),
  };

  const state = {
    sessionToken: null,
    capturadorId: null,
    name: null,
    company: null,
    ownQr: null,
    mode: null, // 'onboard' | 'capture' — which scan flow is active
    stream: null,
    detector: null,
    rafId: null,
    lastDecoded: null,
    cooldownUntil: 0,
    pendingCapturaId: null,
    selectedTags: [],
  };

  function showScreen(name) {
    Object.values(screens).forEach((s) => { s.hidden = true; });
    screens[name].hidden = false;
    el('app-footer').classList.toggle('hide', name === 'scan');
  }

  function toast(msg, ms = 2200) {
    const t = el('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._tid);
    toast._tid = setTimeout(() => t.classList.remove('show'), ms);
  }

  function flash(kind, icon) {
    const f = el('flash-overlay');
    f.className = kind;
    f.querySelector('.icon').textContent = icon;
    f.classList.add('show');
    setTimeout(() => f.classList.remove('show'), 350);
  }

  function showLastCapture(value) {
    const chip = el('last-capture-chip');
    el('last-capture-value').textContent = value;
    chip.hidden = false;
    requestAnimationFrame(() => chip.classList.add('show'));
    clearTimeout(showLastCapture._tid);
    showLastCapture._tid = setTimeout(() => chip.classList.remove('show'), 5000);
  }

  function hideLastCapture() {
    const chip = el('last-capture-chip');
    clearTimeout(showLastCapture._tid);
    chip.classList.remove('show');
    chip.hidden = true;
  }

  function beep() {
    try {
      const ctx = beep._ctx || (beep._ctx = new (window.AudioContext || window.webkitAudioContext)());
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'square';
      osc.frequency.value = 1046;
      gain.gain.setValueAtTime(0.0001, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(1, ctx.currentTime + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.26);
      osc.connect(gain).connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.28);
    } catch (e) { /* audio not available, ignore */ }
  }

  // ---------- Persistence ----------
  function saveSession() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      sessionToken: state.sessionToken,
      capturadorId: state.capturadorId,
      name: state.name,
      company: state.company,
      ownQr: state.ownQr,
    }));
  }

  function loadSession() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      return JSON.parse(raw);
    } catch (e) { return null; }
  }

  function clearSession() {
    localStorage.removeItem(STORAGE_KEY);
  }

  // ---------- API ----------
  async function apiPost(path, body) {
    const res = await fetch(API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Erro de rede');
    return data;
  }

  async function apiGet(path) {
    const res = await fetch(API + path);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Erro de rede');
    return data;
  }

  // ---------- Camera / QR decoding ----------
  async function startCamera(videoEl) {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false,
    });
    state.stream = stream;
    videoEl.srcObject = stream;
    await videoEl.play();
  }

  function stopCamera() {
    if (state.stream) {
      state.stream.getTracks().forEach((t) => t.stop());
      state.stream = null;
    }
    if (state.rafId) {
      cancelAnimationFrame(state.rafId);
      state.rafId = null;
    }
  }

  function useNativeDetector() {
    return 'BarcodeDetector' in window;
  }

  async function decodeLoop(videoEl, canvasEl, onDecode) {
    const ctx = canvasEl.getContext('2d', { willReadFrequently: true });
    let detector = null;
    if (useNativeDetector()) {
      try { detector = new window.BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { detector = null; }
    }

    async function tick() {
      if (!state.stream) return;
      if (videoEl.readyState === videoEl.HAVE_ENOUGH_DATA) {
        const now = Date.now();
        if (now >= state.cooldownUntil) {
          const w = videoEl.videoWidth;
          const h = videoEl.videoHeight;
          if (w && h) {
            canvasEl.width = w;
            canvasEl.height = h;
            ctx.drawImage(videoEl, 0, 0, w, h);

            let value = null;
            if (detector) {
              try {
                const codes = await detector.detect(canvasEl);
                if (codes && codes.length) value = codes[0].rawValue;
              } catch (e) { /* fall through */ }
            } else if (window.jsQR) {
              const imgData = ctx.getImageData(0, 0, w, h);
              const result = window.jsQR(imgData.data, w, h, { inversionAttempts: 'dontInvert' });
              if (result) value = result.data;
            }

            if (value) {
              onDecode(value);
            }
          }
        }
      }
      state.rafId = requestAnimationFrame(tick);
    }
    state.rafId = requestAnimationFrame(tick);
  }

  // ---------- Onboarding: self QR scan ----------
  async function beginOnboardScan() {
    showScreen('onboardScan');
    el('onboard-error').textContent = '';
    try {
      await startCamera(el('onboard-video'));
      decodeLoop(el('onboard-video'), el('onboard-canvas'), (value) => {
        state.cooldownUntil = Date.now() + SCAN_COOLDOWN_MS;
        state.ownQr = value;
        stopCamera();
        el('onboard-confirm-box').hidden = false;
      });
    } catch (e) {
      el('onboard-error').textContent = 'Nao foi possivel acessar a camera. Verifique as permissoes do navegador.';
    }
  }

  // ---------- Welcome screen ----------
  function checkWelcomeReady() {
    const ready = el('chk-welcome-1').checked && el('chk-welcome-2').checked && el('chk-welcome-3').checked;
    el('btn-welcome-continue').hidden = !ready;
  }

  async function submitProfile(ev) {
    ev.preventDefault();
    const name = el('input-name').value.trim();
    const company = el('input-company').value.trim();
    const email = el('input-email').value.trim();
    const errEl = el('profile-error');
    errEl.textContent = '';
    if (!name || !company || !email) {
      errEl.textContent = 'Preencha nome, empresa e email.';
      return;
    }
    if (!email.includes('@')) {
      errEl.textContent = 'Email invalido.';
      return;
    }
    const btn = el('btn-start-capturing');
    btn.disabled = true;
    try {
      const resp = await apiPost('register.php', { own_qr_code: state.ownQr, name, company, email });
      state.sessionToken = resp.session_token;
      state.capturadorId = resp.id;
      state.name = resp.name;
      state.company = resp.company;
      saveSession();
      renderDashboard(resp.stats);
      showScreen('dashboard');
    } catch (e) {
      errEl.textContent = e.message || 'Erro ao cadastrar. Tente novamente.';
    } finally {
      btn.disabled = false;
    }
  }

  // ---------- Dashboard ----------
  function renderDashboard(stats) {
    el('dash-who-name').textContent = state.name;
    el('dash-who-company').textContent = state.company;
    el('stat-unique').textContent = stats.total_unique;
    el('stat-repeated').textContent = stats.total_repeated;
    renderChart(stats.days);
  }

  function renderChart(days) {
    const wrap = el('chart-bars');
    wrap.innerHTML = '';
    const maxTotal = Math.max(1, ...days.map((d) => d.unique + d.repeated));
    days.forEach((d) => {
      const total = d.unique + d.repeated;
      const col = document.createElement('div');
      col.className = 'chart-bar-col';

      const totalLabel = document.createElement('div');
      totalLabel.className = 'chart-bar-total';
      totalLabel.textContent = String(total);

      const bar = document.createElement('div');
      bar.className = 'chart-bar';
      bar.style.height = Math.max(4, Math.round((total / maxTotal) * 200)) + 'px';

      const segUnique = document.createElement('div');
      segUnique.className = 'seg-unique';
      segUnique.style.height = total ? (d.unique / total * 100) + '%' : '0%';

      const segRepeated = document.createElement('div');
      segRepeated.className = 'seg-repeated';
      segRepeated.style.height = total ? (d.repeated / total * 100) + '%' : '0%';

      bar.appendChild(segUnique);
      bar.appendChild(segRepeated);

      const label = document.createElement('div');
      label.className = 'chart-bar-label';
      label.textContent = d.label + ' (' + d.date.slice(5).split('-').reverse().join('/') + ')';

      col.appendChild(totalLabel);
      col.appendChild(bar);
      col.appendChild(label);
      wrap.appendChild(col);
    });
  }

  // ---------- Capture flow ----------
  async function beginCapture() {
    showScreen('scan');
    el('scan-error').textContent = '';
    hideLastCapture();
    try {
      await startCamera(el('scan-video'));
      decodeLoop(el('scan-video'), el('scan-canvas'), handleCapturedQr);
    } catch (e) {
      el('scan-error').textContent = 'Nao foi possivel acessar a camera. Verifique as permissoes do navegador.';
    }
  }

  async function handleCapturedQr(value) {
    state.cooldownUntil = Date.now() + SCAN_COOLDOWN_MS;
    try {
      const resp = await apiPost('capture.php', { session_token: state.sessionToken, qr_code: value });
      beep();
      showLastCapture(value);
      if (resp.is_duplicate) {
        flash('repeat', '↺');
        toast('Lead ja tinha sido capturado por voce.');
      } else {
        flash('success', '✓');
        openTagSheet(resp.id);
      }
      renderDashboard(resp.stats);
    } catch (e) {
      flash('error', '!');
      toast(e.message || 'Erro ao registrar captura.');
    }
  }

  // ---------- Tag sheet ----------
  function renderTagChips() {
    const wrap = el('tag-chips');
    wrap.innerHTML = '';
    TAGS.forEach((tag) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tag-chip';
      btn.textContent = tag.label;
      btn.dataset.code = tag.code;
      btn.addEventListener('click', () => {
        const idx = state.selectedTags.indexOf(tag.code);
        if (idx >= 0) {
          state.selectedTags.splice(idx, 1);
          btn.classList.remove('selected');
        } else {
          state.selectedTags.push(tag.code);
          btn.classList.add('selected');
        }
      });
      wrap.appendChild(btn);
    });
  }

  function openTagSheet(capturaId) {
    state.pendingCapturaId = capturaId;
    state.selectedTags = [];
    el('tag-notes').value = '';
    renderTagChips();
    el('tag-sheet').hidden = false;
  }

  function closeTagSheet() {
    el('tag-sheet').hidden = true;
    state.pendingCapturaId = null;
    state.selectedTags = [];
  }

  async function saveTagSheet() {
    if (!state.pendingCapturaId) { closeTagSheet(); return; }
    const notes = el('tag-notes').value.trim();
    const capturaId = state.pendingCapturaId;
    const tags = state.selectedTags.slice();
    closeTagSheet();
    try {
      await apiPost('tag.php', {
        session_token: state.sessionToken,
        captura_id: capturaId,
        tags,
        notes,
      });
    } catch (e) {
      toast('Nao foi possivel salvar a marcacao.');
    }
  }

  function closeCapture() {
    stopCamera();
    hideLastCapture();
    closeTagSheet();
    showScreen('dashboard');
  }

  // ---------- Boot ----------
  async function boot() {
    showScreen('loading');
    const saved = loadSession();
    if (saved && saved.sessionToken) {
      try {
        const resp = await apiGet('session.php?token=' + encodeURIComponent(saved.sessionToken));
        state.sessionToken = saved.sessionToken;
        state.capturadorId = resp.id;
        state.name = resp.name;
        state.company = resp.company;
        state.ownQr = saved.ownQr;
        renderDashboard(resp.stats);
        showScreen('dashboard');
        return;
      } catch (e) {
        clearSession();
      }
    }
    showScreen('welcome');
  }

  function resetProfile() {
    stopCamera();
    clearSession();
    Object.assign(state, {
      sessionToken: null, capturadorId: null, name: null, company: null, ownQr: null,
    });
    el('chk-welcome-1').checked = false;
    el('chk-welcome-2').checked = false;
    el('chk-welcome-3').checked = false;
    checkWelcomeReady();
    showScreen('welcome');
  }

  document.addEventListener('DOMContentLoaded', () => {
    el('btn-start-capturing').closest('form').addEventListener('submit', submitProfile);
    el('chk-welcome-1').addEventListener('change', checkWelcomeReady);
    el('chk-welcome-2').addEventListener('change', checkWelcomeReady);
    el('chk-welcome-3').addEventListener('change', checkWelcomeReady);
    el('btn-welcome-continue').addEventListener('click', beginOnboardScan);
    el('btn-confirm-own-yes').addEventListener('click', () => {
      el('onboard-confirm-box').hidden = true;
      showScreen('onboardProfile');
    });
    el('btn-confirm-own-no').addEventListener('click', () => {
      el('onboard-confirm-box').hidden = true;
      beginOnboardScan();
    });
    el('btn-open-scan').addEventListener('click', beginCapture);
    el('btn-close-scan').addEventListener('click', closeCapture);
    el('btn-reset-profile').addEventListener('click', resetProfile);
    el('btn-tag-save').addEventListener('click', saveTagSheet);
    el('btn-tag-skip').addEventListener('click', closeTagSheet);
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        stopCamera();
        return;
      }
      if (!screens.onboardScan.hidden && el('onboard-confirm-box').hidden) beginOnboardScan();
      else if (!screens.scan.hidden) beginCapture();
    });
    boot();
  });
})();
