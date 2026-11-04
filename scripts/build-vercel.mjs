import { createRequire } from 'node:module';
import { readFile, mkdir, copyFile, chmod, writeFile, cp, access, mkdtemp } from 'node:fs/promises';
import { createWriteStream } from 'node:fs';
import { pipeline } from 'node:stream/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { glob } = require('@vercel/build-utils');
const php = require('vercel-php');
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
if (process.platform !== 'linux') throw new Error('Build the bundled PHP runtime on Linux (Vercel builds run on Linux).');
const output = path.join(root, '.vercel/output');
const functionDir = path.join(output, 'functions/api/index.php.func');
// Keep builder output separate from its FileFsRef inputs. Downloading files
// back into their source directory truncates same-path references (including
// composer.json) before they can be read.
const workPath = await mkdtemp(path.join(root, '.vercel/php-work-'));
const excludeFiles = '{node_modules/**,.git/**,.vercel/**,outputs/**,training/**,datasets/**,tests/**,scripts/**,.venv-training/**,**/__pycache__/**,*.zip,*.log,.env*,vercel.json,.vercelignore,now.json,.nowignore,Bacterial Leaf Blight/**,Brown Spot/**,Healthy Rice Leaf/**,Sheath Blight/**,Tungro/**,Not Rice Leaf/**,leaf_blast/**}';

// Build with the pinned PHP package, then materialize its files ourselves so
// handler metadata is not lost through its legacy Lambda constructor.
const result = await php.build({
    files: await glob('**', {
        cwd: root,
        ignore: ['.git/**', '.vercel/**', 'node_modules/**', '.venv-training/**',
            'outputs/**', 'training/**', 'datasets/**', 'tests/**', 'scripts/**', '**/__pycache__/**',
            '*.zip', '*.log', '.env*', 'Bacterial Leaf Blight/**', 'Brown Spot/**',
            'Healthy Rice Leaf/**', 'Sheath Blight/**', 'Tungro/**', 'Not Rice Leaf/**',
            'leaf_blast/**'],
    }),
    entrypoint: 'api/index.php', workPath, config: { excludeFiles }, meta: {},
});
const lambda = result.output;
if (!lambda?.files?.['launcher.js'] || !lambda.files['php/php']) {
    throw new Error('PHP runtime did not generate its expected launcher and executable');
}
await mkdir(functionDir, { recursive: true });
for (const [name, file] of Object.entries(lambda.files)) {
    const relative = name === 'launcher.js' ? 'launcher-original.js' : name;
    const destination = path.resolve(functionDir, relative);
    if (!destination.startsWith(functionDir + path.sep)) throw new Error(`Invalid bundle path: ${name}`);
    await mkdir(path.dirname(destination), { recursive: true });
    await pipeline(file.toStream(), createWriteStream(destination));
    if (file.mode) await chmod(destination, file.mode & 0o777);
}
await copyFile(path.join(root, 'scripts/vercel-launcher.cjs'), path.join(functionDir, 'launcher.js'));
await writeFile(path.join(functionDir, 'package.json'), JSON.stringify({ type: 'commonjs' }));
await writeFile(path.join(functionDir, '.vc-config.json'), JSON.stringify({
    runtime: 'nodejs22.x', handler: 'launcher.js', launcherType: 'Nodejs',
    awsLambdaHandler: 'launcher.launcher', shouldAddHelpers: false,
    shouldAddSourcemapSupport: false, environment: lambda.environment,
}, null, 2));
await access(path.join(functionDir, 'user/vendor/autoload.php'));
await access(path.join(functionDir, 'user/public/build/manifest.json'));
await cp(path.join(root, 'public'), path.join(output, 'static'), {
    recursive: true,
    filter: source => !['index.php', 'hot', 'storage'].includes(path.basename(source)) && !source.endsWith('.php'),
});
const config = JSON.parse(await readFile(path.join(root, 'vercel.json'), 'utf8'));
await writeFile(path.join(output, 'config.json'), JSON.stringify({ version: 3, routes: config.routes }, null, 2));
console.log('Built PHP 8.3 function with Node.js launcher metadata and request-body compatibility.');
