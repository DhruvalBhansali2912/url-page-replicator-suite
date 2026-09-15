(() => {
  let isPickerActive = false;
  let hoveredElement = null;
  let selectedElement = null;
  let overlayEl = null;
  let badgeEl = null;
  let modalEl = null;
  let pickerConfig = {
    format: 'react-tailwind',
    serverUrl: 'http://localhost/test/wp-json',
    apiToken: ''
  };

  // Listen for messages from popup
  chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'activate_picker') {
      pickerConfig.format = request.format || 'react-tailwind';
      pickerConfig.serverUrl = request.serverUrl || 'http://localhost/test/wp-json';
      pickerConfig.apiToken = request.apiToken || '';
      startPicker();
      sendResponse({ status: 'picker_activated' });
    }
  });

  function startPicker() {
    if (isPickerActive) return;
    isPickerActive = true;
    cleanupModal();

    // Create highlight overlay
    overlayEl = document.createElement('div');
    overlayEl.id = 'upr-picker-overlay';
    badgeEl = document.createElement('div');
    badgeEl.id = 'upr-picker-badge';
    overlayEl.appendChild(badgeEl);
    document.body.appendChild(overlayEl);

    document.addEventListener('mousemove', onMouseMove, true);
    document.addEventListener('click', onElementClick, true);
    document.addEventListener('keydown', onKeyDown, true);

    showToast('🎯 Element Picker active: Hover and click on any component to extract.');
  }

  function stopPicker() {
    isPickerActive = false;
    if (overlayEl && overlayEl.parentNode) {
      overlayEl.parentNode.removeChild(overlayEl);
    }
    overlayEl = null;
    badgeEl = null;
    document.removeEventListener('mousemove', onMouseMove, true);
    document.removeEventListener('click', onElementClick, true);
    document.removeEventListener('keydown', onKeyDown, true);
  }

  function onMouseMove(e) {
    if (!isPickerActive) return;
    const target = document.elementFromPoint(e.clientX, e.clientY);
    if (!target || target === overlayEl || target.closest('#upr-picker-overlay') || target.closest('#upr-component-modal')) {
      return;
    }
    hoveredElement = target;
    updateHighlight(target);
  }

  function updateHighlight(el) {
    if (!overlayEl || !el) return;
    const rect = el.getBoundingClientRect();
    overlayEl.style.top = `${rect.top}px`;
    overlayEl.style.left = `${rect.left}px`;
    overlayEl.style.width = `${rect.width}px`;
    overlayEl.style.height = `${rect.height}px`;

    const tagName = el.tagName.toLowerCase();
    const className = el.className && typeof el.className === 'string'
      ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.')
      : '';
    badgeEl.textContent = `<${tagName}${className}> (${Math.round(rect.width)} × ${Math.round(rect.height)})`;
  }

  function onKeyDown(e) {
    if (!isPickerActive) return;
    if (e.key === 'Escape') {
      stopPicker();
      showToast('Element picker cancelled.');
    } else if (e.key === 'ArrowUp' && hoveredElement && hoveredElement.parentElement) {
      e.preventDefault();
      hoveredElement = hoveredElement.parentElement;
      updateHighlight(hoveredElement);
    } else if (e.key === 'ArrowDown' && hoveredElement && hoveredElement.firstElementChild) {
      e.preventDefault();
      hoveredElement = hoveredElement.firstElementChild;
      updateHighlight(hoveredElement);
    }
  }

  function onElementClick(e) {
    if (!isPickerActive) return;
    e.preventDefault();
    e.stopPropagation();

    selectedElement = hoveredElement;
    stopPicker();

    if (selectedElement) {
      openComponentModal(selectedElement);
    }
  }

  // Extract CSS rules from all active stylesheets that match the element or its descendants
  function extractMatchedCSS(rootEl) {
    let collectedCSS = '';
    const allElements = [rootEl, ...Array.from(rootEl.querySelectorAll('*'))];

    for (let sheet of document.styleSheets) {
      try {
        const rules = sheet.cssRules || sheet.rules;
        if (!rules) continue;

        for (let rule of rules) {
          if (rule.type === CSSRule.STYLE_RULE && rule.selectorText) {
            const selectors = rule.selectorText.split(',');
            let matched = false;

            for (let sel of selectors) {
              const cleanSel = sel.trim();
              try {
                for (let el of allElements) {
                  if (el.matches && el.matches(cleanSel)) {
                    matched = true;
                    break;
                  }
                }
              } catch (err) {
                // Ignore pseudo-selectors that throw in matches()
              }
              if (matched) break;
            }

            if (matched) {
              collectedCSS += rule.cssText + '\n';
            }
          } else if (rule.type === CSSRule.MEDIA_RULE) {
            // Check media query child rules
            let mediaMatchedRules = '';
            for (let innerRule of rule.cssRules) {
              if (innerRule.selectorText) {
                for (let el of allElements) {
                  try {
                    if (el.matches(innerRule.selectorText)) {
                      mediaMatchedRules += '  ' + innerRule.cssText + '\n';
                      break;
                    }
                  } catch (e) {}
                }
              }
            }
            if (mediaMatchedRules) {
              collectedCSS += `@media ${rule.conditionText} {\n${mediaMatchedRules}}\n`;
            }
          }
        }
      } catch (err) {
        // Cross-origin stylesheets may block reading rules
      }
    }

    return collectedCSS;
  }

  function openComponentModal(el) {
    cleanupModal();

    const tagName = el.tagName.toLowerCase();
    const guessedName = (el.id ? el.id : (el.className && typeof el.className === 'string' ? el.className.split(/\s+/)[0] : tagName))
      .replace(/[^a-zA-Z0-9]/g, ' ')
      .split(' ')
      .map(w => w.charAt(0).toUpperCase() + w.slice(1))
      .join('') || 'CustomComponent';

    modalEl = document.createElement('div');
    modalEl.id = 'upr-component-modal';
    modalEl.innerHTML = `
      <div class="upr-modal-header">
        <div class="upr-modal-title"><span>⚡</span> Component Extractor</div>
        <button class="upr-modal-close" id="upr-close-btn">&times;</button>
      </div>
      <div class="upr-modal-body">
        <div class="upr-form-row">
          <label>Component Name</label>
          <input type="text" id="upr-comp-name" value="${guessedName}" />
        </div>
        <div class="upr-form-row">
          <label>Framework</label>
          <select id="upr-comp-format">
            <option value="react-tailwind" ${pickerConfig.format === 'react-tailwind' ? 'selected' : ''}>React + Tailwind</option>
            <option value="react-css" ${pickerConfig.format === 'react-css' ? 'selected' : ''}>React + CSS Modules</option>
            <option value="angular" ${pickerConfig.format === 'angular' ? 'selected' : ''}>Angular 17+ Standalone</option>
            <option value="html-clean" ${pickerConfig.format === 'html-clean' ? 'selected' : ''}>Clean HTML / CSS</option>
          </select>
        </div>
        <div class="upr-modal-actions">
          <button class="upr-btn-primary" id="upr-copy-btn">
            <span>📋 Copy Code to Clipboard</span>
          </button>
          <button class="upr-btn-secondary" id="upr-repicker-btn">
            <span>🎯 Pick Another Element</span>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(modalEl);

    document.getElementById('upr-close-btn').addEventListener('click', cleanupModal);
    document.getElementById('upr-repicker-btn').addEventListener('click', () => {
      cleanupModal();
      startPicker();
    });

    document.getElementById('upr-copy-btn').addEventListener('click', async () => {
      const copyBtn = document.getElementById('upr-copy-btn');
      copyBtn.disabled = true;
      copyBtn.innerHTML = '<span>⏳ Generating AI Component...</span>';

      const compName = document.getElementById('upr-comp-name').value.trim() || 'Component';
      const format = document.getElementById('upr-comp-format').value;
      const htmlSlice = el.outerHTML;
      const cssSlice = extractMatchedCSS(el);

      try {
        const endpoint = `${pickerConfig.serverUrl.replace(/\/$/, '')}/upr-server/v1/transpile-component`;
        const res = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${pickerConfig.apiToken}`
          },
          body: JSON.stringify({
            html: htmlSlice,
            css: cssSlice,
            format: format,
            title: compName
          })
        });

        const data = await res.json();
        if (!res.ok) {
          throw new Error(data.message || `Server returned ${res.status}`);
        }

        if (data.component) {
          await navigator.clipboard.writeText(data.component);
          showToast(`✅ ${compName} copied to clipboard! (1 token consumed)`);
          cleanupModal();
        } else {
          throw new Error('No component code returned.');
        }
      } catch (err) {
        showToast(`❌ Error: ${err.message}`);
        copyBtn.disabled = false;
        copyBtn.innerHTML = '<span>📋 Copy Code to Clipboard</span>';
      }
    });
  }

  function cleanupModal() {
    if (modalEl && modalEl.parentNode) {
      modalEl.parentNode.removeChild(modalEl);
    }
    modalEl = null;
  }

  function showToast(message) {
    const existing = document.getElementById('upr-toast');
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }

    const toast = document.createElement('div');
    toast.id = 'upr-toast';
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
      if (toast && toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 3200);
  }
})();
