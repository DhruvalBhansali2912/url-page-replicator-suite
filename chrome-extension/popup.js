document.addEventListener('DOMContentLoaded', async () => {
  // Elements
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');
  const pageUrlInput = document.getElementById('page-url');
  const useCurrentTabBtn = document.getElementById('use-current-tab');
  const frameworkSelect = document.getElementById('framework-select');
  const replicateBtn = document.getElementById('replicate-btn');
  const componentFrameworkSelect = document.getElementById('component-framework-select');
  const activatePickerBtn = document.getElementById('activate-picker-btn');
  const openSettingsBtn = document.getElementById('open-settings');

  const totalTokensEl = document.getElementById('total-tokens');
  const freeTokensEl = document.getElementById('free-tokens');
  const purchasedTokensEl = document.getElementById('purchased-tokens');
  const renewLinkEl = document.getElementById('renew-link');

  const statusBox = document.getElementById('status-box');
  const progressBarFill = document.getElementById('progress-bar-fill');
  const statusMessage = document.getElementById('status-message');
  const alertBox = document.getElementById('alert-box');

  // Load Settings
  const settings = await chrome.storage.sync.get({
    serverUrl: 'http://localhost/test/wp-json',
    apiToken: 'df7fe8ab2cce8030d18c6f46cce20716b37efe7fa9c48e5b',
    renewalUrl: 'https://inventkid.com/pricing'
  });

  // 1. Tab Switching
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      tabButtons.forEach(b => b.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));

      btn.classList.add('active');
      const targetTab = btn.getAttribute('data-tab');
      document.getElementById(`tab-${targetTab}`).classList.add('active');
    });
  });

  // 2. Detect Current Tab URL
  async function detectCurrentTab() {
    try {
      const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (activeTab && activeTab.url && activeTab.url.startsWith('http')) {
        pageUrlInput.value = activeTab.url;
      }
    } catch (e) {
      console.warn('Could not auto-detect tab URL', e);
    }
  }
  detectCurrentTab();
  useCurrentTabBtn.addEventListener('click', detectCurrentTab);

  // 3. Settings Button
  openSettingsBtn.addEventListener('click', () => {
    chrome.runtime.openOptionsPage();
  });

  // 4. Fetch Live Token Balance
  async function fetchTokenInfo() {
    if (!settings.apiToken) {
      totalTokensEl.textContent = '0';
      showAlert('Please configure your API token in settings.', 'error');
      return;
    }

    try {
      const endpoint = `${settings.serverUrl.replace(/\/$/, '')}/upr-server/v1/credits/info`;
      const res = await fetch(endpoint, {
        headers: {
          'Authorization': `Bearer ${settings.apiToken}`
        }
      });

      if (!res.ok) {
        throw new Error(`Server returned status ${res.status}`);
      }

      const data = await res.json();
      totalTokensEl.textContent = data.total_available ?? '--';
      freeTokensEl.textContent = data.free_monthly_remaining ?? '0';
      purchasedTokensEl.textContent = data.purchased_remaining ?? '0';

      if (data.renewal_url) {
        renewLinkEl.href = `${data.renewal_url}?token=${encodeURIComponent(settings.apiToken)}`;
      } else {
        renewLinkEl.href = settings.renewalUrl;
      }
    } catch (err) {
      console.warn('Failed to fetch credit balance:', err);
      totalTokensEl.textContent = 'Err';
    }
  }
  fetchTokenInfo();

  // Restore background replication state if process is active or recently completed
  function restoreReplicationState(state) {
    if (!state) return;

    if (state.isReplicating) {
      replicateBtn.disabled = true;
      replicateBtn.querySelector('.btn-text').textContent = 'Replicating...';
      replicateBtn.querySelector('.btn-spinner').classList.remove('hidden');
      hideAlert();
      showProgress(state.percent || 30, state.statusMessage || 'Replicating in background...');
    } else if (state.completed) {
      replicateBtn.disabled = false;
      replicateBtn.querySelector('.btn-text').textContent = 'Generate & Download Project';
      replicateBtn.querySelector('.btn-spinner').classList.add('hidden');
      if (Date.now() - (state.startedAt || 0) < 180000) {
        showProgress(100, state.statusMessage || 'Replication complete! Download started.');
        showAlert(`Successfully generated ${state.format || 'project'}! Saved as ${state.filename || 'archive.zip'}`, 'success');
      } else {
        hideProgress();
      }
    } else if (state.error) {
      replicateBtn.disabled = false;
      replicateBtn.querySelector('.btn-text').textContent = 'Generate & Download Project';
      replicateBtn.querySelector('.btn-spinner').classList.add('hidden');
      if (Date.now() - (state.startedAt || 0) < 180000) {
        showAlert(`Replication failed: ${state.error}`, 'error');
      }
      hideProgress();
    }
  }

  // Check storage on popup open
  chrome.storage.local.get(['replicationState'], res => {
    restoreReplicationState(res.replicationState);
  });

  // Listen for real-time background progress changes
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area === 'local' && changes.replicationState) {
      restoreReplicationState(changes.replicationState.newValue);
      if (changes.replicationState.newValue && changes.replicationState.newValue.completed) {
        fetchTokenInfo();
      }
    }
  });

  // 5. Full Page Replication Handler (Delegated to Background Service Worker)
  replicateBtn.addEventListener('click', async () => {
    const url = pageUrlInput.value.trim();
    if (!url) {
      showAlert('Please enter a valid webpage URL.', 'error');
      return;
    }

    if (!settings.apiToken) {
      showAlert('Missing API token. Click ⚙️ to set your token.', 'error');
      return;
    }

    const format = frameworkSelect.value;

    // UI Loading State
    replicateBtn.disabled = true;
    replicateBtn.querySelector('.btn-text').textContent = 'Replicating...';
    replicateBtn.querySelector('.btn-spinner').classList.remove('hidden');
    hideAlert();
    showProgress(20, 'Capturing webpage and localizing assets in background...');

    chrome.runtime.sendMessage({
      action: 'start_replication',
      url,
      format,
      serverUrl: settings.serverUrl,
      apiToken: settings.apiToken
    }, (res) => {
      if (chrome.runtime.lastError) {
        showAlert(`Failed to start replication: ${chrome.runtime.lastError.message}`, 'error');
        replicateBtn.disabled = false;
        replicateBtn.querySelector('.btn-text').textContent = 'Generate & Download Project';
        replicateBtn.querySelector('.btn-spinner').classList.add('hidden');
        hideProgress();
      }
    });
  });

  // 6. Component Picker Activation (Direct script execution)
  activatePickerBtn.addEventListener('click', async () => {
    const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!activeTab || !activeTab.id) {
      showAlert('Cannot inspect this tab.', 'error');
      return;
    }

    const tabUrl = activeTab.url || '';
    if (tabUrl.startsWith('chrome://') || tabUrl.startsWith('edge://') || tabUrl.startsWith('chrome-extension://') || tabUrl.startsWith('about:')) {
      showAlert('Cannot inspect Chrome internal pages. Please open a website like apple.com, google.com, or github.com first.', 'error');
      return;
    }

    const format = componentFrameworkSelect.value;
    const config = {
      format: format,
      serverUrl: settings.serverUrl,
      apiToken: settings.apiToken
    };

    try {
      // 1. Inject CSS and content-script
      await chrome.scripting.insertCSS({
        target: { tabId: activeTab.id },
        files: ['content-script.css']
      }).catch(() => {});

      await chrome.scripting.executeScript({
        target: { tabId: activeTab.id },
        files: ['content-script.js']
      }).catch(() => {});

      // 2. Directly trigger the picker function inside the tab
      await chrome.scripting.executeScript({
        target: { tabId: activeTab.id },
        func: (cfg) => {
          if (typeof window.__UPR_START_PICKER__ === 'function') {
            window.__UPR_START_PICKER__(cfg);
          }
        },
        args: [config]
      });

      // Instantly close popup to let user interact directly with page
      window.close();
    } catch (err) {
      console.error('Picker activation error:', err);
      showAlert(`Could not activate element picker: ${err.message}`, 'error');
    }
  });

  // Helper Functions
  function showProgress(percent, message) {
    statusBox.classList.remove('hidden');
    progressBarFill.style.width = `${percent}%`;
    statusMessage.textContent = message;
  }

  function hideProgress() {
    statusBox.classList.add('hidden');
    progressBarFill.style.width = '0%';
  }

  function showAlert(msg, type = 'error') {
    alertBox.textContent = msg;
    alertBox.className = `alert-box ${type}`;
    alertBox.classList.remove('hidden');
  }

  function hideAlert() {
    alertBox.classList.add('hidden');
  }
});
