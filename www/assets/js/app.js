/**
 * NOTE on API_KEY: fetched from api/public_key.php on load (see that file) rather than
 * hardcoded here, so api/config/config.php stays the single source of truth -- editing
 * this file to add a feature can never again silently reset a manually-synced key back
 * to a placeholder. It is NOT a secret in the same sense as the DB credentials, which
 * never leave the server; its only purpose is to stop naive/automated direct hits to
 * the API from clients that never loaded this page.
 *
 * Real access control (who can log in, what they can see) is the session-based login in
 * api/login.php -- api/eeprom_config.php requires both the API key AND a logged-in session.
 */
let API_KEY = null;
const API_BASE = "api/eeprom_config.php";

// file_format, output_format and support_mode are <select>s, not text inputs --
// same .value handling, fixed value domains. See index.html and ENUM_FIELDS in
// api/eeprom_config.php for why they are fixed.
const TEXT_FIELDS = ["file_format", "fps", "file_name", "MHz", "output_format", "support_mode", "content", "comment",
    "ext_str1", "ext_str2", "ext_txt1", "ext_txt2", "ext_txt3", "ext_txt4", "ext_txt5"];

let currentItems = [];

const els = {
    userBox: document.getElementById("userBox"),
    userAccountLabel: document.getElementById("userAccountLabel"),
    logoutBtn: document.getElementById("logoutBtn"),
    loginPanel: document.getElementById("loginPanel"),
    loginForm: document.getElementById("loginForm"),
    loginAccount: document.getElementById("loginAccount"),
    loginPassword: document.getElementById("loginPassword"),
    loginMessage: document.getElementById("loginMessage"),
    appContent: document.getElementById("appContent"),

    itemDialog: document.getElementById("itemDialog"),
    addItemBtn: document.getElementById("addItemBtn"),
    form: document.getElementById("itemForm"),
    index: document.getElementById("itemIndex"),
    isPGL: document.getElementById("isPGL"),
    ext_int1: document.getElementById("ext_int1"),
    formTitle: document.getElementById("formTitle"),
    submitBtn: document.getElementById("submitBtn"),
    cancelEditBtn: document.getElementById("cancelEditBtn"),
    formMessage: document.getElementById("formMessage"),
    contentFile: document.getElementById("contentFile"),
    contentHint: document.getElementById("contentHint"),
    itemsBody: document.getElementById("itemsBody"),
    refreshBtn: document.getElementById("refreshBtn"),
    itemsTotal: document.getElementById("itemsTotal"),
    searchBox: document.getElementById("searchBox"),
    tagCloud: document.getElementById("tagCloud"),
    toastContainer: document.getElementById("toastContainer"),
};
TEXT_FIELDS.forEach((f) => { els[f] = document.getElementById(f); });

const ICONS = {
    edit: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
    download: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
    delete: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
};

function showToast(message, isError) {
    const toast = document.createElement("div");
    toast.className = "toast" + (isError ? " toast-error" : "");
    toast.textContent = message;
    els.toastContainer.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add("show"));
    setTimeout(() => {
        toast.classList.remove("show");
        setTimeout(() => toast.remove(), 250);
    }, 2500);
}

async function apiFetch(url, options = {}) {
    const res = await fetch(url, {
        ...options,
        credentials: "same-origin", // send/receive the PHP session cookie
        headers: {
            "X-API-Key": API_KEY,
            ...(options.body ? { "Content-Type": "application/json" } : {}),
            ...options.headers,
        },
    });
    const body = await res.json().catch(() => null);
    if (!res.ok || !body || body.success === false) {
        const err = new Error((body && body.error) || `Request failed (${res.status})`);
        err.status = res.status;
        throw err;
    }
    return body.data;
}

function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str ?? "";
    return div.innerHTML;
}

function formatDate(value) {
    if (!value) return "";
    return value.replace("T", " ").replace(/\.\d+Z?$/, "");
}

/* ---------------- Auth / session ---------------- */

function showLoggedIn(account) {
    els.userAccountLabel.textContent = account;
    els.userBox.hidden = false;
    els.loginPanel.hidden = true;
    els.appContent.hidden = false;
}

function showLoggedOut() {
    els.userBox.hidden = true;
    els.loginPanel.hidden = false;
    els.appContent.hidden = true;
    els.loginPassword.value = "";
}

