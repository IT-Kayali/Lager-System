(function () {
    'use strict';

    const form = document.getElementById('erp-price-import-confirm');
    if (!form) {
        return;
    }

    const submit = document.getElementById('erp-price-import-submit');
    const progress = document.getElementById('erp-price-import-progress');
    const token = form.querySelector('input[name="preview_token"]');
    const csrf = form.querySelector('input[name="_token"]');

    if (!submit || !progress || !token || !csrf) {
        return;
    }

    submit.disabled = false;
    let busy = false;
    let started = false;

    async function send(url, fields) {
        const data = new FormData();
        data.set('_token', csrf.value);
        data.set('preview_token', token.value);

        Object.entries(fields).forEach(([key, value]) => data.set(key, value));

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: data,
        });

        let body = {};
        try {
            body = await response.json();
        } catch (_error) {
            // Die Serverantwort war unvollständig; Status bleibt unklar.
        }

        if (!response.ok) {
            const message = body.message ||
                (response.status === 419
                    ? 'Die Sitzung ist abgelaufen. Bitte neu anmelden und die Datei erneut hochladen.'
                    : 'Serverfehler beim Preisimport. Bitte das Fehlerprotokoll prüfen.');
            const error = new Error(message);
            error.progress = body;
            throw error;
        }

        return body;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }

        busy = true;
        submit.disabled = true;
        progress.textContent = 'Import wird gestartet …';

        try {
            const checkboxes = Array.from(form.querySelectorAll('.erp-price-import-row'));
            const excluded = checkboxes
                .filter((checkbox) => !checkbox.checked)
                .map((checkbox) => checkbox.value)
                .join(',');

            const selectedCount = checkboxes.length - (excluded ? excluded.split(',').length : 0);
            if (selectedCount === 0) {
                throw new Error('Bitte mindestens eine Preiszeile auswählen.');
            }

            const begin = await send(form.dataset.beginUrl, { excluded_rows_csv: excluded });
            started = true;
            checkboxes.forEach((checkbox) => { checkbox.disabled = true; });

            let state = begin;
            while (!state.done) {
                progress.textContent = state.processed + ' von ' + state.total +
                    ' Preisen gespeichert. Bitte diese Seite geöffnet lassen.';
                state = await send(form.dataset.batchUrl, {});
            }

            progress.textContent = state.total + ' Preise erfolgreich übernommen.';
            window.location.assign(form.dataset.successUrl);
        } catch (error) {
            const detail = error.progress && Number.isFinite(error.progress.processed)
                ? ' (bisher ' + error.progress.processed + ' von ' + error.progress.total + ' gespeichert)'
                : '';
            progress.textContent = (error.message || 'Unbekannter Fehler') + detail +
                (started ? ' – „Fortsetzen“ klicken, um ab dem letzten Paket weiterzumachen.' : '');
            submit.textContent = started ? 'Import fortsetzen' : 'Erneut versuchen';
            submit.disabled = false;
        } finally {
            busy = false;
        }
    });
}());
