import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';
import { networkInterfaces } from 'os';
import { existsSync, readFileSync, unlinkSync } from 'fs';
import path from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

/**
 * Get the platform-specific hot file path for Laravel Vite plugin.
 * Use this in your vite.config.js:
 *
 * laravel({
 *     input: ['resources/css/app.css', 'resources/js/app.js'],
 *     hotFile: nativephpHotFile(),
 * })
 */
export function nativephpHotFile() {
    const isIos = process.argv.includes('--mode=ios');
    const isAndroid = process.argv.includes('--mode=android');

    if (isIos) {
        return 'public/ios-hot';
    }

    if (isAndroid) {
        return 'public/android-hot';
    }

    return 'public/hot';
}

// Get the local IP address for HMR
function getLocalIP() {
    const nets = networkInterfaces();
    for (const name of Object.keys(nets)) {
        for (const net of nets[name]) {
            // Skip over non-IPv4 and internal addresses
            if (net.family === 'IPv4' && !net.internal) {
                return net.address;
            }
        }
    }
    return 'localhost';
}

export function nativephpMobile() {
    const localIP = getLocalIP();
    const isIos = process.argv.includes('--mode=ios');
    const isAndroid = process.argv.includes('--mode=android');
    const isBuild = process.argv.includes('build');

    const config = {};

    const axiosPath = path.resolve(process.cwd(), 'node_modules/axios/lib/axios.js');

    // NativePHP Mobile needs axios as a direct dependency: we intercept axios
    // imports at bundle time and route them through the embedded PHP runtime.
    // Inertia 3 removed axios in favour of native fetch, so fresh Inertia 3
    // projects won't declare it — though it may still be in node_modules via
    // transitive deps from @inertiajs/core. Check the project's package.json
    // (not just the filesystem) so a stale transitive dep can't silently pass
    // the check and produce an app that can't talk to its own backend.
    const pkgJsonPath = path.resolve(process.cwd(), 'package.json');
    let declaresAxios = false;
    try {
        const pkg = JSON.parse(readFileSync(pkgJsonPath, 'utf8'));
        declaresAxios = !!(pkg.dependencies?.axios || pkg.devDependencies?.axios);
    } catch {
        // No package.json accessible — skip the check rather than failing
        // outside a user project (e.g. tooling that loads the plugin directly).
    }

    if (!declaresAxios || !existsSync(axiosPath)) {
        throw new Error(
            '[nativephp/mobile] axios must be declared as a dependency in your package.json.\n'
            + 'Inertia 3 removed axios in favour of native fetch; reinstall it so outgoing '
            + 'requests can be routed through our handler:\n'
            + '  npm install axios\n'
            + 'Then follow https://inertiajs.com/docs/v3/installation/client-side-setup#using-axios '
            + 'to tell Inertia to use axios for its client-side requests.'
        );
    }

    if (isIos) {
        // Force the correct URL for iOS
        process.env.APP_URL = 'php://127.0.0.1';

        // Set the base path for iOS builds (only in production)
        if (isBuild) {
            config.base = '/_assets/build/';
        }

        config.server = {
            host: localIP,
            cors: {
                origin: ['php://127.0.0.1'],
            },
        };

        // Prevent Vite from pre-bundling axios so our plugin can intercept it
        config.optimizeDeps = {
            exclude: ['axios']
        };

        // Intercept ALL axios imports and swap in the PHP adapter so outgoing
        // requests go through the WKURLSchemeHandler instead of real sockets.
        config.plugins = [
            {
                name: 'axios-php-wrapper',
                enforce: 'pre',
                resolveId(id) {
                    if (id === 'axios') {
                        return '\0axios-with-php-adapter';
                    }
                    return null;
                },
                load(id) {
                    if (id === '\0axios-with-php-adapter') {
                        const adapterPath = resolve(__dirname, 'phpProtocolAdapter.js');

                        return `
import axios from '${axiosPath}';
import phpAdapter from '${adapterPath}';

axios.defaults.adapter = phpAdapter;

export default axios;
export const isAxiosError = axios.isAxiosError;
export const isCancel = axios.isCancel;
export const mergeConfig = axios.mergeConfig;
`;
                    }
                    return null;
                }
            }
        ];
    }

    if (isAndroid) {
        process.env.APP_URL = 'http://127.0.0.1';

        config.server = {
            host: localIP,
            cors: {
                origin: ['http://127.0.0.1'],
            },
        };
    }

    // Extract iOS-only plugins (the axios wrapper) so we can return them
    // alongside the main plugin; they must not be part of the config the
    // main plugin's config() hook returns, or Vite double-registers them.
    const extraPlugins = config.plugins || [];
    delete config.plugins;

    const mainPlugin = {
        name: 'nativephp',
        enforce: 'pre',
        config() {
            return config;
        },
        closeBundle() {
            if (!isBuild) {
                return;
            }

            const hotFiles = ['public/ios-hot', 'public/android-hot', 'public/hot'];
            for (const hotFile of hotFiles) {
                const hotFilePath = path.resolve(process.cwd(), hotFile);
                if (existsSync(hotFilePath)) {
                    unlinkSync(hotFilePath);
                }
            }
        }
    };

    if (extraPlugins.length > 0) {
        return [mainPlugin, ...extraPlugins];
    }

    return mainPlugin;
}
