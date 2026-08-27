// Mock globalThis
globalThis.uprGetPathname = () => "/OVERRIDDEN_VIA_PROXY";
globalThis.location = { pathname: "/ORIGINAL" };

// Proxy definition
const window = new Proxy(globalThis, {
  get(target, prop) {
    if (prop === 'location') {
      const loc = target.location || {};
      return new Proxy(loc, {
        get(locTarget, locProp) {
          if (locProp === 'pathname') {
            return globalThis.uprGetPathname ? globalThis.uprGetPathname() : locTarget.pathname;
          }
          if (locProp === 'then') return undefined;
          const val = locTarget[locProp];
          if (typeof val === 'function') {
            return val.bind(locTarget);
          }
          return val;
        }
      });
    }
    const val = target[prop];
    if (typeof val === 'function') {
      return val.bind(target);
    }
    return val;
  }
});

const location = window.location;

// Test accesses
console.log("window.location.pathname:", window.location.pathname);
console.log("location.pathname:", location.pathname);

const { pathname } = window.location;
console.log("Destructured pathname:", pathname);
