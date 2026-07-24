/* avuz_body_cache: IndexedDB store. One DB per origin, namespaced per user tag. */
(function () {
  var DB = 'avuz_body_cache', STORE = 'bodies', META = 'meta';
  var dbp = null, now = function () { return Date.now(); };

  function openRaw() {
    return new Promise(function (res, rej) {
      var r = indexedDB.open(DB, 1);
      r.onupgradeneeded = function () {
        var db = r.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var s = db.createObjectStore(STORE, { keyPath: 'k' });
          s.createIndex('lastAccess', 'lastAccess');
        }
        if (!db.objectStoreNames.contains(META)) db.createObjectStore(META, { keyPath: 'id' });
      };
      r.onsuccess = function () { res(r.result); };
      r.onerror = function () { rej(r.error); };
    });
  }
  function del() { return new Promise(function (res) { var r = indexedDB.deleteDatabase(DB); r.onsuccess = r.onerror = function () { res(); }; }); }
  function tx(db, store, mode) { return db.transaction(store, mode).objectStore(store); }
  function pReq(req) { return new Promise(function (res, rej) { req.onsuccess = function () { res(req.result); }; req.onerror = function () { rej(req.error); }; }); }

  window.avuzIdb = {
    open: function (userTag) {
      dbp = openRaw().then(function (db) {
        return pReq(tx(db, META, 'readonly').get('user')).then(function (m) {
          if (m && m.value !== userTag) {
            db.close();
            return del().then(openRaw).then(function (db2) {
              return pReq(tx(db2, META, 'readwrite').put({ id: 'user', value: userTag })).then(function () { return db2; });
            });
          }
          if (!m) return pReq(tx(db, META, 'readwrite').put({ id: 'user', value: userTag })).then(function () { return db; });
          return db;
        });
      });
      return dbp.then(function () {});
    },
    has: function (k) { return dbp.then(function (db) { return pReq(tx(db, STORE, 'readonly').get(k)); }).then(function (v) { return !!v; }); },
    get: function (k) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readonly').get(k)).then(function (v) {
          if (!v) return null;
          v.lastAccess = now();
          tx(db, STORE, 'readwrite').put(v);
          return v;
        });
      });
    },
    put: function (k, html) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readwrite').put({ k: k, html: html, bytes: html.length, lastAccess: now(), createdAt: now() }));
      });
    },
    evictToBudget: function (maxEntries, maxBytes) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readonly').getAll()).then(function (all) {
          var bytes = all.reduce(function (s, e) { return s + (e.bytes || 0); }, 0);
          if (all.length <= maxEntries && bytes <= maxBytes) return;
          all.sort(function (a, b) { return a.lastAccess - b.lastAccess; }); // oldest first
          var s = tx(db, STORE, 'readwrite');
          for (var i = 0; i < all.length && (all.length - i > maxEntries || bytes > maxBytes); i++) {
            s.delete(all[i].k); bytes -= (all[i].bytes || 0);
          }
        });
      });
    },
    clearAll: function () { return dbp.then(function (db) { return pReq(tx(db, STORE, 'readwrite').clear()); }); }
  };
})();
