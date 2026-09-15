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

  // 5. Full Page Replication Handler
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
    showProgress(20, 'Capturing webpage and localizing assets...');

    try {
      const endpoint = `${settings.serverUrl.replace(/\/$/, '')}/upr-server/v1/replicate`;
      
      showProgress(45, format !== 'raw' ? `Converting into component-based ${format}...` : 'Packaging offline assets...');

      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${settings.apiToken}`
        },
        body: JSON.stringify({ url, format })
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || `Server error ${response.status}`);
      }

      showProgress(85, 'Downloading project ZIP package...');

      if (data.download_url) {
        const filename = `${data.slug || 'replicated-project'}-${format}.zip`;
        chrome.downloads.download({
          url: data.download_url,
          filename: filename,
          saveAs: true
        });

        showProgress(100, 'Replication complete! Download started.');
        showAlert(`Successfully generated ${format} project! Saved as ${filename}`, 'success');
        fetchTokenInfo();
      } else {
        throw new Error('No download URL returned by the server.');
      }
    } catch (err) {
      showAlert(`Replication failed: ${err.message}`, 'error');
      hideProgress();
    } finally {
      replicateBtn.disabled = false;
      replicateBtn.querySelector('.btn-text').textContent = 'Generate & Download Project';
      replicateBtn.querySelector('.btn-spinner').classList.add('hidden');
    }
  });

  // 6. Component Picker Activation
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

    // Send activation message; if content script isn't loaded yet, inject it dynamically
    try {
      await chrome.tabs.sendMessage(activeTab.id, {
        action: 'activate_picker',
        format: format,
        serverUrl: settings.serverUrl,
        apiToken: settings.apiToken
      });
      window.close();
    } catch (msgErr) {
      // Content script not loaded yet (e.g. tab was open before extension installed). Inject and retry:
      try {
        await chrome.scripting.insertCSS({
          target: { tabId: activeTab.id },
          files: ['content-script.css']
        });
        await chrome.scripting.executeScript({
          target: { tabId: activeTab.id },
          files: ['content-script.js']
        });

        setTimeout(async () => {
          try {
            await chrome.tabs.sendMessage(activeTab.id, {
              action: 'activate_picker',
              format: format,
              serverUrl: settings.serverUrl,
              apiToken: settings.apiToken
            });
            window.close();
          } catch (retryErr) {
            showAlert('Please refresh the webpage and try Activate Component Picker again.', 'error');
          }
        }, 120);
      } catch (injectErr) {
        showAlert(`Could not inspect page: ${injectErr.message}`, 'error');
      }
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