async function checkSession() {
    try {
        const data = await apiFetch("api/session.php");
        if (data.loggedIn) {
            showLoggedIn(data.account);
            await loadItems();
        } else {
            showLoggedOut();
        }
    } catch (err) {
        showLoggedOut();
    }
}

els.loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    els.loginMessage.textContent = "";
    els.loginMessage.classList.remove("error");
    try {
        const data = await apiFetch("api/login.php", {
            method: "POST",
            body: JSON.stringify({
                account: els.loginAccount.value.trim(),
                password: els.loginPassword.value,
            }),
        });
        showLoggedIn(data.account);
        await loadItems();
    } catch (err) {
        els.loginMessage.textContent = err.message;
        els.loginMessage.classList.add("error");
    }
});

els.logoutBtn.addEventListener("click", async () => {
    try {
        await apiFetch("api/logout.php", { method: "POST" });
    } catch (err) {
        // even if the request fails, drop the client-side view back to the login screen
    }
    showLoggedOut();
});

/* ---------------- Items CRUD ---------------- */

async function loadItems() {
    els.itemsBody.innerHTML = `<tr><td colspan="13" class="empty">Loading...</td></tr>`;
    try {
        // ?all=1: the page has no pagination UI, so it needs every row, not just the
        // API's default first-20 page (see api/eeprom_config.php for the paginated form).
        const data = await apiFetch(`${API_BASE}?all=1`);
        currentItems = data.items || [];
        els.itemsTotal.textContent = currentItems.length ? `(${data.total ?? currentItems.length})` : "";
        renderTagCloud(currentItems);
        applyFilter();
    } catch (err) {
        if (err.status === 401) {
            showLoggedOut();
            return;
        }
        els.itemsBody.innerHTML = `<tr><td colspan="13" class="empty error">${escapeHtml(err.message)}</td></tr>`;
    }
}

// comment and account are searchable too: "whose row is this" and "which one
// was the production build" are the two questions this list gets asked.
const SEARCH_FIELDS = ["file_name", "file_format", "fps", "MHz", "output_format", "support_mode",
    "comment", "account"];

function applyFilter() {
    const q = els.searchBox.value.trim().toLowerCase();
    if (!q) {
        renderItems(currentItems);
        return;
    }
    const filtered = currentItems.filter((item) =>
        SEARCH_FIELDS.some((f) => String(item[f] ?? "").toLowerCase().includes(q))
    );
    renderItems(filtered);
}

function renderItems(items) {
    if (!items || items.length === 0) {
        els.itemsBody.innerHTML = `<tr><td colspan="13" class="empty">No items match.</td></tr>`;
        return;
    }
    // The leading "#" is the row's position in the list, which stays 1..N however
    // the table is filtered or whatever has been deleted. The database key
    // (`index`) is not shown -- it is a primary key with gaps in it after a
    // delete, which reads as a mistake -- but every row action still carries it
    // in data-index, so edit/download/delete address the right record.
    els.itemsBody.innerHTML = items.map((item, position) => `
        <tr>
            <td class="col-seq">${position + 1}</td>
            <td>${escapeHtml(item.file_name)}</td>
            <td class="col-content" title="${escapeHtml(item.content_preview)}">${escapeHtml(item.content_preview)}</td>
            <td class="col-comment" title="${escapeHtml(item.comment)}">${escapeHtml(item.comment)}</td>
            <td>${escapeHtml(item.file_format)}</td>
            <td>${escapeHtml(item.fps)}</td>
            <td>${escapeHtml(item.MHz)}</td>
            <td><span class="badge badge-${item.isPGL ? "yes" : "no"}">${item.isPGL ? "Yes" : "No"}</span></td>
            <td>${escapeHtml(item.output_format)}</td>
            <td>${escapeHtml(item.support_mode)}</td>
            <td>${escapeHtml(item.account)}</td>
            <td>${formatDate(item.modify_time)}</td>
            <td class="row-actions"><div class="row-actions-inner">
                <button type="button" class="icon-btn" data-action="edit" data-index="${item.index}" aria-label="Edit" title="Edit">${ICONS.edit}</button>
                <button type="button" class="icon-btn" data-action="download" data-index="${item.index}" aria-label="Download" title="Download">${ICONS.download}</button>
                <button type="button" class="icon-btn icon-btn-danger" data-action="delete" data-index="${item.index}" aria-label="Delete" title="Delete">${ICONS.delete}</button>
            </div>
            </td>
        </tr>
    `).join("");
}

