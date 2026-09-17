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

const TEXT_FIELDS = ["file_format", "fps", "file_name", "MHz", "output_format", "support_mode", "content",
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
    els.itemsBody.innerHTML = `<tr><td colspan="10" class="empty">Loading...</td></tr>`;
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
        els.itemsBody.innerHTML = `<tr><td colspan="10" class="empty error">${escapeHtml(err.message)}</td></tr>`;
    }
}

const SEARCH_FIELDS = ["file_name", "file_format", "fps", "MHz", "output_format", "support_mode"];

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
        els.itemsBody.innerHTML = `<tr><td colspan="10" class="empty">No items match.</td></tr>`;
        return;
    }
    els.itemsBody.innerHTML = items.map((item) => `
        <tr>
            <td>${item.index}</td>
            <td>${escapeHtml(item.file_name)}</td>
            <td>${escapeHtml(item.file_format)}</td>
            <td>${escapeHtml(item.fps)}</td>
            <td>${escapeHtml(item.MHz)}</td>
            <td><span class="badge badge-${item.isPGL ? "yes" : "no"}">${item.isPGL ? "Yes" : "No"}</span></td>
            <td>${escapeHtml(item.output_format)}</td>
            <td>${escapeHtml(item.support_mode)}</td>
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
    setFormMessage("");
}

function fillForm(item) {
    els.index.value = item.index;
    TEXT_FIELDS.forEach((f) => { els[f].value = item[f] ?? ""; });
    els.isPGL.checked = !!item.isPGL;
    els.ext_int1.value = item.ext_int1 ?? "";
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

els.addItemBtn.addEventListener("click", openAddDialog);
els.cancelEditBtn.addEventListener("click", () => els.itemDialog.close());

els.form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const payload = readForm();
    const index = els.index.value;

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
