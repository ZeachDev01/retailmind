"use strict";

// Behavior checks for src/frontend/assets/js/remote-access-toggle.js.
// The Super Administrator Remote Access switch must start/stop the tunnel,
// reconcile after failures, and ignore stale status polls.

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "../../..");
const source = fs.readFileSync(
    path.join(root, "src/frontend/assets/js/remote-access-toggle.js"),
    "utf8"
);

function createElement(id, options = {}) {
    const classes = new Set((options.className || "").split(/\s+/).filter(Boolean));
    const element = {
        id,
        textContent: options.textContent || "",
        checked: Boolean(options.checked),
        disabled: Boolean(options.disabled),
        className: options.className || "",
        attributes: {},
        listeners: {},
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        addEventListener(type, handler) {
            this.listeners[type] = handler;
        }
    };
    element.classList = {
        add(name) {
            classes.add(name);
            element.className = [...classes].join(" ");
        },
        remove(...names) {
            names.forEach(name => classes.delete(name));
            element.className = [...classes].join(" ");
        },
        contains(name) {
            return classes.has(name);
        }
    };
    return element;
}

function createPage(config) {
    const elements = new Map();
    [
        ["remote-access-toggle", {checked: config.state !== "offline", disabled: config.state === "starting"}],
        ["remote-toggle-status-text"],
        ["remote-access-feedback"],
        ["tunnel-public-url"],
        ["remote-mode-pill"],
        ["remote-mode-label"],
        ["remote-active-provider"],
        ["remote-link-copy-value"],
        ["remote-copy-link"],
        ["remote-link-empty"],
        ["remote-link-note"],
        ["remote-mode-icon"]
    ].forEach(([id, options]) => {
        elements.set(id, createElement(id, options));
    });
    elements.set(
        "remote-access-toggle-config",
        createElement("remote-access-toggle-config", {textContent: JSON.stringify(config)})
    );
    return {
        elements,
        document: {
            querySelectorAll() { return []; },
            getElementById(id) {
                return elements.get(id) || null;
            }
        }
    };
}

function createSandbox(config) {
    const page = createPage(config);
    const calls = [];
    const responders = [];
    const timers = new Map();
    let nextTimer = 1;

    const sandbox = {
        document: page.document,
        fetch: async (url, options) => {
            calls.push({url, options});
            const responder = responders.shift();
            if (!responder) {
                throw new Error("Unexpected fetch call");
            }
            const data = typeof responder === "function" ? await responder() : responder;
            return {json: async () => data};
        },
        setInterval: fn => {
            const id = nextTimer++;
            timers.set(id, fn);
            return id;
        },
        clearInterval: id => {
            timers.delete(id);
        }
    };

    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, {filename: "remote-access-toggle.js"});

    return {
        page,
        calls,
        responders,
        fireIntervals() {
            return [...timers.values()].map(fn => fn());
        },
        pendingTimers() {
            return timers.size;
        }
    };
}

async function flush() {
    for (let index = 0; index < 20; index += 1) {
        await new Promise(resolve => setImmediate(resolve));
    }
}

function baseConfig(state = "offline") {
    return {
        apiUrl: "/retailmind/components/administrator/remote_access_api.php",
        csrfToken: "csrf-test-token",
        permanent: false,
        state
    };
}

const failures = [];
async function scenario(name, run) {
    try {
        await run();
        console.log(`PASS: ${name}`);
    } catch (error) {
        failures.push(`${name}: ${error.message}`);
        console.log(`FAIL: ${name}: ${error.message}`);
    }
}

