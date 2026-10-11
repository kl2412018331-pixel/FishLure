(() => {
  const accountButton = document.getElementById('accountBtn');
  const authDialog = document.getElementById('authDialog');
  let user = null;
  let csrfToken = '';

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const toast = message => {
    const node = document.getElementById('toast');
    node.textContent = message;
    node.classList.add('show');
    setTimeout(() => node.classList.remove('show'), 3600);
  };

  async function request(action, body) {
    const options = body ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({...body, csrfToken})} : {};
    const response = await fetch(`account.php?action=${action}`, options);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || 'Could not update your fishing notebook.');
    return payload;
  }

  function drawEntries(data) {
    document.getElementById('savedCount').textContent = `${data.saved.length} saved`;
    document.getElementById('catchCount').textContent = `${data.catches.length} catches`;
    document.getElementById('savedList').innerHTML = data.saved.length ? data.saved.map(item => `
      <article class="entry-card"><div class="entry-card-head"><span class="entry-date">${escapeHtml(new Date(item.created_at.replace(' ','T')).toLocaleDateString())}</span><button class="entry-delete" data-delete-saved="${Number(item.saved_id)}" type="button">Delete</button></div>
      <h4>${escapeHtml(item.species_name)} · ${escapeHtml(item.lure_name)}</h4><p>${escapeHtml(item.lure_type)} · ${escapeHtml(item.lure_colour)}</p>
      <p><b>Depth:</b> ${escapeHtml(item.recommended_depth)} &nbsp; <b>Technique:</b> ${escapeHtml(item.technique)}</p><p class="entry-muted">${escapeHtml(item.water_clarity)} water · ${escapeHtml(item.environment)}</p></article>`).join('') : '<p class="entry-empty">No saved recommendations yet. Get a suggestion and save it here.</p>';
    document.getElementById('catchList').innerHTML = data.catches.length ? data.catches.map(item => `
      <article class="entry-card"><div class="entry-card-head"><span class="entry-date">${escapeHtml(item.catch_date)}</span><button class="entry-delete" data-delete-catch="${Number(item.catch_id)}" type="button">Delete</button></div>
      <h4>${escapeHtml(item.species_name)}</h4><p>${escapeHtml(item.location)}${item.size_note ? ` · ${escapeHtml(item.size_note)}` : ''}</p>
      <p>${item.lure_used ? `<b>Lure:</b> ${escapeHtml(item.lure_used)}` : ''}${item.depth_note ? ` · <b>Depth:</b> ${escapeHtml(item.depth_note)}` : ''}</p>${item.notes ? `<p class="entry-muted">${escapeHtml(item.notes)}</p>` : ''}</article>`).join('') : '<p class="entry-empty">Your catch log is empty. Add your first catch above.</p>';
  }

  async function refresh() {
    try {
      const authResponse = await fetch('auth.php?action=me');
      const auth = await authResponse.json();
      csrfToken = auth.csrfToken || '';
      user = auth.user || null;
      document.getElementById('accountHint').textContent = user ? `Welcome, ${user.name}. Your saves and catch log are private to your account.` : 'Log in or register to start saving your fishing history.';
      document.getElementById('saveRecommendation').textContent = user ? '♡ Save this recommendation' : '♡ Log in to save recommendation';
      if (!user) {
        document.getElementById('savedList').innerHTML = '<p class="entry-empty">Log in to see recommendations you’ve saved.</p>';
        document.getElementById('catchList').innerHTML = '<p class="entry-empty">Log in to keep your catch history.</p>';
        document.getElementById('savedCount').textContent = '0 saved';
        document.getElementById('catchCount').textContent = '0 catches';
        return;
      }
      drawEntries(await request('dashboard'));
    } catch (error) { toast(error.message); }
  }

  function syncFishOptions() {
    const fish = document.getElementById('fish');
    const catchFish = document.getElementById('catchSpecies');
    if (!fish.options.length) return;
    const groups = [...fish.querySelectorAll('optgroup')].map(group => {
      const copy = document.createElement('optgroup');
      copy.label = group.label;
      [...group.options].forEach(option => copy.append(new Option(option.textContent, option.value)));
      return copy;
    });
    catchFish.replaceChildren(new Option('Choose a fish…', ''), ...groups);
  }

  const speciesObserver = new MutationObserver(syncFishOptions);
  speciesObserver.observe(document.getElementById('fish'), {childList:true, subtree:true});
  syncFishOptions();
  document.getElementById('catchDate').value = new Date().toISOString().slice(0,10);
  refresh();
  new MutationObserver(refresh).observe(accountButton, {childList:true, characterData:true, subtree:true});

  accountButton.addEventListener('click', () => setTimeout(refresh, 50));
  document.getElementById('authForm').addEventListener('submit', () => setTimeout(refresh, 600));
  document.getElementById('logoutBtn').addEventListener('click', () => setTimeout(refresh, 300));

  document.getElementById('saveRecommendation').addEventListener('click', async () => {
    if (!user) { authDialog.showModal(); toast('Log in or create an account, then get a recommendation to save.'); return; }
    const card = document.querySelector('#primaryPick .primary-pick');
    if (!card) { toast('Get a lure recommendation first, then save it here.'); return; }
    const lines = card.querySelector('.pick-meta').innerText.split('\n').map(line => line.trim());
    const pair = (prefix, index) => lines[index]?.replace(new RegExp(`^${prefix}:\\s*`), '') || '';
    const fields = lines[0].split(' · ');
    const payload = {
      speciesId:document.getElementById('fish').value,
      speciesName:document.getElementById('fish').selectedOptions[0]?.textContent || 'Fish',
      water:document.getElementById('water').value, weather:document.getElementById('weather').value,
      environment:document.getElementById('location').value,
      name:card.querySelector('.pick-title strong').textContent,
      size:card.querySelector('.pick-title span').textContent,
      type:fields[0] || '', color:fields.slice(1).join(' · '),
      suggestedDepth:pair('Suggested depth',1), technique:pair('Technique',2), retrieve:pair('Retrieve',3),
      why:document.getElementById('whyText').textContent
    };
    try { const result = await request('save-recommendation', payload); toast(result.message); refresh(); }
    catch (error) { toast(error.message); }
  });

  document.getElementById('catchForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!user) { authDialog.showModal(); toast('Log in to add a catch to your personal log.'); return; }
    const button = event.currentTarget.querySelector('button[type=submit]');
    button.disabled = true;
    try {
      const result = await request('add-catch', Object.fromEntries(new FormData(event.currentTarget).entries()));
      toast(result.message); event.currentTarget.reset();
      document.getElementById('catchDate').value = new Date().toISOString().slice(0,10);
      refresh();
    } catch (error) { toast(error.message); }
    finally { button.disabled = false; }
  });

  document.getElementById('my-log').addEventListener('click', async event => {
    const savedId = event.target.closest('[data-delete-saved]')?.dataset.deleteSaved;
    const catchId = event.target.closest('[data-delete-catch]')?.dataset.deleteCatch;
    if (!savedId && !catchId) return;
    try { const result = await request(savedId ? 'delete-saved' : 'delete-catch', {id:Number(savedId || catchId)}); toast(result.message); refresh(); }
    catch (error) { toast(error.message); }
  });
})();
