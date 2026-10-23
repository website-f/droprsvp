import { useEffect, useMemo, useRef, useState } from 'react';
import type { HtmlLayout } from '@/components/html-content';

/**
 * Pasted HTML whose JavaScript has to run — an FAQ accordion, tabs, a
 * countdown, a page built on the Tailwind CDN.
 *
 * Anywhere else on the site pasted scripts are removed: on DropRSVP's own
 * origin they could act as whoever is viewing the page, an admin included.
 * Here they run in a sandboxed frame instead. Without allow-same-origin the
 * frame has an origin of its own, so its scripts work against their own page
 * but cannot reach the site's cookies, storage, pages or signed-in session.
 *
 * To sit in the page like any other content rather than as a box with a
 * scrollbar, the frame grows to fit what is inside it. That needs three
 * things the frame does not do by itself, all set up by a small script
 * placed ahead of the pasted page's own:
 *
 *   - height: the page reports its height and the frame follows;
 *   - "vh" units: they measure the frame, so a 100vh hero inside a frame that
 *     grows to fit would grow with it, forever. They are pinned to the real
 *     screen height instead (and growth that will not stop is cut off);
 *   - #anchors: the frame has no scroll of its own, so a link to #pricing
 *     scrolls the page around it.
 *
 * Storage and cookies are unavailable in a sandbox and a script that touches
 * them would stop dead, so they are replaced with in-memory stand-ins.
 */

export const SANDBOX_FLAGS = 'allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-top-navigation-by-user-activation';

/** Does this HTML need to run code? Structured data (JSON-LD) does not count. */
export function hasScripts(html: string): boolean {
    const markup = html.replace(/<!--[\s\S]*?-->/g, '');

    return /<script\b(?![^>]*application\/ld\+json)/i.test(markup)
        || /<[a-z][^>]*[\s/"']on[a-z]+\s*=/i.test(markup)
        || /\b(?:href|src|action)\s*=\s*["']?\s*javascript:/i.test(markup);
}

/** 100vh → calc(var(--drop-vh) * 100): the screen's height, not the frame's. */
function pinViewportUnits(css: string): string {
    return css.replace(/(-?\d*\.?\d+)(?:s|d|l)?vh\b/gi, (_match, n: string) => `calc(var(--drop-vh, 1vh) * ${n})`);
}

/** Tailwind's screen-height classes are written at run time by its CDN script, so they are pinned by name. */
const SCREEN_CLASSES = '.h-screen{height:calc(var(--drop-vh,1vh)*100)!important}'
    + '.min-h-screen{min-height:calc(var(--drop-vh,1vh)*100)!important}'
    + '.max-h-screen{max-height:calc(var(--drop-vh,1vh)*100)!important}'
    + 'html,body{overflow-y:hidden!important}';

function bootstrap(viewportHeight: number, autoHeight: boolean): string {
    return `(function(){
var root=document.documentElement;
function setVh(h){root.style.setProperty('--drop-vh',(h/100)+'px');}
setVh(${Math.round(viewportHeight)});
function memory(){var m={};return{getItem:function(k){return Object.prototype.hasOwnProperty.call(m,k)?m[k]:null;},setItem:function(k,v){m[k]=String(v);},removeItem:function(k){delete m[k];},clear:function(){m={};},key:function(i){return Object.keys(m)[i]||null;},get length(){return Object.keys(m).length;}};}
['localStorage','sessionStorage'].forEach(function(n){try{window[n].getItem('x');}catch(e){try{Object.defineProperty(window,n,{value:memory(),configurable:true});}catch(e2){}}});
try{document.cookie;}catch(e){var jar='';try{Object.defineProperty(document,'cookie',{get:function(){return jar;},set:function(v){jar=String(v).split(';')[0];},configurable:true});}catch(e2){}}
addEventListener('message',function(e){if(e.data&&e.data.dropFrame==='viewport'){setVh(e.data.height);}});
${autoHeight ? `var last=-1;
function report(){var h=Math.max(root.scrollHeight,document.body?document.body.scrollHeight:0);if(Math.abs(h-last)>1){last=h;parent.postMessage({dropFrame:'height',height:h},'*');}}
addEventListener('load',report);addEventListener('resize',report);
if(window.ResizeObserver){new ResizeObserver(report).observe(root);}
setInterval(report,1000);
document.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('a[href^="#"]'):null;if(!a){return;}var id=decodeURIComponent(a.getAttribute('href').slice(1));var el=id?document.getElementById(id):null;if(el){e.preventDefault();parent.postMessage({dropFrame:'scroll',top:el.getBoundingClientRect().top+(window.scrollY||0)},'*');}});` : ''}
})();`;
}