const TAG_FIELDS = ["file_format", "support_mode", "output_format", "fps"];

let tagCloud3D = null;

function renderTagCloud(items) {
    const counts = {};
    items.forEach((item) => {
        TAG_FIELDS.forEach((f) => {
            const v = item[f];
            if (!v) return;
            counts[v] = (counts[v] || 0) + 1;
        });
    });
    const tags = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 20);

    if (!tagCloud3D) tagCloud3D = new TagCloud3D(els.tagCloud);
    tagCloud3D.setTags(tags);
}

function setFormMessage(message, isError) {
    els.formMessage.textContent = message || "";
    els.formMessage.classList.toggle("error", !!isError);
}

function resetForm() {
    els.form.reset();
    els.index.value = "";
    els.isPGL.checked = true;
    els.contentFile.value = "";
    setFormMessage("");
    updateContentHint();
}

/* ---------------- content: text for INI, hex bytes for BIN ---------------- */

// "12 40 AD 01", 16 bytes per line. The desktop tool writes its hex exactly
// like this and api/eeprom_config.php re-normalizes whatever it is sent, so a
// file uploaded here and the same file imported there give the same string --
// which is what keeps a sync from seeing a difference that is not one.
function bytesToHex(bytes) {
    const parts = [];
    for (let i = 0; i < bytes.length; i++) {
        if (i !== 0) parts.push(i % 16 === 0 ? "\r\n" : " ");
        parts.push(bytes[i].toString(16).padStart(2, "0").toUpperCase());
    }
    return parts.join("");
}

// An INI record whose content is really a hex image is the one mismatch nothing
// else catches: the API stores it, a sync copies it, and it only shows up when
// the download button writes an .ini full of "12 40 AD 01". The reverse (BIN
// holding text) is already rejected by the hex check on the server.
//
// Enough bytes to rule out a coincidence: an INI file has '=' and '[' in it, so
// it never matches at all, and one or two tokens could be anything.
function looksLikeHexImage(text) {
    const tokens = text.trim().split(/[\s,]+/).filter(Boolean);
    if (tokens.length < 4) return false;
    return tokens.every((t) => /^(0x)?[0-9a-fA-F]{1,2}$/.test(t));
}

function updateContentHint() {
    els.contentHint.textContent = els.file_format.value === "BIN"
        ? "(hex bytes, e.g. 12 40 AD 01)"
        : "";
}

/* ---------------- reading a file's own settings ----------------
 *
 * The same rules the desktop tool applies on Import File (see
 * UI/Dialogs/SqlDbDialog.cpp -- AnalyzePairs8A8D / AnalyzePairs16A8D /
 * AnalyzeBinContent / AnalyzeIniSupportMode). Both sides have to read a file
 * the same way, or the same file would land in the database as two different
 * records depending on where it was imported.
 *
 * Whatever the file does not say is left blank rather than defaulted: a guessed
 * fps that happens to be wrong is harder to notice than an empty box.
 */

// Register writes as (address, data) pairs. An INI writes them as "0x.." text;
// an address written with more than two hex digits (0x0020) means 16-bit
// addressing, which uses a different set of markers.
function scanIniPairs(text) {
    const tokens = [];
    const widths = [];
    const re = /0x([0-9a-fA-F]+)/g;
    let m;
    while ((m = re.exec(text)) !== null) {
        tokens.push(parseInt(m[1], 16));
        widths.push(m[1].length);
    }
    const pairs = [];
    let addr16 = false;
    for (let i = 0; i + 1 < tokens.length; i += 2) {
        pairs.push([tokens[i], tokens[i + 1]]);
        if (widths[i] > 2) addr16 = true;
    }
    return { pairs, addr16 };
}

// A BIN image is the same pairs as raw bytes.
function scanBinPairs(bytes) {
    const pairs = [];
    for (let i = 0; i + 1 < bytes.length; i += 2) pairs.push([bytes[i], bytes[i + 1]]);
    return pairs;
}

