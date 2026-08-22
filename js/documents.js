(function () {
  "use strict";

  var initialized = false;

  function setBusy(button, busy, label) {
    if (window.AmberUI && typeof window.AmberUI.setButtonLoading === "function") {
      window.AmberUI.setButtonLoading(button, busy, label);
      return;
    }
    button.disabled = busy;
    button.setAttribute("aria-busy", busy ? "true" : "false");
  }

  function printDocument() {
    window.print();
  }

  function downloadPdf(button) {
    var targetId = button.getAttribute("data-document-pdf");
    var target = targetId ? document.getElementById(targetId) : document.querySelector("[data-document-sheet]");
    if (!target || typeof window.html2pdf !== "function") {
      if (window.AmberUI && window.AmberUI.toast) {
        window.AmberUI.toast({ type: "error", message: "PDF generation is unavailable. Please use Print instead." });
      }
      return;
    }
    var fileName = button.getAttribute("data-document-filename") || "amber-fabrics-document.pdf";
    var options = {
      margin: 8,
      filename: fileName,
      image: { type: "jpeg", quality: 0.96 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: "mm", format: "a4", orientation: "portrait" }
    };
    setBusy(button, true, "Preparing PDF…");
    window.html2pdf().set(options).from(target).save().catch(function () {
      if (window.AmberUI && window.AmberUI.toast) {
        window.AmberUI.toast({ type: "error", message: "The PDF could not be created. Please use Print instead." });
      }
    }).finally(function () {
      setBusy(button, false);
    });
  }

  function init() {
    if (initialized || document.body.getAttribute("data-ui-area") !== "document") {
      return;
    }
    initialized = true;
    document.addEventListener("click", function (event) {
      var printButton = event.target.closest("[data-document-print]");
      if (printButton) {
        event.preventDefault();
        printDocument();
        return;
      }
      var pdfButton = event.target.closest("[data-document-pdf]");
      if (pdfButton) {
        event.preventDefault();
        downloadPdf(pdfButton);
      }
    });
  }

  window.AmberDocuments = { init: init, print: printDocument };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
}());
