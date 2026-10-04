import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const MAX_HTML_BYTES = 2_000_000;
const MAX_PNG_HEIGHT = 12_000;
const MAX_PNG_PIXELS = 8_000_000;
const TIMEOUT_MS = 45_000;
let failureStage = 'request';
let cleanupFailureStage;
let sessionDeletionFailed = false;
let rendererTimedOut = false;

function fail(code = 1) {
    if (code === 42 && cleanupFailureStage === undefined) {
        process.stderr.write('PNG_DOCUMENT_TOO_TALL');
        process.exitCode = 42;

        return;
    }

    process.stderr.write(`EXPORT_RENDERER_FAILED:${failureStage}`);

    const secondaryCleanupStage = cleanupFailureStage ?? (sessionDeletionFailed ? 'webdriver_session_cleanup_failure' : undefined);

    if (secondaryCleanupStage !== undefined) {
        process.stderr.write(`\nEXPORT_RENDERER_CLEANUP_FAILED:${secondaryCleanupStage}`);
    }

    process.exitCode = 1;
}

async function reservePort() {
    const server = net.createServer();
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });

    return { port: server.address().port, server };
}

function driverHasExited(driver) {
    return driver.exitCode !== null || driver.signalCode !== null;
}

function signalRendererProcessGroup(driver, signal) {
    if (!driver?.pid) {
        return true;
    }

    try {
        process.kill(-driver.pid, signal);

        return true;
    } catch (error) {
        return error?.code === 'ESRCH';
    }
}

async function rendererProcessGroupExists(driver) {
    if (!driver?.pid) {
        return false;
    }

    try {
        process.kill(-driver.pid, 0);

        return true;
    } catch (error) {
        return error?.code !== 'ESRCH';
    }
}

async function waitForRendererProcessGroupExit(driver, timeoutMs) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        if (! await rendererProcessGroupExists(driver)) {
            return true;
        }

        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    return ! await rendererProcessGroupExists(driver);
}

async function terminateRendererProcessGroup(driver) {
    if (! driver?.pid) {
        return true;
    }

    if (! signalRendererProcessGroup(driver, 'SIGTERM')) {
        return false;
    }

    if (await waitForRendererProcessGroupExit(driver, 2_000)) {
        return true;
    }

    if (! signalRendererProcessGroup(driver, 'SIGKILL')) {
        return false;
    }

    return waitForRendererProcessGroupExit(driver, 2_000);
}

async function closeReservation(server) {
    if (! server.listening) {
        return true;
    }

    return new Promise((resolve) => {
        let completed = false;
        const finish = (succeeded) => {
            if (completed) {
                return;
            }

            completed = true;
            clearTimeout(timer);
            resolve(succeeded);
        };
        const timer = setTimeout(() => finish(false), 500);

        try {
            server.close((error) => finish(error === undefined));
        } catch {
            finish(false);
        }
    });
}

async function waitForWebDriver(port, driver, launchFailed) {
    const deadline = Date.now() + 12_000;
    let responseStatus;

    while (Date.now() < deadline && ! launchFailed() && ! driverHasExited(driver)) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}/status`, {
                signal: AbortSignal.timeout(500),
            });

            if (response.ok) {
                return;
            }
            responseStatus = response.status;

            await new Promise((resolve) => setTimeout(resolve, 100));
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
    }

    if (launchFailed()) {
        failureStage = 'webdriver_launch_failure';
    } else if (driver.exitCode !== null) {
        failureStage = `webdriver_exit_${driver.exitCode}`;
    } else if (driver.signalCode !== null) {
        const signal = ['SIGTERM', 'SIGKILL', 'SIGHUP', 'SIGABRT'].includes(driver.signalCode)
            ? driver.signalCode.toLowerCase()
            : 'signal';
        failureStage = `webdriver_exit_${signal}`;
    } else {
        failureStage = `webdriver_status_timeout${responseStatus ? `_${responseStatus}` : ''}`;
    }

    throw new Error('WebDriver unavailable');
}

async function command(socket, id, method, params = {}) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('BiDi command timeout')), 15_000);
        const onMessage = (event) => {
            let message;

            try {
                message = JSON.parse(event.data);
            } catch {
                clearTimeout(timeout);
                socket.removeEventListener('message', onMessage);
                reject(new Error('BiDi protocol response invalid'));

                return;
            }

            if (message.id !== id) {
                return;
            }

            clearTimeout(timeout);
            socket.removeEventListener('message', onMessage);

            if (message.type === 'error') {
                reject(new Error('BiDi command failed'));
            } else {
                resolve(message.result);
            }
        };

        socket.addEventListener('message', onMessage);
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function closeSession(port, sessionId) {
    if (!sessionId) {
        return true;
    }

    try {
        const response = await fetch(`http://127.0.0.1:${port}/session/${sessionId}`, {
            method: 'DELETE',
            signal: AbortSignal.timeout(2_000),
        });

        return response.ok;
    } catch {
        return false;
    }
}

