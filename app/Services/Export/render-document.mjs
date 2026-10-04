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

function fail(code = 1) {
    process.stderr.write(code === 42 ? 'PNG_DOCUMENT_TOO_TALL' : `EXPORT_RENDERER_FAILED:${failureStage}`);
    process.exitCode = code;
}

async function reservePort() {
    const server = net.createServer();
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });

    return { port: server.address().port, server };
}

async function waitForWebDriver(port, driver) {
    const deadline = Date.now() + 12_000;
    let responseStatus;

    while (Date.now() < deadline && driver.exitCode === null) {
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

    failureStage = driver.exitCode === null
        ? `webdriver_status_timeout${responseStatus ? `_${responseStatus}` : ''}`
        : `webdriver_exit_${driver.exitCode}`;
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
        return;
    }

    try {
        await fetch(`http://127.0.0.1:${port}/session/${sessionId}`, {
            method: 'DELETE',
            signal: AbortSignal.timeout(2_000),
        });
    } catch {
        // Process cleanup below still terminates the isolated renderer.
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
        await Promise.all(reservations.map((server) => new Promise((resolve) => server.close(resolve))));
        reservations.length = 0;
        driver = spawn(request.geckodriver, [
            '--host', '127.0.0.1',
            '--allow-hosts', '127.0.0.1',
            '--port', String(driverPort),
            '--websocket-port', String(bidiPort),
            '--log', 'fatal',
            '--profile-root', root,
        ], {
            stdio: ['ignore', 'ignore', 'ignore'],
            env: {
                PATH: process.env.PATH ?? '/usr/local/bin:/usr/bin:/bin',
                HOME: root,
                TMPDIR: os.tmpdir(),
                MOZ_HEADLESS: '1',
            },
        });
        timeout = setTimeout(() => driver.kill('SIGKILL'), TIMEOUT_MS);
        failureStage = 'webdriver_status';
        await waitForWebDriver(driverPort, driver);

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
                fail(42);

                return;
            }

            failureStage = 'png_capture';
            rendered = await command(socket, 5, 'browsingContext.captureScreenshot', {
                context,
                origin: 'document',
                format: { type: 'image/png' },
            });
            rendered = Buffer.from(rendered.data, 'base64');
        }

        if (rendered.length < 8) {
            throw new Error('Renderer returned empty data');
        }

        process.stdout.write(rendered);
    } finally {
        clearTimeout(timeout);
        await closeSession(driverPort, sessionId);

        if (socket?.readyState === WebSocket.OPEN) {
            socket.close();
        }

        for (const server of reservations) {
            if (server.listening) {
                await new Promise((resolve) => server.close(resolve));
            }
        }

        if (driver && driver.exitCode === null && driver.signalCode === null) {
            await new Promise((resolve) => {
                const finish = () => {
                    clearTimeout(killTimeout);
                    resolve();
                };
                const killTimeout = setTimeout(() => {
                    driver.kill('SIGKILL');

                    if (driver.exitCode !== null || driver.signalCode !== null) {
                        finish();
                    }
                }, 2_000);

                driver.once('exit', finish);
                driver.kill('SIGTERM');
            });
        }

        if (root) {
            await rm(root, { recursive: true, force: true });
        }
    }
}

main().catch(() => fail());
