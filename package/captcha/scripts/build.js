const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SRC = path.join(ROOT, 'src');
const DIST = path.join(ROOT, 'dist');

if (!fs.existsSync(DIST)) fs.mkdirSync(DIST, { recursive: true });

function read(file) { return fs.readFileSync(path.join(SRC, file), 'utf-8'); }
function write(file, content) { fs.writeFileSync(path.join(DIST, file), content, 'utf-8'); }

const slideJs = read('slide.js');
const clickJs = read('click.js');
const slideCss = read('slide.css');
const clickCss = read('click.css');

// 去除 ESM export 部分，构造 UMD
function stripExports(src) {
    return src
        .replace(/^\s*export\s*\{\s*[^}]+\s*\}\s*;?\s*$/gm, '')
        .replace(/^\s*export\s+default\s+[A-Za-z_$][A-Za-z0-9_$]*\s*;?\s*$/gm, '');
}

const slideCore = stripExports(slideJs).trim();
const clickCore = stripExports(clickJs).trim();

// ---- ESM 版本 ----
const esm = `// @aoss/captcha - ESM build
${slideCore}
${clickCore}
export { initSlideCaptacle, SlideCaptcha } from './slide.js';
export { initClickCaptcha } from './click.js';
`;
write('aoss-captcha.esm.js', esm);

// 保留独立的 slide.js 和 click.js（便于按需 import）
write('slide.js', slideJs);
write('click.js', clickJs);

// ---- UMD 版本（可直接 <script> 引入） ----
const umd = `(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.AossCaptcha = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    ${slideCore}
    ${clickCore}
    return {
        initSlideCaptacle: typeof initSlideCaptacle !== 'undefined' ? initSlideCaptacle : undefined,
        SlideCaptcha: typeof SlideCaptcha !== 'undefined' ? SlideCaptcha : undefined,
        initClickCaptcha: typeof initClickCaptcha !== 'undefined' ? initClickCaptcha : undefined
    };
}));
`;
write('aoss-captcha.umd.js', umd);

// ---- CSS 拷贝 ----
write('slide.css', slideCss);
write('click.css', clickCss);

// ---- 类型声明 ----
const dts = `export interface CaptchaOptions {
    token: string;
    ident?: string;
    apiUrl?: string;
    onSuccess?: () => void;
    onError?: (msg: string) => void;
}

export interface SlideData {
    bg: string;
    block: string;
    y: number;
    bg_width: number;
    bg_height: number;
    block_size: number;
    pad_top?: number;
    pad_left?: number;
    block_width?: number;
    block_height?: number;
}

export interface SlideResult {
    success: boolean;
    data?: SlideData;
    ident?: string;
    message?: string;
}

export declare function initSlideCaptacle(options: CaptchaOptions): void;

export declare class SlideCaptcha {
    constructor(options: { token: string; apiUrl?: string });
    generate(ident?: string): Promise<SlideResult>;
    check(ident: string, x: number): Promise<SlideResult>;
}

export declare function initClickCaptcha(options: CaptchaOptions): void;
`;
write('index.d.ts', dts);

console.log('✓ Build complete. Output in dist/');
console.log('  - aoss-captcha.esm.js');
console.log('  - aoss-captcha.umd.js');
console.log('  - slide.js / click.js (独立)');
console.log('  - slide.css / click.css');
console.log('  - index.d.ts');