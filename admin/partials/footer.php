</div><!-- Close container -->

<div class="modal fade" id="adminConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adminConfirmTitle">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="adminConfirmBody">Are you sure you want to continue?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="adminConfirmOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/script.js?v=20260516b" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var modalEl = document.getElementById('adminConfirmModal');
    if (!modalEl || typeof bootstrap === 'undefined') {
        return;
    }
    var titleEl = document.getElementById('adminConfirmTitle');
    var bodyEl = document.getElementById('adminConfirmBody');
    var okBtn = document.getElementById('adminConfirmOk');
    var bsModal = new bootstrap.Modal(modalEl);

    window.adminConfirm = function (options) {
        options = options || {};
        titleEl.textContent = options.title || 'Please confirm';
        bodyEl.textContent = options.message || 'Are you sure you want to continue?';
        okBtn.textContent = options.okText || 'Confirm';
        return new Promise(function (resolve) {
            var done = false;
            var onHide = function () {
                if (!done) {
                    done = true;
                    resolve(false);
                }
                cleanup();
            };
            var onOk = function () {
                if (!done) {
                    done = true;
                    resolve(true);
                }
                bsModal.hide();
                cleanup();
            };
            var cleanup = function () {
                modalEl.removeEventListener('hidden.bs.modal', onHide);
                okBtn.removeEventListener('click', onOk);
            };
            modalEl.addEventListener('hidden.bs.modal', onHide);
            okBtn.addEventListener('click', onOk);
            bsModal.show();
        });
    };

    document.addEventListener('submit', function (event) {
        var form = event.target && event.target.closest ? event.target.closest('form[data-confirm-modal]') : null;
        if (!form || form.dataset.confirmed === '1') {
            return;
        }
        event.preventDefault();
        window.adminConfirm({
            title: form.getAttribute('data-confirm-title') || 'Please confirm',
            message: form.getAttribute('data-confirm-message') || 'Are you sure you want to continue?',
            okText: form.getAttribute('data-confirm-ok') || 'Confirm'
        }).then(function (ok) {
            if (!ok) {
                return;
            }
            form.dataset.confirmed = '1';
            form.submit();
            window.setTimeout(function () {
                delete form.dataset.confirmed;
            }, 0);
        });
    }, true);
})();
</script>
</body>
</html>