(async () => {
    await scenario("start success renders the active tunnel and URL", async () => {
        const app = createSandbox(baseConfig());
        app.responders.push({
            success: true,
            status: "online",
            online: true,
            url: "https://hand-test.trycloudflare.com"
        });

        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = true;
        await toggle.listeners.change();
        await flush();

        assert.equal(app.calls.length, 1);
        assert.equal(app.calls[0].options.method, "POST");
        assert.equal(app.calls[0].options.headers["X-CSRF-TOKEN"], "csrf-test-token");
        assert.equal(JSON.parse(app.calls[0].options.body).action, "start");
        assert.equal(toggle.checked, true);
        assert.equal(toggle.disabled, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Active & encrypted");
        assert.equal(
            app.page.elements.get("tunnel-public-url").textContent,
            "https://hand-test.trycloudflare.com"
        );
        assert.equal(app.page.elements.get("remote-mode-label").textContent, 'Online');
        assert.equal(app.page.elements.get("remote-active-provider").textContent, 'Cloudflare Quick Tunnel');
        assert.ok(app.page.elements.get("remote-mode-pill").classList.contains("is-on"));
    });

    await scenario("failed start reconciles back to the confirmed offline state", async () => {
        const app = createSandbox(baseConfig());
        app.responders.push({success: false, error: "Failed to start tunnel daemon."});
        app.responders.push({success: true, status: "offline", online: false});

        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = true;
        await toggle.listeners.change();
        await flush();

        assert.equal(toggle.checked, false);
        assert.equal(toggle.disabled, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Connection is stopped or unavailable");
        assert.equal(
            app.page.elements.get("remote-access-feedback").textContent,
            "Failed to start tunnel daemon."
        );
        assert.match(app.page.elements.get("remote-access-feedback").className, /is-error/);
    });

    await scenario("failed start with a failed status check restores the confirmed offline state", async () => {
        const app = createSandbox(baseConfig());
        app.responders.push({success: false, error: "Failed to start tunnel daemon."});
        app.responders.push(() => {
            throw new Error("network down");
        });

        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = true;
        await toggle.listeners.change();
        await flush();

        assert.equal(toggle.checked, false);
        assert.equal(toggle.disabled, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Connection is stopped or unavailable");
        assert.equal(
            app.page.elements.get("remote-access-feedback").textContent,
            "Failed to start tunnel daemon."
        );
    });

    await scenario("failed stop with a failed status check restores the confirmed online state", async () => {
        const app = createSandbox(baseConfig("online"));
        app.responders.push({success: false, error: "Failed to stop tunnel daemon."});
        app.responders.push(() => {
            throw new Error("network down");
        });

        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = false;
        await toggle.listeners.change();
        await flush();

        assert.equal(toggle.checked, true);
        assert.equal(toggle.disabled, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Active & encrypted");
    });

    await scenario("stop success turns the switch off", async () => {
        const app = createSandbox(baseConfig("online"));
        app.responders.push({success: true, status: "offline", online: false});

        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = false;
        await toggle.listeners.change();
        await flush();

        assert.equal(JSON.parse(app.calls[0].options.body).action, "stop");
        assert.equal(toggle.checked, false);
        assert.equal(toggle.attributes["aria-checked"], "false");
        assert.equal(toggle.disabled, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Connection is stopped or unavailable");
    });

    await scenario("a stale status poll cannot overwrite a later stop", async () => {
        const app = createSandbox(baseConfig("starting"));
        let resolvePoll;
        const pendingPoll = new Promise(resolve => {
            resolvePoll = resolve;
        });
        app.responders.push(async () => {
            await pendingPoll;
            return {success: true, status: "online", online: true, url: "https://stale.trycloudflare.com"};
        });
        app.responders.push({success: true, status: "offline", online: false});

        assert.equal(app.page.elements.get("remote-access-toggle").disabled, true);

        const [poll] = app.fireIntervals();
        const toggle = app.page.elements.get("remote-access-toggle");
        toggle.checked = false;
        await toggle.listeners.change();
        await flush();

        resolvePoll();
        await poll;
        await flush();

        assert.equal(toggle.checked, false);
        assert.equal(app.page.elements.get("remote-toggle-status-text").textContent, "Connection is stopped or unavailable");
        assert.notEqual(
            app.page.elements.get("tunnel-public-url").textContent,
            "https://stale.trycloudflare.com"
        );
    });

    await scenario("overlapping poll ticks issue only one status request", async () => {
        const app = createSandbox(baseConfig("starting"));
        let resolvePoll;
        const pendingPoll = new Promise(resolve => {
            resolvePoll = resolve;
        });
        app.responders.push(async () => {
            await pendingPoll;
            return {success: true, status: "online", online: true, url: "https://single.trycloudflare.com"};
        });

        const first = app.fireIntervals();
        const second = app.fireIntervals();
        await flush();

        assert.equal(app.calls.length, 1, "second tick must not overlap the first request");

        resolvePoll();
        await Promise.all([...first, ...second]);
        await flush();

        assert.equal(app.page.elements.get("tunnel-public-url").textContent, "https://single.trycloudflare.com");
    });

    await scenario("an enabled ngrok service loses Online when disconnected and recovers", async () => {
        const app = createSandbox({...baseConfig('online'), mode: 'ngrok', configuredUrl: 'https://store.ngrok-free.dev'});
        app.responders.push({success: true, status: 'starting', running: true, online: false, url: null, mode: 'ngrok'});
        await Promise.all(app.fireIntervals());
        assert.equal(app.page.elements.get('remote-mode-label').textContent, 'Connecting');
        assert.equal(app.page.elements.get('tunnel-public-url').textContent, 'https://store.ngrok-free.dev');
        assert.equal(app.page.elements.get('remote-active-provider').textContent, 'ngrok Free');
        assert.match(app.page.elements.get('remote-link-note').textContent, /not been verified/);
        assert.equal(app.page.elements.get('remote-access-toggle').checked, true, 'The service remains enabled while reconnecting');
        app.responders.push({success: true, status: 'online', online: true, url: 'https://store.ngrok-free.dev', mode: 'ngrok'});
        await Promise.all(app.fireIntervals());
        assert.equal(app.page.elements.get('remote-mode-label').textContent, 'Online');
        assert.equal(app.page.elements.get('tunnel-public-url').textContent, 'https://store.ngrok-free.dev');
    });

    if (failures.length > 0) {
        console.log(`\nFAILURES DETECTED: ${failures.length}`);
        failures.forEach(failure => console.log(` - ${failure}`));
        process.exit(1);
    }

    console.log("\nSUCCESS: Super Administrator Remote Access toggle behavior passed.");
})();
