















const BD_DB_NAME = 'bantaydagat-offline';
const BD_DB_VERSION = 3;

function bdOpenDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(BD_DB_NAME, BD_DB_VERSION);
        req.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('ff_cache')) {
                db.createObjectStore('ff_cache', { keyPath: 'boat_registry_number' });
            }
            if (!db.objectStoreNames.contains('rfid_cache')) {
                db.createObjectStore('rfid_cache', { keyPath: 'rfid_uid' });
            }
            if (!db.objectStoreNames.contains('qr_cache')) {
                db.createObjectStore('qr_cache', { keyPath: 'qr_token' });
            }
            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
            if (!db.objectStoreNames.contains('violation_queue')) {
                db.createObjectStore('violation_queue', { keyPath: 'local_id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains('registration_queue')) {
                db.createObjectStore('registration_queue', { keyPath: 'local_id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function bdTx(db, storeName, mode) {
    return db.transaction(storeName, mode).objectStore(storeName);
}


async function bdPut(storeName, value) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const req = bdTx(db, storeName, 'readwrite').put(value);
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function bdGet(storeName, key) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const req = bdTx(db, storeName, 'readonly').get(key);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
    });
}

async function bdGetAll(storeName) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const req = bdTx(db, storeName, 'readonly').getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

async function bdDelete(storeName, key) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const req = bdTx(db, storeName, 'readwrite').delete(key);
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
}

async function bdClear(storeName) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const req = bdTx(db, storeName, 'readwrite').clear();
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
}


async function bdReplaceFFCache(records) {
    const db = await bdOpenDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(['ff_cache', 'rfid_cache', 'qr_cache'], 'readwrite');
        const ffStore = tx.objectStore('ff_cache');
        const rfidStore = tx.objectStore('rfid_cache');
        const qrStore = tx.objectStore('qr_cache');
        ffStore.clear();
        rfidStore.clear();
        qrStore.clear();
        for (const r of records) {
            ffStore.put(r);
            if (r.rfid_uid) {
                rfidStore.put({ rfid_uid: String(r.rfid_uid).trim().toUpperCase(), boat_registry_number: r.boat_registry_number });
            }
            if (r.qr_token) {
                qrStore.put({ qr_token: r.qr_token, boat_registry_number: r.boat_registry_number });
            }
        }
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function bdFindFFByBRN(brn) {
    if (!brn) return null;
    return bdGet('ff_cache', brn.toUpperCase().trim());
}






async function bdFindFFByRFID(uid) {
    if (!uid) return null;
    const mapping = await bdGet('rfid_cache', uid.trim().toUpperCase());
    if (!mapping) return null;
    return bdFindFFByBRN(mapping.boat_registry_number);
}




async function bdFindFFByQRToken(token) {
    if (!token) return null;
    const mapping = await bdGet('qr_cache', token.trim());
    if (!mapping) return null;
    return bdFindFFByBRN(mapping.boat_registry_number);
}

async function bdSearchFFCache(query) {
    const all = await bdGetAll('ff_cache');
    const tokens = (query || '').toLowerCase().split(/\s+/).filter(Boolean);
    if (!tokens.length) return all;

    return all.filter(f => {
        const hay = [f.first_name, f.middle_name, f.last_name, f.barangay, f.municipality, f.boat_name, f.boat_registry_number]
            .map(x => (x || '').toLowerCase()).join(' ');
        return tokens.every(t => hay.includes(t));
    }).sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '') || (a.first_name || '').localeCompare(b.first_name || ''));
}


if (typeof self !== 'undefined') {
    self.bdOpenDB = bdOpenDB;
    self.bdPut = bdPut;
    self.bdGet = bdGet;
    self.bdGetAll = bdGetAll;
    self.bdDelete = bdDelete;
    self.bdClear = bdClear;
    self.bdReplaceFFCache = bdReplaceFFCache;
    self.bdFindFFByBRN = bdFindFFByBRN;
    self.bdFindFFByRFID = bdFindFFByRFID;
    self.bdFindFFByQRToken = bdFindFFByQRToken;
    self.bdSearchFFCache = bdSearchFFCache;
}