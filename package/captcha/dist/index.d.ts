export interface CaptchaOptions {
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