// Markers, by addressing mode. 8-bit addressing is what a BIN image and a plain
// INI use; 16-bit is the 0x0230/0x0020/0x0200/0x01F0 set.
const MARKERS_8BIT  = { pgl: [0x30, 0x19], fps25: [0x20, 0xDE], fps30: [0x20, 0x39],
                        yuvA: [0x00, 0x00], yuvB: [0xF0, 0x93] };
const MARKERS_16BIT = { pgl: [0x0230, 0x19], fps25: [0x0020, 0xDE], fps30: [0x0020, 0x39],
                        yuvA: [0x0200, 0x00], yuvB: [0x01F0, 0x93] };

// Returns only what the pairs actually say: { fps, isPGL, output_format }, each
// key absent when no marker matched.
function analyzePairs(pairs, markers) {
    const found = {};
    if (!pairs.length) return found;

    const has = ([a, d]) => pairs.some((p) => p[0] === a && p[1] === d);

    if (has(markers.pgl)) found.isPGL = 1;
    else found.isPGL = 0;            // pairs were readable and the marker is not there

    if (has(markers.fps25)) found.fps = "25";
    else if (has(markers.fps30)) found.fps = "30";

    found.output_format = (has(markers.yuvA) && has(markers.yuvB)) ? "YUV422" : "AHD";
    return found;
}

// An INI that carries both sections describes a device driven as a slave.
function analyzeIniSupportMode(text) {
    const lower = text.toLowerCase();
    return (lower.includes("[sensortype]") && lower.includes("[ini_register]"))
        ? "Slave" : "Master";
}

// Fills a field only if the analysis produced a value; otherwise clears it, so
// what is left blank is visibly the tool's "I could not tell".
function applyAnalysis(found) {
    const filled = [];
    const blank = [];

    const set = (field, value) => {
        els[field].value = value ?? "";
        (value ? filled : blank).push(field);
    };
    set("fps", found.fps);
    set("output_format", found.output_format);
    set("support_mode", found.support_mode);

    // isPGL is a checkbox: it cannot be blank, so an unreadable file leaves it
    // unticked and that is worth saying out loud.
    els.isPGL.checked = found.isPGL === 1;
    if (found.isPGL === undefined) blank.push("isPGL (left unticked)");

    // Nothing in either file format states the clock.
    blank.push("MHz");
    return { filled, blank };
}

async function loadContentFromFile(file) {
    const isBin = /\.bin$/i.test(file.name);
    try {
        const found = {};

        if (isBin) {
            const bytes = new Uint8Array(await file.arrayBuffer());
            els.content.value = bytesToHex(bytes);
            els.file_format.value = "BIN";
            Object.assign(found, analyzePairs(scanBinPairs(bytes), MARKERS_8BIT));
            // A BIN image is the whole EEPROM of a single device.
            found.support_mode = "Master";
        } else {
            const text = await file.text();
            els.content.value = text;
            els.file_format.value = "INI";
            const { pairs, addr16 } = scanIniPairs(text);
            Object.assign(found, analyzePairs(pairs, addr16 ? MARKERS_16BIT : MARKERS_8BIT));
            found.support_mode = analyzeIniSupportMode(text);
        }

        els.file_name.value = file.name;
        const { filled, blank } = applyAnalysis(found);
        updateContentHint();

        let message = `Loaded ${file.name} (${file.size} bytes).`;
        if (filled.length) message += ` Read from the file: ${filled.join(", ")}.`;
        if (blank.length) message += ` Please fill in: ${blank.join(", ")}.`;
        setFormMessage(message);
    } catch (err) {
        setFormMessage(`Cannot read ${file.name}: ${err.message}`, true);
    }
}

function fillForm(item) {
    els.index.value = item.index;
    TEXT_FIELDS.forEach((f) => { els[f].value = item[f] ?? ""; });
    els.isPGL.checked = !!item.isPGL;
    els.ext_int1.value = item.ext_int1 ?? "";
    updateContentHint();
}

function readForm() {
    const payload = {};
    TEXT_FIELDS.forEach((f) => { payload[f] = els[f].value.trim(); });
    payload.isPGL = els.isPGL.checked;
    payload.ext_int1 = els.ext_int1.value === "" ? null : Number(els.ext_int1.value);
    return payload;
}

function openAddDialog() {
    resetForm();
    els.formTitle.textContent = "Add Item";
    els.submitBtn.textContent = "Save";
    els.itemDialog.showModal();
}

