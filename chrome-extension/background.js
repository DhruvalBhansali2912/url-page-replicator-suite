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

// Proxy API requests and handle long-running background replication tasks
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'transpile_component') {
    handleTranspileComponent(request)
      .then(result => sendResponse(result))
      .catch(err => sendResponse({ success: false, error: err.message }));
    return true; // Keep message channel open for async response
  }

  if (request.action === 'start_replication') {
    handleReplication(request);
    sendResponse({ success: true, message: 'Replication started in background' });
    return false;
  }
});

async function handleReplication(data) {
  const { serverUrl, apiToken, url, format } = data;
  const endpoint = `${serverUrl.replace(/\/$/, '')}/upr-server/v1/replicate`;

  const state = {
    isReplicating: true,
    url,
    format,
    percent: 20,
    statusMessage: 'Capturing webpage and localizing assets...',
    completed: false,
    downloadUrl: null,
    error: null,
    startedAt: Date.now()
  };

  await chrome.storage.local.set({ replicationState: state });

  // Progress simulation timers while waiting for server
  const timer1 = setTimeout(() => {
    chrome.storage.local.get(['replicationState'], res => {
      if (res.replicationState && res.replicationState.isReplicating) {
        chrome.storage.local.set({
          replicationState: {
            ...res.replicationState,
            percent: 50,
            statusMessage: format !== 'raw' ? `Converting into component-based ${format}...` : 'Packaging offline assets...'
          }
        });
      }
    });
  }, 3500);

  const timer2 = setTimeout(() => {
    chrome.storage.local.get(['replicationState'], res => {
      if (res.replicationState && res.replicationState.isReplicating) {
        chrome.storage.local.set({
          replicationState: {
            ...res.replicationState,
            percent: 80,
            statusMessage: 'Compiling project files & creating ZIP package...'
          }
        });
      }
    });
  }, 10000);

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiToken}`
      },
      body: JSON.stringify({ url, format })
    });

    clearTimeout(timer1);
    clearTimeout(timer2);

    const resData = await response.json();

    if (!response.ok) {
      throw new Error(resData.message || `Server returned error ${response.status}`);
    }

    if (!resData.download_url) {
      throw new Error('No download URL returned by the server.');
    }

    const filename = `${resData.slug || 'replicated-project'}-${format}.zip`;

    // Automatically trigger file download
    chrome.downloads.download({
      url: resData.download_url,
      filename: filename,
      saveAs: true
    });

    await chrome.storage.local.set({
      replicationState: {
        isReplicating: false,
        completed: true,
        percent: 100,
        statusMessage: 'Replication complete! Download started.',
        downloadUrl: resData.download_url,
        filename: filename,
        format: format,
        error: null,
        startedAt: Date.now()
      }
    });
  } catch (err) {
    clearTimeout(timer1);
    clearTimeout(timer2);
    console.error('Background replication error:', err);

    await chrome.storage.local.set({
      replicationState: {
        isReplicating: false,
        completed: false,
        percent: 0,
        statusMessage: '',
        error: err.message,
        startedAt: Date.now()
      }
    });
  }
}

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
