/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    "use strict";
    const translate = (text, parameters = {}) =>
        // Every translated value is assigned through textContent or a plain-text attribute.
        OC.L10N.translate("vereinsflieger_login", text, parameters, undefined, { escape: false, sanitize: false });
    const init = () => {
        const root = document.getElementById("vf-admin");
        if (!root) return;
        const state = JSON.parse(root.dataset.state);
        const form = document.getElementById("vf-settings-form");
        const message = document.getElementById("vf-message");
        const checkResult = document.getElementById("vf-check-result");
        const links = document.getElementById("vf-links");
        const roles = document.getElementById("vf-role-map");
        const pagers = [];
        const displayTime = (value) => {
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(
                document.documentElement.lang || undefined,
                { dateStyle: "medium", timeStyle: "short" },
            ).format(date);
        };
        const setupList = (container) => {
            const kind = container.dataset.vfList;
            let currentPage = 1, hasMore = false, busy = false, controller;
            const toolbar = document.createElement("div");
            toolbar.className = "vf-list-toolbar";
            const filter = document.createElement("input");
            filter.type = "search";
            filter.maxLength = 128;
            filter.placeholder = kind === "pauses" ? translate("Search login name or IP") : translate("Search account");
            filter.setAttribute("aria-label", filter.placeholder);
            // List filtering must not mark configuration as changed or submit its form.
            filter.addEventListener("input", (event) => event.stopPropagation());
            const search = document.createElement("button");
            search.type = "button";
            search.textContent = translate("Search");
            toolbar.append(filter, search);
            const rows = document.createElement("div");
            rows.className = "vf-list-viewport";
            rows.tabIndex = 0;
            rows.setAttribute("role", "region");
            rows.setAttribute("aria-label", {
                identities: translate("Automatically saved identities"),
                snapshots: translate("Last verified roles"),
                warnings: translate("Group synchronization warnings"),
                pauses: translate("Active login pauses"),
            }[kind]);
            const navigation = document.createElement("div");
            navigation.className = "vf-list-navigation";
            const previous = document.createElement("button"), next = document.createElement("button");
            previous.type = next.type = "button";
            previous.textContent = translate("Previous");
            next.textContent = translate("Next");
            const status = document.createElement("span");
            status.setAttribute("role", "status");
            status.setAttribute("aria-live", "polite");
            navigation.append(previous, status, next);
            container.append(toolbar, rows, navigation);
            const syncControls = () => {
                previous.disabled = busy || currentPage === 1;
                next.disabled = busy || !hasMore;
            };
            const render = (items) => {
                rows.replaceChildren();
                if (items.length === 0) {
                    const empty = document.createElement("p");
                    empty.className = "vf-empty";
                    empty.textContent = translate("No entries found.");
                    rows.append(empty);
                }
                const labels = {
                    failures: translate("Account failure limit"), pair_attempts: translate("Account attempt limit"),
                    ip_failures: translate("IP failure limit"), ip_attempts: translate("IP attempt limit"),
                };
                items.forEach((item) => {
                    const row = document.createElement("div");
                    if (kind === "pauses") {
                        row.className = "vf-pause-row";
                        const label = document.createElement("span"), remaining = document.createElement("span");
                        label.textContent = (item.username || translate("All login names")) + " · " + item.ip + " · " + (labels[item.kind] || item.kind);
                        remaining.dataset.pauseUntil = String(Date.now() + item.seconds * 1000);
                        const remove = document.createElement("button");
                        remove.type = "button";
                        remove.textContent = translate("Remove login pause");
                        remove.addEventListener("click", () => request(state.pauseUrl, { identifier: item.id }, null, () => load(currentPage)));
                        row.append(label, remaining, remove);
                    } else if (kind === "identities") {
                        row.className = "vf-identity-row";
                        row.textContent = "VF " + item.vfUid + " → " + item.uid + (item.blocked ? " · " + translate("blocked") : "");
                    } else {
                        row.className = "vf-snapshot";
                        const name = document.createElement("strong"), time = document.createElement("time");
                        name.textContent = item.uid;
                        time.dateTime = item.capturedAt;
                        time.textContent = displayTime(item.capturedAt);
                        const names = document.createElement("div");
                        if (kind === "warnings") {
                            row.classList.add("vf-mapping-warning");
                            names.textContent = translate("Missing target groups: {groups}", { groups: item.names.join(", ") });
                        } else item.names.forEach((role) => {
                            const chip = document.createElement("span");
                            chip.className = "vf-role-chip";
                            chip.textContent = role;
                            names.append(chip);
                        });
                        row.append(name, time, names);
                    }
                    rows.append(row);
                });
                rows.scrollTop = 0;
                updatePauseTimes();
            };
            const load = async (page) => {
                if (controller) controller.abort();
                const active = controller = new AbortController();
                busy = true;
                rows.setAttribute("aria-busy", "true");
                status.textContent = translate("Loading entries …");
                syncControls();
                try {
                    const response = await fetch(state.listUrl, {
                        method: "POST", credentials: "same-origin", signal: active.signal,
                        headers: { "Content-Type": "application/json", requesttoken: OC.requestToken },
                        body: JSON.stringify({ kind, page, search: filter.value }),
                    });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || translate("The list could not be loaded."));
                    if (active !== controller) return;
                    if (!Array.isArray(data.items) || data.items.length > 25 || !Number.isInteger(data.page) || typeof data.hasMore !== "boolean") throw new Error(translate("The list could not be loaded."));
                    if (data.items.length === 0 && page > 1 && !data.hasMore) return load(page - 1);
                    currentPage = data.page;
                    hasMore = data.hasMore;
                    render(data.items);
                    status.textContent = translate("Page {page} · {count} entries", { page: String(currentPage), count: String(data.items.length) });
                } catch (error) {
                    if (active !== controller || error.name === "AbortError") return;
                    status.textContent = translate("The list could not be loaded.");
                    // Keep the previous page available and allow retrying the same request.
                } finally {
                    if (active === controller) {
                        busy = false;
                        rows.setAttribute("aria-busy", "false");
                        syncControls();
                    }
                }
            };
            search.addEventListener("click", () => load(1));
            filter.addEventListener("keydown", (event) => {
                if (event.key === "Enter") { event.preventDefault(); event.stopPropagation(); load(1); }
            });
            previous.addEventListener("click", () => load(Math.max(1, currentPage - 1)));
            next.addEventListener("click", () => load(currentPage + 1));
            pagers.push({ syncControls });
            load(1);
        };
        const updatePauseTimes = () => {
            root.querySelectorAll("[data-pause-until]").forEach((element) => {
                const seconds = Math.max(0, Math.ceil((Number(element.dataset.pauseUntil) - Date.now()) / 1000));
                const time = String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
                element.textContent = seconds > 0 ? translate("Remaining: {time}", { time }) : translate("Expired");
            });
        };
        const input = (label, value, numeric = false) => {
            const el = document.createElement("input");
            el.value = value;
            el.required = true;
            el.setAttribute("aria-label", label);
            if (numeric) {
                el.inputMode = "numeric";
                el.pattern = "[1-9][0-9]*";
            }
            return el;
        };
        const remove = (row) => {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = translate("Remove");
            button.addEventListener("click", () => {
                row.remove();
                message.textContent = translate("Unsaved changes.");
            });
            return button;
        };
        const addLink = (value = {}) => {
            const row = document.createElement("div");
            row.className = "vf-row";
            row.append(
                input(translate("Vereinsflieger ID"), value.vfUid || "", true),
                input(
                    translate("Nextcloud account ID"),
                    value.nextcloudUid || "",
                ),
                remove(row),
            );
            links.append(row);
        };
        const addRole = (value = {}) => {
            const row = document.createElement("div");
            row.className = "vf-row";
            const role = document.createElement("select");
            role.required = true;
            role.setAttribute("aria-label", translate("Vereinsflieger role"));
            const roleEmpty = document.createElement("option");
            roleEmpty.value = "";
            roleEmpty.textContent = translate("Choose a received role");
            role.append(roleEmpty);
            state.settings.knownRoles.forEach((name) => {
                const option = document.createElement("option");
                option.value = name;
                option.textContent = name;
                role.append(option);
            });
            if (value.role && !state.settings.knownRoles.includes(value.role)) {
                const previous = document.createElement("option");
                previous.value = value.role;
                previous.textContent = translate(
                    "{role} (previous mapping, not verified yet)",
                    { role: value.role },
                );
                role.append(previous);
            }
            role.value = value.role || "";
            const select = document.createElement("select");
            select.required = true;
            select.setAttribute("aria-label", translate("Nextcloud group"));
            const empty = document.createElement("option");
            empty.value = "";
            empty.textContent = translate("Choose a group");
            select.append(empty);
            const groups = new Set(state.groups);
            if (value.group) groups.add(value.group);
            [...groups].sort().forEach((gid) => {
                const option = document.createElement("option");
                option.value = gid;
                option.textContent = (state.missingGroups || []).includes(gid)
                    ? translate("{group} (target group missing)", { group: gid })
                    : gid;
                select.append(option);
            });
            select.value = value.group || "";
            row.append(role, select, remove(row));
            const warning = document.createElement("small");
            warning.className = "vf-mapping-warning";
            warning.textContent = translate(
                "This target group is missing. Choose an existing group or remove the mapping.",
            );
            const updateWarning = () => {
                warning.hidden = !(state.missingGroups || []).includes(
                    select.value,
                );
            };
            updateWarning();
            select.addEventListener("change", updateWarning);
            row.append(warning);
            roles.append(row);
        };
        state.settings.links.forEach(addLink);
        state.settings.roleMap.forEach(addRole);
        document
            .getElementById("vf-add-link")
            .addEventListener("click", () => { addLink(); links.lastElementChild.scrollIntoView({ block: "nearest" }); });
        document
            .getElementById("vf-add-role")
            .addEventListener("click", () => { addRole(); roles.lastElementChild.scrollIntoView({ block: "nearest" }); });
        document.getElementById("vf-add-role").disabled =
            state.settings.knownRoles.length === 0;
        form.addEventListener("input", () => {
            message.textContent = translate("Unsaved changes.");
            if (!checkResult.hidden) {
                checkResult.dataset.state = "pending";
                checkResult.textContent = translate(
                    "Inputs changed. The check uses saved settings; save changes first.",
                );
            }
        });
        const request = async (url, body, result = null, onSuccess = null) => {
            const buttons = [...root.querySelectorAll("button")];
            buttons.forEach((b) => {
                b.disabled = true;
            });
            message.textContent = result
                ? translate("Checking saved configuration locally …")
                : translate("Saving …");
            if (result) {
                result.hidden = false;
                result.dataset.state = "pending";
                result.textContent = message.textContent;
            }
            try {
                const response = await fetch(url, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        requesttoken: OC.requestToken,
                    },
                    body: JSON.stringify(body),
                });
                let data;
                try {
                    data = await response.json();
                } catch {
                    throw new Error(
                        translate(
                            "Unreadable response. Check your session and administrator access.",
                        ),
                    );
                }
                if (!response.ok)
                    throw new Error(
                        data.message ||
                            translate(
                                "Request failed. Check your session and administrator access.",
                            ),
                    );
                message.textContent = data.message;
                if (onSuccess) await onSuccess(data);
                if (result) {
                    result.dataset.state = "success";
                    result.textContent = translate("OK: {message}", { message: data.message });
                }
                return true;
            } catch (error) {
                message.textContent = error.message;
                if (result) {
                    result.dataset.state = "error";
                    result.textContent = translate("Error: {message}", { message: error.message });
                }
                return false;
            } finally {
                buttons.forEach((b) => {
                    b.disabled = false;
                });
                pagers.forEach((pager) => pager.syncControls());
                document.getElementById("vf-add-role").disabled =
                    state.settings.knownRoles.length === 0;
            }
        };
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const data = {
                cid: form.elements.cid.value,
                appkey: form.elements.appkey.value,
                dailyApiBudget: form.elements.dailyApiBudget.value,
                hourlyApiBudget: form.elements.hourlyApiBudget.value,
                loginFailureLimit: form.elements.loginFailureLimit.value,
                loginIpFailureLimit: form.elements.loginIpFailureLimit.value,
                loginPauseMinutes: form.elements.loginPauseMinutes.value,
                loginAttemptWindowMinutes:
                    form.elements.loginAttemptWindowMinutes.value,
                loginPairAttemptLimit:
                    form.elements.loginPairAttemptLimit.value,
                loginIpAttemptLimit: form.elements.loginIpAttemptLimit.value,
                providerPauseMinutes: form.elements.providerPauseMinutes.value,
                enabled: form.elements.enabled.checked,
                syncRoles: form.elements.syncRoles.checked,
                defaultLogin: form.elements.defaultLogin.checked,
                createAccounts: form.elements.createAccounts.checked,
                rememberMeDefault: form.elements.rememberMeDefault.checked,
                showLocalLoginLink: form.elements.showLocalLoginLink.checked,
                linkExistingByEmail: form.elements.linkExistingByEmail.checked,
                excludedAccounts: [
                    ...form.elements.excludedAccounts.selectedOptions,
                ].map((option) => option.value),
                links: [...links.children].map((row) => ({
                    vfUid: row.children[0].value,
                    nextcloudUid: row.children[1].value,
                })),
                roleMap: [...roles.children].map((row) => ({
                    role: row.children[0].value,
                    group: row.children[1].value,
                })),
            };
            if (await request(state.saveUrl, { settings: data })) {
                form.elements.appkey.value = "";
                window.location.reload();
            }
        });
        document
            .getElementById("vf-check")
            .addEventListener("click", () =>
                request(state.checkUrl, {}, checkResult),
            );
        root.querySelectorAll("[data-vf-list]").forEach(setupList);
        window.setInterval(updatePauseTimes, 1000);
    };
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", init);
    else init();
})();