async function openEditDialog(index) {
    try {
        const item = await apiFetch(`${API_BASE}?index=${encodeURIComponent(index)}`);
        resetForm();
        fillForm(item);
        els.formTitle.textContent = `Edit Item #${item.index}`;
        els.submitBtn.textContent = "Update";
        els.itemDialog.showModal();
    } catch (err) {
        alert(err.message);
    }
}

async function deleteItem(index) {
    if (!confirm(`Delete item #${index}?`)) return;
    try {
        // POST + _method=DELETE instead of a real HTTP DELETE -- see the comment in
        // api/eeprom_config.php for why (some hosts block/mangle DELETE before PHP sees it).
        await apiFetch(`${API_BASE}?index=${encodeURIComponent(index)}&_method=DELETE`, { method: "POST" });
        showToast("Item deleted.");
        await loadItems();
    } catch (err) {
        alert(err.message);
    }
}

// The server turns a BIN row's hex back into bytes before sending it, so what
// lands on disk is the image, not the hex. See downloadRecord() in the API.
async function downloadItem(index) {
    const item = currentItems.find((i) => String(i.index) === String(index));
    const suggestedName = (item && item.file_name) || `eeprom_config_${index}.txt`;
    try {
        const res = await fetch(`${API_BASE}?index=${encodeURIComponent(index)}&download=1`, {
            credentials: "same-origin",
            headers: { "X-API-Key": API_KEY },
        });
        if (!res.ok) {
            const body = await res.json().catch(() => null);
            throw new Error((body && body.error) || `Download failed (${res.status})`);
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = suggestedName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        alert(err.message);
    }
}

els.contentFile.addEventListener("change", (e) => {
    const file = e.target.files && e.target.files[0];
    if (file) loadContentFromFile(file);
});
els.file_format.addEventListener("change", updateContentHint);

els.addItemBtn.addEventListener("click", openAddDialog);
els.cancelEditBtn.addEventListener("click", () => els.itemDialog.close());

els.form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = readForm();
    const index = els.index.value;

    if (payload.file_format === "INI" && looksLikeHexImage(payload.content)) {
        const proceed = confirm(
            "File Format is INI, but the content is hex bytes -- that is what a BIN " +
            "record holds.\n\nNothing will complain later: downloading this record " +
            "would give an .ini file full of \"12 40 AD 01\" instead of a config file." +
            "\n\nSave it as INI anyway?"
        );
        if (!proceed) return;
    }

    try {
        if (index) {
            // POST + _method=PUT instead of a real HTTP PUT -- see the comment in
            // api/eeprom_config.php for why (some hosts block/mangle PUT before PHP sees it).
            await apiFetch(`${API_BASE}?index=${encodeURIComponent(index)}&_method=PUT`, {
                method: "POST",
                body: JSON.stringify(payload),
            });
            showToast("Item updated.");
        } else {
            await apiFetch(API_BASE, {
                method: "POST",
                body: JSON.stringify(payload),
            });
            showToast("Item created.");
        }
        els.itemDialog.close();
        await loadItems();
    } catch (err) {
        setFormMessage(err.message, true);
    }
});

els.refreshBtn.addEventListener("click", loadItems);
els.searchBox.addEventListener("input", applyFilter);

els.tagCloud.addEventListener("click", (e) => {
    const btn = e.target.closest(".tag-pill");
    if (!btn) return;
    els.searchBox.value = btn.dataset.value;
    applyFilter();
    els.searchBox.focus();
});

els.itemsBody.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-action]");
    if (!btn) return;
    const index = btn.dataset.index;
    if (btn.dataset.action === "edit") openEditDialog(index);
    if (btn.dataset.action === "delete") deleteItem(index);
    if (btn.dataset.action === "download") downloadItem(index);
});

async function loadApiKey() {
    const res = await fetch("api/public_key.php");
    const body = await res.json().catch(() => null);
    if (!res.ok || !body || body.success === false) {
        throw new Error((body && body.error) || `Failed to load API key (${res.status})`);
    }
    API_KEY = body.data.apiKey;
}

async function init() {
    document.getElementById("year").textContent = new Date().getFullYear();
    try {
        await loadApiKey();
        await checkSession();
    } catch (err) {
        showLoggedOut();
        els.loginMessage.textContent = `Failed to initialize: ${err.message}`;
        els.loginMessage.classList.add("error");
    }
}

init();
