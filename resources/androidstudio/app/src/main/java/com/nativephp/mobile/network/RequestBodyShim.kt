package com.nativephp.mobile.network

/**
 * The page-side half of request body capture. WebResourceRequest has no
 * body accessor, so shouldInterceptRequest can't see what a POST carries.
 * This script wraps fetch, XMLHttpRequest and <form> submits, lets the
 * browser serialize each same-origin body (so the bytes and the
 * Content-Type, multipart boundary included, are exactly what the request
 * carries), and hands the bytes to [JSBridge] in base64 chunks with
 * beginBody / appendBody / commitBody. Base64 is used only on this hop,
 * because addJavascriptInterface can only pass strings; Kotlin decodes each
 * chunk to bytes immediately.
 *
 * It is installed at document start where the WebView supports it, so the
 * page's first requests are covered too, and again from onPageFinished as a
 * fallback. It guards itself, so running twice is harmless.
 *
 * Keep it free of the dollar sign: this is a Kotlin raw string.
 */
internal object RequestBodyShim {
    const val SCRIPT = """
(function () {
    // Hands every same-origin request body to native as exact bytes, because
    // WebResourceRequest carries no body. The browser serializes the body
    // (so the bytes and the Content-Type, multipart boundary included, are
    // what a real request would carry), and the bytes cross
    // addJavascriptInterface in base64 chunks, since it only passes strings.
    // Kotlin decodes them to a ByteArray straight away.
    if (window.__nphpBodyShim) {
        return 'request body shim already installed';
    }
    var bridge = window.AndroidPOST;
    if (!bridge || typeof bridge.beginBody !== 'function') {
        return 'request body shim: no AndroidPOST bridge yet';
    }
    window.__nphpBodyShim = true;
    window.__nphpPostPatched = true;

    var CHUNK = 786432;               // bytes per bridge call, a multiple of 3
    var MAX_BODY = 16 * 1024 * 1024;  // post_max_size of the embedded PHP
    var encoder = new TextEncoder();
    var seq = 0;

    function newKey() {
        return 'nphp_' + (++seq) + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
    }

    function absolute(url) {
        try { return new URL(url, document.baseURI).href; } catch (e) { return String(url); }
    }

    function sameOrigin(url) {
        try { return new URL(url, document.baseURI).origin === location.origin; } catch (e) { return false; }
    }

    function bodyless(method) {
        return method === 'GET' || method === 'HEAD';
    }

    function toBase64(bytes) {
        var parts = [];
        for (var i = 0; i < bytes.length; i += 0x8000) {
            parts.push(String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000)));
        }
        return btoa(parts.join(''));
    }

    // Store the bytes natively under key. Returns false if they were not sent.
    function hand(key, url, contentType, buffer, isForm) {
        var bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
        if (bytes.length > MAX_BODY) {
            console.error('NativePHP: a request body of ' + bytes.length + ' bytes is over the ' +
                MAX_BODY + ' byte limit and was not sent to PHP: ' + url);
            return false;
        }
        bridge.beginBody(key, String(url), contentType || '', bytes.length, !!isForm);
        for (var i = 0; i < bytes.length; i += CHUNK) {
            bridge.appendBody(key, toBase64(bytes.subarray(i, i + CHUNK)));
        }
        bridge.commitBody(key);
        return true;
    }

    // The browser's own encoder for any body type: string, URLSearchParams,
    // FormData, Blob/File, ArrayBuffer or a typed array.
    function serialize(url, method, body, contentType) {
        var init = { method: method, body: body };
        if (contentType) {
            init.headers = { 'Content-Type': contentType };
        }
        var req = new Request(url, init);
        return req.arrayBuffer().then(function (buffer) {
            return { contentType: req.headers.get('Content-Type') || '', buffer: buffer };
        });
    }

    // fetch(url, init) and fetch(Request)
    var originalFetch = window.fetch;
    window.fetch = function (input, init) {
        var isRequest = typeof Request !== 'undefined' && input instanceof Request;
        var method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();
        var url = isRequest ? input.url : absolute(input);
        var hasBody = (init && init.body != null) || (isRequest && input.body != null && !input.bodyUsed);

        if (bodyless(method) || !hasBody || !sameOrigin(url)) {
            return originalFetch.apply(this, arguments);
        }

        var self = this;
        var req;
        try {
            req = new Request(input, init);
        } catch (e) {
            return originalFetch.apply(this, arguments);
        }

        return req.arrayBuffer().then(function (buffer) {
            var contentType = req.headers.get('Content-Type') || '';
            var key = newKey();
            var headers = new Headers(req.headers);
            if (hand(key, req.url, contentType, buffer, false)) {
                headers.set('X-NativePHP-Req-Id', key);
            }
            var next = {
                method: req.method,
                headers: headers,
                body: buffer,
                credentials: req.credentials,
                cache: req.cache,
                redirect: req.redirect,
                referrer: req.referrer,
                referrerPolicy: req.referrerPolicy,
                integrity: req.integrity,
                keepalive: req.keepalive,
                signal: req.signal
            };
            if (init && init.mode) {
                next.mode = init.mode;
            }
            return originalFetch.call(self, req.url, next);
        });
    };

    // XMLHttpRequest
    var xhrProto = XMLHttpRequest.prototype;
    var originalOpen = xhrProto.open;
    var originalSend = xhrProto.send;
    var originalSetHeader = xhrProto.setRequestHeader;

    xhrProto.open = function (method, url, async) {
        this.__nphp = {
            method: String(method).toUpperCase(),
            url: absolute(url),
            async: arguments.length < 3 || !!async,
            contentType: null
        };
        return originalOpen.apply(this, arguments);
    };

    xhrProto.setRequestHeader = function (name, value) {
        if (this.__nphp && String(name).toLowerCase() === 'content-type') {
            this.__nphp.contentType = String(value);
        }
        return originalSetHeader.apply(this, arguments);
    };

    xhrProto.send = function (body) {
        var meta = this.__nphp;
        if (!meta || body == null || bodyless(meta.method) || !sameOrigin(meta.url)) {
            return originalSend.apply(this, arguments);
        }

        var xhr = this;
        var key = newKey();
        var hint = meta.contentType;

        if (typeof Document !== 'undefined' && body instanceof Document) {
            if (!hint) {
                hint = body.contentType === 'text/html' ? 'text/html;charset=UTF-8' : 'application/xml;charset=UTF-8';
            }
            body = new XMLSerializer().serializeToString(body);
        }

        function tag(contentType) {
            originalSetHeader.call(xhr, 'X-NativePHP-Req-Id', key);
            if (!meta.contentType && contentType) {
                originalSetHeader.call(xhr, 'Content-Type', contentType);
            }
        }

        if (!meta.async) {
            // A synchronous send can't wait for a Blob or FormData to be read.
            var bytes = null;
            var contentType = hint;
            if (typeof body === 'string') {
                bytes = encoder.encode(body);
                contentType = contentType || 'text/plain;charset=UTF-8';
            } else if (body instanceof URLSearchParams) {
                bytes = encoder.encode(body.toString());
                contentType = contentType || 'application/x-www-form-urlencoded;charset=UTF-8';
            } else if (body instanceof ArrayBuffer) {
                bytes = new Uint8Array(body.slice(0));
            } else if (ArrayBuffer.isView(body)) {
                bytes = new Uint8Array(body.buffer.slice(body.byteOffset, body.byteOffset + body.byteLength));
            }
            if (!bytes) {
                console.error('NativePHP: a synchronous XMLHttpRequest can only send a string, URLSearchParams ' +
                    'or bytes to PHP. This ' + Object.prototype.toString.call(body) + ' body was not sent: ' + meta.url);
                return originalSend.apply(this, arguments);
            }
            if (hand(key, meta.url, contentType || '', bytes, false)) {
                tag(contentType);
            }
            return originalSend.call(xhr, bytes);
        }

        serialize(meta.url, meta.method, body, hint).then(function (r) {
            if (hand(key, meta.url, r.contentType, r.buffer, false)) {
                tag(r.contentType);
            }
            try { originalSend.call(xhr, r.buffer); } catch (e) { console.error('NativePHP: XMLHttpRequest send failed: ' + e); }
        }, function (e) {
            console.error('NativePHP: could not read the XMLHttpRequest body for ' + meta.url + ': ' + e);
            try { originalSend.call(xhr, body); } catch (ignored) {}
        });
    };

    // <form method="post"> submits. Runs in the bubble phase on window, so a
    // page or framework handler that calls preventDefault() wins. The form is
    // encoded the way the browser would, stored by its action URL, and
    // resubmitted; native matches the navigation by URL and uses these bytes
    // and this content type.
    function resubmit(form, submitter) {
        // form.submit() fires no submit event, so this can't loop. It also
        // ignores the submitter's form* overrides, so apply them for the call.
        var overrides = { formaction: 'action', formmethod: 'method', formenctype: 'enctype', formtarget: 'target' };
        var saved = {};
        if (submitter) {
            Object.keys(overrides).forEach(function (name) {
                if (submitter.hasAttribute(name)) {
                    var prop = overrides[name];
                    saved[prop] = form.hasAttribute(prop) ? form.getAttribute(prop) : null;
                    form.setAttribute(prop, submitter.getAttribute(name));
                }
            });
        }
        try {
            HTMLFormElement.prototype.submit.call(form);
        } finally {
            Object.keys(saved).forEach(function (prop) {
                if (saved[prop] === null) { form.removeAttribute(prop); } else { form.setAttribute(prop, saved[prop]); }
            });
        }
    }

    window.addEventListener('submit', function (e) {
        if (e.defaultPrevented) {
            return;
        }
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var submitter = e.submitter || null;
        var method = submitter && submitter.hasAttribute('formmethod') ? submitter.formMethod : form.method;
        if (String(method).toLowerCase() !== 'post') {
            return;
        }
        var action = submitter && submitter.hasAttribute('formaction') ? submitter.formAction : form.action;
        if (!sameOrigin(action)) {
            return;
        }
        var enctype = submitter && submitter.hasAttribute('formenctype') ? submitter.formEnctype : form.enctype;

        var data;
        try {
            data = new FormData(form, submitter);
        } catch (err) {
            data = new FormData(form);
            if (submitter && submitter.name) {
                data.append(submitter.name, submitter.value);
            }
        }

        e.preventDefault();

        var encoded;
        if (enctype === 'multipart/form-data') {
            encoded = serialize(action, 'POST', data, null);
        } else if (enctype === 'text/plain') {
            var text = '';
            data.forEach(function (value, name) {
                text += name + '=' + (typeof value === 'string' ? value : value.name) + '\r\n';
            });
            encoded = Promise.resolve({ contentType: 'text/plain', buffer: encoder.encode(text) });
        } else {
            var params = new URLSearchParams();
            data.forEach(function (value, name) {
                params.append(name, typeof value === 'string' ? value : value.name);
            });
            encoded = Promise.resolve({ contentType: 'application/x-www-form-urlencoded', buffer: encoder.encode(params.toString()) });
        }

        encoded.then(function (r) {
            hand(absolute(action), absolute(action), r.contentType, r.buffer, true);
            resubmit(form, submitter);
        }, function (err) {
            console.error('NativePHP: could not encode the form for ' + action + ': ' + err);
            resubmit(form, submitter);
        });
    }, false);

    console.log('NativePHP request body shim installed (readyState=' + document.readyState + ')');
    return 'request body shim installed';
})();
"""
}
