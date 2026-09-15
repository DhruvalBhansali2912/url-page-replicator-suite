// Service Worker for InventKid Replicator Extension
chrome.runtime.onInstalled.addListener(async () => {
  const current = await chrome.storage.sync.get(['serverUrl', 'apiToken', 'renewalUrl']);
  if (!current.serverUrl) {
    chrome.storage.sync.set({
      serverUrl: 'http://localhost/test/wp-json',
      apiToken: 'df7fe8ab2cce8030d18c6f46cce20716b37efe7fa9c48e5b',
      renewalUrl: 'https://inventkid.com/pricing'
    });
  }
  console.log('InventKid Page & Component Replicator installed.');
});

// Proxy API requests from content scripts to bypass Mixed-Content and page CSP restrictions
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'transpile_component') {
    handleTranspileComponent(request)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ success: false, error: err.message }));
    return true; // Keep message channel open for async response
  }
});

async function handleTranspileComponent(data) {
  const { serverUrl, apiToken, html, css, format, title } = data;
  const endpoint = `${serverUrl.replace(/\/$/, '')}/upr-server/v1/transpile-component`;

  const response = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${apiToken}`
    },
    body: JSON.stringify({
      html,
      css,
      format,
      component_name: title
    })
  });

  const resData = await response.json();
  if (!response.ok) {
    throw new Error(resData.message || `Server returned error ${response.status}`);
  }

  return { success: true, data: resData };
}