async function main() {
    let input = '';

    for await (const chunk of process.stdin) {
        input += chunk;

        if (Buffer.byteLength(input) > MAX_HTML_BYTES + 128) {
            throw new Error('Input too large');
        }
    }

    const request = JSON.parse(input);

    if (!['pdf', 'png'].includes(request.format) || typeof request.html !== 'string' || typeof request.geckodriver !== 'string' || typeof request.firefox !== 'string') {
        throw new Error('Invalid render request');
    }

    if (request.geckodriver.startsWith('/snap/') || request.firefox.startsWith('/snap/')) {
        failureStage = 'unsupported_snap_executable';
        throw new Error('Standalone browser executables are required');
    }

    if (Buffer.byteLength(request.html) > MAX_HTML_BYTES) {
        throw new Error('Input too large');
    }

    if (typeof globalThis.WebSocket !== 'function') {
        failureStage = 'node_websocket_api_unavailable';
        throw new Error('Node.js WebSocket support is required');
    }

    let root;
    let driver;
    let driverPort;
    let sessionId;
    let socket;
    let timeout;
    let driverLaunchFailed = false;
    let documentTooTall = false;
    const reservations = [];

    try {
        root = await mkdtemp(path.join(os.tmpdir(), 'fictional-internet-export-'));
        failureStage = 'browser_startup';
        const driverReservation = await reservePort();
        reservations.push(driverReservation.server);
        const bidiReservation = await reservePort();
        reservations.push(bidiReservation.server);
        driverPort = driverReservation.port;
        const bidiPort = bidiReservation.port;
        const reservationsClosed = await Promise.all(reservations.map(closeReservation));
        if (reservationsClosed.some((closed) => !closed)) {
            failureStage = 'port_reservation_cleanup_failure';
            throw new Error('Renderer port reservation cleanup failed');
        }
        reservations.length = 0;
        driver = spawn(request.geckodriver, [
            '--host', '127.0.0.1',
            '--allow-hosts', '127.0.0.1',
            '--port', String(driverPort),
            '--websocket-port', String(bidiPort),
            '--log', 'fatal',
            '--profile-root', root,
        ], {
            detached: true,
            stdio: ['ignore', 'ignore', 'ignore'],
            env: {
                PATH: process.env.PATH ?? '/usr/local/bin:/usr/bin:/bin',
                HOME: root,
                TMPDIR: os.tmpdir(),
                MOZ_HEADLESS: '1',
            },
        });
        driver.on('error', () => {
            driverLaunchFailed = true;
        });
        timeout = setTimeout(() => {
            rendererTimedOut = true;
            signalRendererProcessGroup(driver, 'SIGTERM');
        }, TIMEOUT_MS);
        failureStage = 'webdriver_status';
        await waitForWebDriver(driverPort, driver, () => driverLaunchFailed);

        failureStage = 'webdriver_session_request';
        const sessionResponse = await fetch(`http://127.0.0.1:${driverPort}/session`, {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            signal: AbortSignal.timeout(20_000),
            body: JSON.stringify({
                capabilities: {
                    alwaysMatch: {
                        browserName: 'firefox',
                        webSocketUrl: true,
                        'moz:firefoxOptions': {
                            binary: request.firefox,
                            args: ['-headless'],
                            prefs: {
                                'toolkit.telemetry.enabled': false,
                                'datareporting.healthreport.uploadEnabled': false,
                                'app.normandy.enabled': false,
                                'app.update.enabled': false,
                                'services.settings.server': 'http://127.0.0.1:9/v1',
                                'network.proxy.type': 1,
                                'network.proxy.http': '127.0.0.1',
                                'network.proxy.http_port': 9,
                                'network.proxy.ssl': '127.0.0.1',
                                'network.proxy.ssl_port': 9,
                                'network.proxy.no_proxies_on': '127.0.0.1,localhost',
                            },
                        },
                    },
                },
            }),
        });
        failureStage = 'webdriver_session';
        const session = await sessionResponse.json();

        if (!sessionResponse.ok || !session?.value?.sessionId || !session?.value?.capabilities?.webSocketUrl) {
            throw new Error('Browser session unavailable');
        }

        sessionId = session.value.sessionId;
        failureStage = 'bidi_connection';
        socket = new WebSocket(session.value.capabilities.webSocketUrl);
        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('BiDi connection timeout')), 10_000);
            socket.addEventListener('open', () => {
                clearTimeout(timer);
                resolve();
            }, { once: true });
            socket.addEventListener('error', () => {
                clearTimeout(timer);
                reject(new Error('BiDi connection failed'));
            }, { once: true });
        });

        failureStage = 'document_navigation';
        const tab = await command(socket, 1, 'browsingContext.create', { type: 'tab' });
        const context = tab.context;
        await command(socket, 2, 'browsingContext.setViewport', {
            context,
            viewport: { width: 972, height: 768 },
            devicePixelRatio: 1,
        });
        await command(socket, 3, 'browsingContext.navigate', {
            context,
            url: `data:text/html;charset=utf-8;base64,${Buffer.from(request.html, 'utf8').toString('base64')}`,
            wait: 'complete',
        });

        let rendered;

        if (request.format === 'pdf') {
            failureStage = 'pdf_render';
            rendered = await command(socket, 4, 'browsingContext.print', {
                context,
                background: true,
                orientation: 'portrait',
                page: { width: 8.27, height: 11.69 },
                margin: { top: 0.5, bottom: 0.5, left: 0.55, right: 0.55 },
                shrinkToFit: true,
            });
            rendered = Buffer.from(rendered.data, 'base64');
        } else {
            failureStage = 'png_dimensions';
            const dimensionsResult = await command(socket, 4, 'script.evaluate', {
                expression: 'JSON.stringify({width: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth), height: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)})',
                target: { context },
                awaitPromise: true,
                resultOwnership: 'none',
            });
            const dimensions = JSON.parse(dimensionsResult.result.value);

            if (dimensions.height > MAX_PNG_HEIGHT || dimensions.width * dimensions.height > MAX_PNG_PIXELS) {
                documentTooTall = true;
            } else {
                failureStage = 'png_capture';
                rendered = await command(socket, 5, 'browsingContext.captureScreenshot', {
                    context,
                    origin: 'document',
                    format: { type: 'image/png' },
                });
                rendered = Buffer.from(rendered.data, 'base64');
            }
        }

        if (!documentTooTall && rendered.length < 8) {
            throw new Error('Renderer returned empty data');
        }

        if (!documentTooTall) {
            process.stdout.write(rendered);
        }
    } finally {
        clearTimeout(timeout);
        if (! await closeSession(driverPort, sessionId)) {
            sessionDeletionFailed = true;
        }

        try {
            if (socket && socket.readyState !== WebSocket.CLOSED) {
                socket.close();
            }
        } catch {
            cleanupFailureStage ??= 'bidi_socket_cleanup_failure';
        }

        for (const server of reservations) {
            if (! await closeReservation(server)) {
                cleanupFailureStage ??= 'port_reservation_cleanup_failure';
            }
        }

        const browserProcessGroupStopped = !driver || await terminateRendererProcessGroup(driver);

        if (!browserProcessGroupStopped) {
            cleanupFailureStage ??= 'browser_process_cleanup_failure';
        }

        if (root) {
            if (browserProcessGroupStopped) {
                try {
                    await rm(root, { recursive: true, force: true });
                } catch {
                    cleanupFailureStage ??= 'temporary_profile_cleanup_failure';
                }
            } else {
                cleanupFailureStage ??= 'temporary_profile_retained_for_active_browser';
            }
        }
    }

    if (rendererTimedOut) {
        failureStage = 'renderer_timeout';
        throw new Error('Renderer timed out');
    }

    if (cleanupFailureStage !== undefined) {
        failureStage = 'renderer_cleanup_failure';
        throw new Error('Renderer cleanup failed');
    }

    if (sessionDeletionFailed) {
        process.stderr.write('EXPORT_RENDERER_CLEANUP_WARNING:webdriver_session_cleanup_failure');
    }

    if (documentTooTall) {
        fail(42);
    }
}

main().catch(() => {
    if (rendererTimedOut) {
        failureStage = 'renderer_timeout';
    }

    fail();
});
