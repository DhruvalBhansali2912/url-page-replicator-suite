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
