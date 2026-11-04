import { execFileSync } from 'node:child_process';
import { mkdir, readdir, cp } from 'node:fs/promises';
import path from 'node:path';

// Bundle a relocatable Linux Python runtime with the PHP function. Vercel's
// Node/PHP runtime does not supply Python or a TensorFlow Lite interpreter.
export async function bundleScannerRuntime(root, functionDir) {
    const tools = path.join(root, '.vercel/scanner-build-tools');
    const install = path.join(root, '.vercel/scanner-python');
    await mkdir(tools, { recursive: true });
    const run = (file, args, env = process.env) => execFileSync(file, args, {
        cwd: root, env, stdio: 'inherit', timeout: 300000,
    });
    run('python3', ['-m', 'pip', 'install', '--target', tools, 'uv==0.9.7']);
    const uv = path.join(tools, 'bin/uv');
    const env = { ...process.env, UV_PYTHON_INSTALL_DIR: install, UV_PYTHON_INSTALL_BIN: '0' };
    run(uv, ['python', 'install', '3.11.14'], env);
    const names = await readdir(install);
    const name = names.find(value => value.startsWith('cpython-3.11.14-linux-x86_64-gnu'));
    if (!name) throw new Error('The Linux scanner Python runtime was not installed.');
    const source = path.join(install, name);
    run(uv, ['pip', 'install', '--break-system-packages', '--python', path.join(source, 'bin/python3.11'),
        'numpy==1.26.4', 'Pillow==11.3.0', 'ai-edge-litert==1.4.0'], env);
    const target = path.join(functionDir, 'user/.scanner-python');
    await cp(source, target, { recursive: true, dereference: true,
        filter: file => !file.includes('/__pycache__') && !file.includes('/include/') });
    // Fail the deployment build if the relocated runtime cannot load the actual
    // production models, instead of publishing an unavailable scanner.
    run(path.join(target, 'bin/python3.11'), ['-c',
        "import sys; from pathlib import Path; sys.path.insert(0, sys.argv[1]); from predict_scan import load_model; p=Path(sys.argv[2]); load_model(p/'rice_leaf_validator.tflite'); load_model(p/'mobilenetv2_rice_leaf.tflite'); load_model(p/'severity_experimental_v1.tflite'); print('Server scanner models loaded successfully')",
        path.join(functionDir, 'user/inference'), path.join(functionDir, 'user/oryzatix_app/assets/models')]);
}
