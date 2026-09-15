document.addEventListener('DOMContentLoaded', async () => {
  const serverUrlInput = document.getElementById('server-url');
  const apiTokenInput = document.getElementById('api-token');
  const renewalUrlInput = document.getElementById('renewal-url');
  const saveBtn = document.getElementById('save-btn');
  const testBtn = document.getElementById('test-btn');
  const alertBox = document.getElementById('status-alert');

  // Load saved settings
  const settings = await chrome.storage.sync.get({
    serverUrl: 'http://localhost/test/wp-json',
    apiToken: 'df7fe8ab2cce8030d18c6f46cce20716b37efe7fa9c48e5b',
    renewalUrl: 'https://inventkid.com/pricing'
  });

  serverUrlInput.value = settings.serverUrl;
  apiTokenInput.value = settings.apiToken;
  renewalUrlInput.value = settings.renewalUrl;

  // Save Settings Handler
  saveBtn.addEventListener('click', async () => {
    const serverUrl = serverUrlInput.value.trim().replace(/\/$/, '');
    const apiToken = apiTokenInput.value.trim();
    const renewalUrl = renewalUrlInput.value.trim();

    if (!serverUrl || !apiToken) {
      showAlert('Server URL and API Token are required.', 'error');
      return;
    }

    await chrome.storage.sync.set({
      serverUrl,
      apiToken,
      renewalUrl
    });

    showAlert('✅ Settings saved successfully!', 'success');
  });

  // Test Connection Handler
  testBtn.addEventListener('click', async () => {
    const serverUrl = serverUrlInput.value.trim().replace(/\/$/, '');
    const apiToken = apiTokenInput.value.trim();

    if (!serverUrl || !apiToken) {
      showAlert('Please enter both Server URL and API Token before testing.', 'error');
      return;
    }

    testBtn.disabled = true;
    testBtn.textContent = 'Testing...';

    try {
      const endpoint = `${serverUrl}/upr-server/v1/credits/info`;
      const res = await fetch(endpoint, {
        headers: {
          'Authorization': `Bearer ${apiToken}`
        }
      });

      if (!res.ok) {
        throw new Error(`Server returned HTTP ${res.status}`);
      }

      const data = await res.json();
      showAlert(
        `🎉 Connection successful! Token belongs to "${data.client_url}". Total available tokens: ${data.total_available} (${data.free_monthly_remaining} free monthly + ${data.purchased_remaining} purchased).`,
        'success'
      );
    } catch (err) {
      showAlert(`❌ Connection failed: ${err.message}`, 'error');
    } finally {
      testBtn.disabled = false;
      testBtn.textContent = 'Test Connection';
    }
  });

  function showAlert(msg, type = 'error') {
    alertBox.textContent = msg;
    alertBox.className = `alert ${type}`;
    alertBox.classList.remove('hidden');
  }
});
