<div
    class="modal fade ui-dialog"
    id="uiConfirmDialog"
    tabindex="-1"
    aria-labelledby="uiConfirmDialogTitle"
    aria-describedby="uiConfirmDialogMessage"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="ui-dialog__heading">
                    <span class="ui-dialog__icon" id="uiConfirmDialogIcon" aria-hidden="true"></span>
                    <h2 class="modal-title h5" id="uiConfirmDialogTitle">Please confirm</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <div class="modal-body" id="uiConfirmDialogMessage">Are you sure you want to continue?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="uiConfirmDialogCancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="uiConfirmDialogOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="site-toast-region" id="siteToastRegion" aria-live="polite" aria-atomic="false"></div>
