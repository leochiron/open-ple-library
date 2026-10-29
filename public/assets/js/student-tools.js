(function () {
    'use strict';
    const dialog = document.getElementById('student-contact-dialog');
    const trigger = document.getElementById('student-contact-open');
    if (!dialog || !trigger) { return; }
    const copy = document.getElementById('student-contact-copy');
    const status = document.getElementById('student-contact-status');
    trigger.addEventListener('click', function () { status.textContent = ''; dialog.showModal(); });
    dialog.addEventListener('close', function () { trigger.focus(); });
    copy.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(copy.dataset.email);
            status.textContent = 'L’adresse est copiée. Vous pouvez la coller dans votre messagerie.';
        } catch (error) {
            status.textContent = 'Sélectionnez l’adresse ci-dessus et copiez-la avec Ctrl+C ou Cmd+C.';
        }
    });
})();
