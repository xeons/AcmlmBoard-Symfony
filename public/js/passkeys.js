/*
 * Passkey (WebAuthn) browser glue.
 *
 * Everything here is optional: with JavaScript disabled, or on a browser or device
 * without an authenticator, the passkey controls simply do not appear and password
 * login carries on working. Nothing on the board depends on this file.
 *
 * WebAuthn requires a secure context - HTTPS, or localhost while developing. On
 * plain HTTP over a network address, navigator.credentials is absent and the UI
 * stays hidden rather than offering something that cannot work.
 */
(function () {
    'use strict';

    var supported = window.PublicKeyCredential
        && navigator.credentials
        && typeof navigator.credentials.create === 'function';

    /* --------------------------------------------------------- encoding
     *
     * WebAuthn speaks ArrayBuffers; JSON does not. The server sends and expects
     * base64url, so both directions are converted here.
     */

    function fromBase64Url(value) {
        var padded = value.replace(/-/g, '+').replace(/_/g, '/');
        while (padded.length % 4) padded += '=';
        var binary = window.atob(padded);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes.buffer;
    }

    function toBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /** Turns the server's JSON options into the shape navigator.credentials wants. */
    function decodeOptions(options) {
        var decoded = Object.assign({}, options);
        decoded.challenge = fromBase64Url(options.challenge);

        if (options.user) {
            decoded.user = Object.assign({}, options.user, { id: fromBase64Url(options.user.id) });
        }

        ['excludeCredentials', 'allowCredentials'].forEach(function (key) {
            if (!Array.isArray(options[key])) return;
            decoded[key] = options[key].map(function (credential) {
                return Object.assign({}, credential, { id: fromBase64Url(credential.id) });
            });
        });

        return decoded;
    }

    /** Turns an authenticator's response back into JSON the server can read. */
    function encodeCredential(credential) {
        var response = credential.response;
        var encoded = {
            id: credential.id,
            type: credential.type,
            rawId: toBase64Url(credential.rawId),
            response: {
                clientDataJSON: toBase64Url(response.clientDataJSON)
            }
        };

        if (response.attestationObject) {
            encoded.response.attestationObject = toBase64Url(response.attestationObject);
            if (typeof response.getTransports === 'function') {
                encoded.response.transports = response.getTransports();
            }
        } else {
            encoded.response.authenticatorData = toBase64Url(response.authenticatorData);
            encoded.response.signature = toBase64Url(response.signature);
            encoded.response.userHandle = response.userHandle
                ? toBase64Url(response.userHandle)
                : null;
        }

        return encoded;
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return { error: 'The server returned an unreadable response.' };
            }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        });
    }

    function setStatus(element, message, isError) {
        if (!element) return;
        element.textContent = message || '';
        element.className = 'passkey-status' + (isError ? ' passkey-error' : '');
    }

    /**
     * WebAuthn rejections are often unhelpfully generic. Translate the ones with a
     * clear cause into something a member can act on.
     */
    function describeError(error) {
        if (!error) return 'Something went wrong.';
        if (error.name === 'NotAllowedError') {
            return 'Cancelled, or it timed out. Please try again.';
        }
        if (error.name === 'InvalidStateError') {
            return 'That device already has a passkey for this board.';
        }
        if (error.name === 'SecurityError') {
            return 'Passkeys need a secure connection (HTTPS, or localhost while testing).';
        }
        return error.message || String(error);
    }

    /* --------------------------------------------------- registration */

    var registerButton = document.querySelector('[data-passkey-register]');
    if (registerButton) {
        var registerBlock = document.querySelector('[data-passkey-block]');
        if (registerBlock) registerBlock.hidden = false;

        var registerStatus = document.querySelector('[data-passkey-status]');

        if (!supported) {
            // Say why, rather than leaving the member looking at a dead button. The
            // usual cause is the page being served over plain HTTP on a network
            // address, where navigator.credentials does not exist at all.
            setStatus(
                registerStatus,
                window.isSecureContext === false
                    ? 'Passkeys need a secure connection. This page is not on HTTPS or localhost.'
                    : 'This browser cannot use passkeys. Your password still works.',
                true
            );
        } else {
            registerButton.disabled = false;
            setStatus(registerStatus, '', false);

            registerButton.addEventListener('click', function () {
                var nameField = document.querySelector('[data-passkey-name]');
                var token = registerButton.getAttribute('data-csrf');

                registerButton.disabled = true;
                setStatus(registerStatus, 'Follow the prompt on your device...', false);

                postJson('/profile/passkeys/options', { _token: token })
                    .then(function (result) {
                        if (!result.ok) throw new Error(result.data.error || 'Could not start registration.');
                        return navigator.credentials.create({ publicKey: decodeOptions(result.data) });
                    })
                    .then(function (credential) {
                        return postJson('/profile/passkeys/register', {
                            _token: token,
                            name: nameField ? nameField.value : '',
                            credential: encodeCredential(credential)
                        });
                    })
                    .then(function (result) {
                        if (!result.ok) throw new Error(result.data.error || 'Registration failed.');
                        window.location.href = result.data.redirect || '/profile/passkeys';
                    })
                    .catch(function (error) {
                        setStatus(registerStatus, describeError(error), true);
                        registerButton.disabled = false;
                    });
            });
        }
    }

    /* ---------------------------------------------------------- login */

    var loginButton = document.querySelector('[data-passkey-login]');
    if (loginButton && supported) {
        var loginBlock = document.querySelector('[data-passkey-login-block]');
        if (loginBlock) loginBlock.hidden = false;

        loginButton.addEventListener('click', function () {
            var status = document.querySelector('[data-passkey-login-status]');
            var usernameField = document.getElementById('username');

            loginButton.disabled = true;
            setStatus(status, 'Follow the prompt on your device...', false);

            postJson('/login/passkey/options', {
                username: usernameField ? usernameField.value : ''
            })
                .then(function (result) {
                    if (!result.ok) throw new Error(result.data.error || 'Could not start sign-in.');
                    return navigator.credentials.get({ publicKey: decodeOptions(result.data) });
                })
                .then(function (credential) {
                    return postJson('/login/passkey/verify', {
                        credential: encodeCredential(credential)
                    });
                })
                .then(function (result) {
                    if (!result.ok) throw new Error(result.data.error || 'Sign-in failed.');
                    window.location.href = result.data.redirect || '/';
                })
                .catch(function (error) {
                    setStatus(status, describeError(error), true);
                    loginButton.disabled = false;
                });
        });
    }
})();