/** The site's own stylesheets, as absolute URLs, for partial HTML shown in the site's look. */
function siteStyles(): string {
    const links = Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]'))
        .map((link) => `<link rel="stylesheet" href="${link.href}">`)
        .join('');
    // Vite's dev server injects CSS as <style> tags rather than links.
    const inline = Array.from(document.head.querySelectorAll('style')).map((style) => style.outerHTML).join('');

    return links + inline;
}

/**
 * The document the frame loads: the pasted page (or, for partial HTML, the
 * pasted content inside the site's styling), with the helper script first.
 */
export function sandboxDocument(html: string, layout: HtmlLayout, options: { viewportHeight: number; autoHeight: boolean; linkTarget: '_top' | '_blank'; contentClass: string; container?: boolean }): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');

    if (options.autoHeight) {
        doc.querySelectorAll('style').forEach((style) => {
            style.textContent = pinViewportUnits(style.textContent ?? '');
        });
        doc.querySelectorAll<HTMLElement>('[style]').forEach((element) => {
            element.setAttribute('style', pinViewportUnits(element.getAttribute('style') ?? ''));
        });
    }

    const helpers = `<base target="${options.linkTarget}">`
        // The browser's 8px body margin showed as a gap under the site's
        // header. First in <head>, so the page's own CSS still overrides it.
        + '<style>body{margin:0}</style>'
        + (options.autoHeight ? `<style>${SCREEN_CLASSES}</style>` : '')
        + `<script>${bootstrap(options.viewportHeight, options.autoHeight)}</script>`;

    if (layout === 'content') {
        const theme = document.documentElement.classList.contains('dark') ? 'dark' : '';

        return `<!DOCTYPE html><html class="${theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${helpers}${siteStyles()}${doc.head.innerHTML}</head>`
            + '<body class="bg-background font-sans text-foreground antialiased">'
            // The public page already puts the frame in a readable column; a
            // preview frame has to supply the column itself.
            + (options.container ? '<main class="mx-auto w-full max-w-3xl px-6 py-12">' : '')
            + `<article class="${options.contentClass}">${doc.body.innerHTML}</article>`
            + (options.container ? '</main>' : '')
            + '</body></html>';
    }

    return `<!DOCTYPE html><html${attributes(doc.documentElement)}><head>${helpers}${doc.head.innerHTML}</head><body${attributes(doc.body)}>${doc.body.innerHTML}</body></html>`;
}

function attributes(element: Element): string {
    return Array.from(element.attributes).map((a) => ` ${a.name}="${a.value.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"`).join('');
}

/** The public page: a frame that grows to fit, inside the site's header and footer. */
export function SandboxedHtml({ html, layout, contentClass, className }: { html: string; layout: HtmlLayout; contentClass: string; className?: string }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [height, setHeight] = useState(0);
    const growth = useRef<number[]>([]);

    // Built once per HTML: the screen height it starts with is updated by message afterwards.
    const srcDoc = useMemo(
        () => sandboxDocument(html, layout, { viewportHeight: window.innerHeight, autoHeight: true, linkTarget: '_top', contentClass }),
        [html, layout, contentClass],
    );

    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.source !== frame.current?.contentWindow || typeof event.data !== 'object' || !event.data) {
                return;
            }

            if (event.data.dropFrame === 'height' && typeof event.data.height === 'number') {
                const next = Math.min(Math.max(Math.ceil(event.data.height), 1), 60000);

                setHeight((current) => {
                    if (next > current) {
                        // A page that keeps growing every time the frame does
                        // is chasing its own height: stop feeding it.
                        const now = Date.now();
                        growth.current = [...growth.current.filter((t) => now - t < 4000), now];

                        if (growth.current.length > 8) {
                            return current;
                        }
                    }

                    return next;
                });
            }

            if (event.data.dropFrame === 'scroll' && typeof event.data.top === 'number' && frame.current) {
                window.scrollTo({ top: frame.current.getBoundingClientRect().top + window.scrollY + event.data.top - 16, behavior: 'smooth' });
            }
        };

        const onResize = () => frame.current?.contentWindow?.postMessage({ dropFrame: 'viewport', height: window.innerHeight }, '*');

        window.addEventListener('message', onMessage);
        window.addEventListener('resize', onResize);

        return () => {
            window.removeEventListener('message', onMessage);
            window.removeEventListener('resize', onResize);
        };
    }, []);

    return (
        <iframe
            ref={frame}
            title="Page content"
            sandbox={SANDBOX_FLAGS}
            srcDoc={srcDoc}
            className={className}
            style={{ display: 'block', width: '100%', height: height || 400, border: 0 }}
        />
    );
}
