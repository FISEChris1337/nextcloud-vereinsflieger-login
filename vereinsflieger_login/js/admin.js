/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    "use strict";
    const translate = (text, parameters = []) =>
        OC.L10N.translate("vereinsflieger_login", text, parameters);
    const init = () => {
        const root = document.getElementById("vf-admin");
        if (!root) return;
        const state = JSON.parse(root.dataset.state);
        const form = document.getElementById("vf-settings-form");
        const message = document.getElementById("vf-message");
        const checkResult = document.getElementById("vf-check-result");
        const links = document.getElementById("vf-links");
        const roles = document.getElementById("vf-role-map");
        const pauses = document.getElementById("vf-pauses");
        const renderPauses = (items) => {
            if (!pauses) return;
            pauses.replaceChildren();
            if (items.length === 0) {
                const empty = document.createElement("p");
                empty.className = "vf-empty";
                empty.textContent = translate("No active login pauses.");
                pauses.append(empty);
            }
            const labels = {
                failures: translate("Account failure limit"),
                pair_attempts: translate("Account attempt limit"),
                ip_failures: translate("IP failure limit"),
                ip_attempts: translate("IP attempt limit"),
            };
            items.forEach((pause) => {
                const row = document.createElement("div");
                row.className = "vf-pause-row";
                const label = document.createElement("span");
                label.textContent = (pause.username || translate("All login names")) + " · " + pause.ip + " · " + labels[pause.kind];
                const remaining = document.createElement("span");
                remaining.dataset.pauseUntil = String(Date.now() + pause.seconds * 1000);
                remaining.setAttribute("aria-live", "off");
                const remove = document.createElement("button");
                remove.type = "button";
                remove.textContent = translate("Remove login pause");
                remove.addEventListener("click", () => request(state.pauseUrl, { identifier: pause.id }, null, (data) => renderPauses(data.pauses)));
                row.append(label, remaining, remove);
                pauses.append(row);
            });
            updatePauseTimes();
        };
        const updatePauseTimes = () => {
            root.querySelectorAll("[data-pause-until]").forEach((element) => {
                const seconds = Math.max(0, Math.ceil((Number(element.dataset.pauseUntil) - Date.now()) / 1000));
                const time = String(Math.floor(seconds / 60)).padStart(2, "0") + ":" + String(seconds % 60).padStart(2, "0");
                element.textContent = seconds > 0 ? translate("Remaining: %s", [time]) : translate("Expired");
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
                    "%s (previous mapping, not verified yet)",
                    [value.role],
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
                    ? translate("%s (target group missing)", [gid])
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
            .addEventListener("click", () => addLink());
        document
            .getElementById("vf-add-role")
            .addEventListener("click", () => addRole());
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
                if (onSuccess) onSuccess(data);
                if (result) {
                    result.dataset.state = "success";
                    result.textContent = translate("OK: %s", [data.message]);
                }
                return true;
            } catch (error) {
                message.textContent = error.message;
                if (result) {
                    result.dataset.state = "error";
                    result.textContent = translate("Error: %s", [
                        error.message,
                    ]);
                }
                return false;
            } finally {
                buttons.forEach((b) => {
                    b.disabled = false;
                });
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
        renderPauses(state.pauses || []);
        window.setInterval(updatePauseTimes, 1000);
    };
    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", init);
    else init();
})();
